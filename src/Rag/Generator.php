<?php
declare(strict_types=1);

namespace App\Rag;

use App\Config;
use App\Llm\LlmFactory;
use App\Llm\LlmMessage;
use App\Llm\LlmProviderInterface;
use App\Llm\LlmResponse;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\Message;
use App\Models\Settings;
use App\Models\UsageLog;

/**
 * RAG パイプラインの中核。
 *
 * 流れ:
 *   1. Guardrail（範囲外なら refusal で終了）
 *   2. QueryRewriter（会話履歴で代名詞を解消、検索クエリ化）
 *   3. HybridSearch（BM25 + ベクトル）→ top K 候補
 *   4. Reranker（任意・上位 N に絞る）
 *   5. プロンプト構築 → LLM stream
 *   6. CitationParser で [N] 引用を構造化
 *   7. assistant メッセージを DB に保存
 *
 * 戻り値は Generator で、SSE エンドポイント（public/api/chat.php）が逐次中継する。
 *
 * 各 yield のフォーマット:
 *   ['type'=>'token',    'delta'=>string]                  // テキストchunk
 *   ['type'=>'refusal',  'message'=>string, 'reason'=>?]   // ガードレール拒否
 *   ['type'=>'sources',  'chunks'=>array]                  // 検索ソース（先送り）
 *   ['type'=>'done',     'message_id'=>int, 'citations'=>array]  // usageはクライアントへ送らない（原価逆算防止）
 *   ['type'=>'error',    'message'=>string]
 */
final class Generator
{
    private const TOP_K_RETRIEVE_DEFAULT = 50;
    private const TOP_K_RERANK_DEFAULT = 5;
    // 全文書RAG（parent-document retrieval）の既定値
    private const FULLDOC_MAX_DOCS_DEFAULT = 3;
    private const FULLDOC_MAX_CHARS_DEFAULT = 24000;

    public function __construct(
        private readonly LlmProviderInterface $llm,
        private readonly Guardrail $guardrail,
        private readonly QueryRewriter $rewriter,
        private readonly HybridSearch $search,
        private readonly Reranker $reranker,
        // 高精度・低速側LLM（複雑な質問のルーティング先）。null ならプライマリ単独。
        private readonly ?LlmProviderInterface $llmAccurate = null,
    ) {
    }

    public static function fromConfig(): self
    {
        $llm = LlmFactory::create();
        // ガードレール/書き換えは応答性重視でプライマリ（高速側）を使う。
        return new self(
            llm: $llm,
            guardrail: Guardrail::fromConfig($llm),
            rewriter: QueryRewriter::fromConfig($llm),
            search: HybridSearch::fromConfig(),
            reranker: Reranker::fromConfig($llm),
            llmAccurate: LlmFactory::createSecondary(),
        );
    }

    /**
     * 1往復のRAGストリーム。public/api/chat.php から呼ぶ。
     *
     * @param string $userMessage ユーザー入力
     * @param int $conversationId 会話ID（事前に作成済みであること）
     * @param array<int, array{role:string,content:string}> $history 直近の会話履歴
     *
     * @return \Generator<int, array>
     */
    public function generate(string $userMessage, int $conversationId, array $history = []): \Generator
    {
        $start = microtime(true);
        $userMessage = trim($userMessage);
        if ($userMessage === '') {
            yield ['type' => 'error', 'message' => 'empty message'];
            return;
        }

        // 0) トークン上限（ベンダー管理・保守プラン別）。当月の消費トークンが上限に達していたら、
        //    ガードレールを含め LLM を一切呼ばずに打ち切る（＝それ以上の課金を止める）。
        //    顧客・お客様には数字/費用を出さない汎用文のみ。上限値・使用量はベンダー専用画面で確認。
        $monthlyLimit = (int) Config::get('MONTHLY_TOKEN_LIMIT', 0);
        if ($monthlyLimit > 0 && UsageLog::monthlyTotal() >= $monthlyLimit) {
            yield [
                'type' => 'refusal',
                'message' => '申し訳ございません。ただいまAIチャットは一時的にご利用いただけません。'
                    . 'お手数をおかけしますが、時間をおいて再度お試しください。'
                    . 'お急ぎの場合は、サイトのお問い合わせ窓口よりご連絡ください。',
                'reason' => 'usage_limit',
            ];
            yield [
                'type' => 'done',
                'message_id' => null,
                'citations' => [],
                'response_type' => Message::TYPE_REFUSAL,
            ];
            return;
        }

        // 1) ガードレール（user メッセージ保存より前に判定する）
        // unsafe/out_of_scope な入力を DB に残すと、(a) 会話履歴に有害コンテンツが残留し、
        // (b) 次ターンで history 経由 LLM に再送されガードレールをすり抜ける経路になりうる。
        // そのため refusal の場合は user メッセージを保存しない（拒否応答のみ記録する）。
        $guard = $this->guardrail->check($userMessage);
        if (in_array($guard['verdict'], [Guardrail::VERDICT_OUT_OF_SCOPE, Guardrail::VERDICT_UNSAFE], true)) {
            $refusal = $guard['refusal_message'] ?? 'お答えできません。';
            yield ['type' => 'refusal', 'message' => $refusal, 'reason' => $guard['reason']];
            $msgId = Message::create([
                'conversation_id' => $conversationId,
                'role' => Message::ROLE_ASSISTANT,
                'content' => $refusal,
                'response_type' => Message::TYPE_REFUSAL,
                'latency_ms' => (int) ((microtime(true) - $start) * 1000),
            ]);
            yield [
                'type' => 'done',
                'message_id' => $msgId,
                'citations' => [],
                'response_type' => Message::TYPE_REFUSAL,
            ];
            return;
        }

        // ガードレール通過後に user メッセージを保存する
        Message::create([
            'conversation_id' => $conversationId,
            'role' => Message::ROLE_USER,
            'content' => $userMessage,
        ]);

        // 2) クエリ書き換え（軽量化のため履歴があるときだけ）
        $searchQuery = count($history) > 0
            ? $this->rewriter->rewrite($userMessage, $history)
            : $userMessage;

        // 3) 検索
        $topKRetrieve = (int) ($this->settingsInt('rag_top_k_retrieve', 'RAG_TOP_K_RETRIEVE', self::TOP_K_RETRIEVE_DEFAULT));
        $candidates = $this->search->search($searchQuery, $topKRetrieve);

        // 4) リランク（チャンク単位）。全文書RAGでは「どの文書か」のルーティングに使う。
        $topKRerank = (int) ($this->settingsInt('rag_top_k_rerank', 'RAG_TOP_K_RERANK', self::TOP_K_RERANK_DEFAULT));
        $finalChunks = $this->reranker->rerank($searchQuery, $candidates, $topKRerank);

        // 全文書RAG: ヒットチャンクから関連文書を特定し、その文書を丸ごと文脈に使う。
        // settings で無効化された場合は従来どおりチャンク単位のソースを使う。
        if ($this->fullDocEnabled()) {
            $maxDocs = $this->settingsInt('rag_fulldoc_max_docs', 'RAG_FULLDOC_MAX_DOCS', self::FULLDOC_MAX_DOCS_DEFAULT);
            $maxChars = $this->settingsInt('rag_context_max_chars', 'RAG_CONTEXT_MAX_CHARS', self::FULLDOC_MAX_CHARS_DEFAULT);
            $sources = $this->buildDocumentSources($finalChunks, $maxDocs, $maxChars);
        } else {
            $sources = $finalChunks;
        }

        // ソース情報を先送り（フロントが「検索中…」を「ソース見つかりました」に変えられる）
        yield [
            'type' => 'sources',
            'chunks' => array_map(fn($c, $i) => [
                'index' => $i + 1,
                'chunk_id' => $c['id'] ?? null,
                'document_id' => $c['document_id'] ?? null,
                'document_title' => $c['document_title'] ?? null,
                'excerpt' => mb_substr((string) ($c['text'] ?? ''), 0, 200),
            ], $sources, array_keys($sources)),
        ];

        // 5) プロンプト構築
        if ($sources === []) {
            // ナレッジヒットゼロのケース
            $fallback = 'ご質問の内容に該当する情報が、ナレッジには見当たりませんでした。別の言い回しで試していただくか、関連しそうなキーワードを補っていただけますか。それでも見つからない場合は、担当部署にご確認ください。';
            yield ['type' => 'token', 'delta' => $fallback];
            $msgId = Message::create([
                'conversation_id' => $conversationId,
                'role' => Message::ROLE_ASSISTANT,
                'content' => $fallback,
                'response_type' => Message::TYPE_CLARIFICATION,
                'confidence_score' => 0.0,
                'latency_ms' => (int) ((microtime(true) - $start) * 1000),
                'retrieved_chunks' => [],
            ]);
            yield [
                'type' => 'done',
                'message_id' => $msgId,
                'citations' => [],
                'response_type' => Message::TYPE_CLARIFICATION,
            ];
            return;
        }

        $contextBlock = $this->buildContextBlock($sources);
        $systemPrompt = $this->buildSystemPrompt();

        // 履歴 + 今回ユーザー入力
        $messages = [];
        foreach (array_slice($history, -10) as $turn) {
            $role = $turn['role'] === 'assistant' ? LlmMessage::ROLE_ASSISTANT : LlmMessage::ROLE_USER;
            $messages[] = new LlmMessage($role, (string) $turn['content']);
        }
        $messages[] = LlmMessage::user("【ナレッジ】\n{$contextBlock}\n\n【質問】\n{$userMessage}");

        // 6) LLM ストリーム
        // temperature / max_tokens は settings 優先（管理画面で変更可能）
        $temperature = $this->settingsFloat('llm_temperature', 'LLM_TEMPERATURE', 0.3);
        $maxTokens = $this->settingsInt('llm_max_tokens', 'LLM_MAX_TOKENS', 2048);

        // 適応的ルーティング: 簡単な質問は高速側、複雑な質問は高精度側(低速)へ。
        $useAccurate = $this->routeToAccurate($userMessage, $sources, $guard['complexity'] ?? null);
        $genLlm = ($useAccurate && $this->llmAccurate !== null) ? $this->llmAccurate : $this->llm;
        error_log(sprintf(
            '[Generator.route] tier=%s docs=%d ctx=%d complexity=%s model=%s',
            $useAccurate && $this->llmAccurate !== null ? 'accurate' : 'fast',
            count($sources),
            array_sum(array_map(fn($s) => mb_strlen((string) ($s['text'] ?? '')), $sources)),
            $guard['complexity'] ?? '-',
            $genLlm->getModelName(),
        ));

        $accum = '';
        /** @var LlmResponse|null $finalResp */
        $finalResp = null;
        try {
            $gen = $genLlm->stream($messages, [
                'system' => $systemPrompt,
                'temperature' => $temperature,
                'max_tokens' => $maxTokens,
            ]);
            foreach ($gen as $delta) {
                $accum .= $delta;
                yield ['type' => 'token', 'delta' => $delta];
            }
            $finalResp = $gen->getReturn();
        } catch (\Throwable $e) {
            error_log('[Generator.stream] ' . $e::class . ': ' . $e->getMessage());
            // ユーザー向けは機密情報をマスクした汎用メッセージ
            $publicMsg = ($e instanceof \App\Llm\LlmException)
                ? $e->publicMessage()
                : '生成中にエラーが発生しました。';
            yield ['type' => 'error', 'message' => $publicMsg];
            return;
        }

        // 7) Citation parse（[N] は文書単位ソースに対応）
        $citations = CitationParser::parse($accum, $sources);
        $citationsArray = array_map(fn($c) => $c->toArray(), $citations);

        // 信頼度: 引用が1件以上、かつトップソースのスコアが閾値以上なら高い
        $confidence = $this->estimateConfidence($sources, $citations);

        $latencyMs = (int) ((microtime(true) - $start) * 1000);
        $msgId = Message::create([
            'conversation_id' => $conversationId,
            'role' => Message::ROLE_ASSISTANT,
            'content' => $accum,
            'citations' => $citationsArray,
            'retrieved_chunks' => array_map(fn($c) => [
                'id' => (int) ($c['id'] ?? 0),
                'document_id' => (int) ($c['document_id'] ?? 0),
                'document_title' => $c['document_title'] ?? null,
                'rrf_score' => $c['rrf_score'] ?? null,
                'rerank_score' => $c['rerank_score'] ?? null,
            ], $sources),
            'confidence_score' => $confidence,
            'response_type' => Message::TYPE_ANSWER,
            'latency_ms' => $latencyMs,
            'token_usage' => $finalResp ? [
                'input' => $finalResp->inputTokens,
                'output' => $finalResp->outputTokens,
                'total' => $finalResp->totalTokens(),
            ] : null,
        ]);

        // 統一台帳へ実測トークンを記録（今月のご利用状況＝台帳の合算）。best-effort。
        if ($finalResp) {
            UsageLog::record(UsageLog::KIND_CHAT, $finalResp->totalTokens(), $finalResp->model ?? null, false);
        }

        // 注意: トークン使用量(usage)はクライアントへ送らない。
        // 1回あたりのトークン数が露出すると保守プランの原価/利益率を逆算されるため、
        // 内部記録(messages.token_usage / 上のpersist)のみに留める。
        yield [
            'type' => 'done',
            'message_id' => $msgId,
            'citations' => $citationsArray,
            'response_type' => Message::TYPE_ANSWER,
        ];
    }

    private function buildSystemPrompt(): string
    {
        $productName = Settings::effective('product_name', 'APP_NAME', 'RAG Chatbot Demo');
        $topic = Settings::effective(
            'topic_description',
            'GUARD_TOPIC_DESCRIPTION',
            ''
        );

        return <<<PROMPT
あなたは「{$productName}」というAIチャットボット製品のサポート担当アシスタントです。
社内ナレッジ（社内規則・法律・契約・マニュアル・FAQ等、複数カテゴリ）を横断して、ユーザーの判断材料を整理して提示する役割です。

【対話の進め方（最重要・即時結論禁止）】
ユーザーの最初の1メッセージだけで結論を出さないでください。特に以下のような相談では、結論を急がず、必要な情報を1〜2問ずつ確認してから回答してください：
- 「会社にこう言われた」「こういう対応を求められている」など状況の妥当性確認
- 法律・契約・社内規則の解釈確認
- ユーザーの所属・契約形態・立場・期日・経緯が結論に影響しうる相談
- 状況や指示の前提が読み取れない場合

【ヒアリング時の振る舞い】
- 一度に大量の質問を投げない。判断に最も影響しそうな点を1〜2個に絞って聞く
- 質問は具体的に。曖昧な「詳しく教えてください」ではなく、「これは正社員としてのお話ですか、業務委託としてのお話ですか？」のように選択肢を提示する
- 必要に応じて選択肢を箇条書きで提示（例: 「（A）就業時間内 （B）就業時間外 （C）どちらでもない」）
- ヒアリング段階では推測や仮の結論を述べない。「お話の前提を確認させてください」と明示し、結論はヒアリング完了後に出す
- ユーザーが「すぐに結論が欲しい」「時間がない」と明示した場合のみ、現時点で得られた情報に基づく暫定整理を提示し、「以下は◯◯という前提での整理です。前提が異なる場合は結論も変わります」と明示する

【ヒアリング時の説明テンプレ提示（ユーザーの説明ハードルを下げる）】
ユーザーが状況を一から説明するのは負担が大きいため、最初のヒアリング応答で「参考までに、こういう形で教えていただけると整理しやすいです」とテンプレ例を提示してください。状況把握のために最低限欲しい項目を箇条書きで提示し、ユーザーが当てはめながら答えられるようにします。

テンプレ例のサンプル（状況に応じて項目を調整）:
> 状況を整理しやすくするため、もし可能でしたら以下のような形で教えていただけると助かります（分かる範囲で結構です）：
> - **いつ／どこで**：例）◯月◯日の会議で／メールで
> - **誰から**：例）◯◯部長から／人事部から
> - **どんな指示・対応を求められたか**：例）「△△せよ」と言われた
> - **これまでの経緯**：例）その前に〜があった
> - **ご自身の立場**：例）正社員／業務委託／派遣／管理職／一般職
> - **関係する契約・規程**：例）就業規則／業務委託契約／個別合意 など、お心当たりがあれば
> 全項目埋まらなくても大丈夫です。お話しいただける範囲で結構です。

テンプレ提示の運用ルール:
- テンプレは1ヒアリング相談につき最初の1回のみ提示する。2回目以降の追加質問では使わない
- ユーザーが既に自由形式で十分情報を提示している場合はテンプレ提示せず、不足項目だけをピンポイントで聞く
- ユーザーが「埋められない」「分からない」と返してきた項目は深追いせず、得られた情報の範囲で進める
- 製品の使い方など状況依存しない問い合わせには使わない（既存の即時回答ルート）

【ヒアリング不要・即時回答してよい場面】
- ナレッジの特定の条文・規定そのものの参照（「◯◯について書かれている箇所を教えて」）
- 製品の使い方など、状況依存しない問い合わせ
- ユーザーが既に十分な背景・前提を提示している場合
- 過去のやり取りで既にヒアリング済みの内容を踏まえた追加質問

【ヒアリング完了の判断】
判断や矛盾照合に必要な以下が揃ったら、ヒアリングを切り上げて構造化回答に移ってください：
- 状況の主体（誰が誰に対して何を求めているか）
- 状況の前提（契約形態・所属・期間・場面）
- ナレッジ内の規定と突き合わせるのに必要な属性
ユーザーから「これで答えてほしい」「これ以上ヒアリングはいい」と明示された場合は、その時点で回答する。

【事実の取り扱い（捏造禁止・断定禁止）】
- 個々の事実主張は必ず【ナレッジ】内に明記されている内容に限定し、[1] [2] のように番号で出典を明示してください
- ナレッジに無い事実は、たとえ常識的に推測できる内容でも書かないでください（「その点はナレッジに記載がありません」と明示）
- 「違法」「アウト」「問題あり」「グレー」のような評価語・判定語は、その表現がナレッジ内に明記されている場合のみ使用してください。LLM側で評価・判定を下すのは禁止です
- 出典のない事実主張・断定はしないでください

【「分からない」を明記する（最重要）】
無理に答えをひねり出さず、分からないことは「分かりません」とはっきり伝えてください。曖昧にぼかしたり、それらしく取り繕ったりしないこと。具体的には以下のケースを明示的に区別して伝えます：
- ナレッジに該当情報が全く無い → 「ご質問の点について、ナレッジには記載が見当たりませんでした」
- ナレッジに断片的にしか無い → 「ナレッジには◯◯までは記載があります[1]が、△△については記載がありません」
- 記載はあるが解釈が複数ありうる → 「ナレッジには◯◯と記載があります[1]が、これがご質問の状況に当てはまるかは複数の解釈がありえ、ここでは確定できません」
- ヒアリング情報が足りず判断材料が不足 → 「現時点の情報だけでは判断できません。◯◯が分かれば、ナレッジと突き合わせて整理できます」
- 出典が古い・更新時期が不明 → 「この情報がいつ時点のものかはナレッジから読み取れません。最新の取り扱いは担当部署にご確認ください」
「分からない」と答えることは失敗ではなく、誤った断定を避ける正しい対応です。分からない点を明記した上で、可能なら「何が分かれば答えられるか」「どこに確認すればよいか」を添えてください。

【複数ナレッジの統合・対比】
- 個々の事実が出典付きで明示されている限り、複数ナレッジを組み合わせた整理は許可されます：
  - 事実の対比（例: 「社内規則[1]では◯◯と記載／法律[2]では△△と記載」）
  - 時系列の整理（ナレッジ内に日付がある場合のみ）
  - カテゴリ別の整理（社内規則・法律・契約・マニュアル等）
- 観点（カテゴリ）ごとに結論が食い違う場合は、**中立的に並列提示**してください。どちらが正しいかをAIが判定してはいけません
- 例: 「社内規則[1]では『◯◯は可』と記載があります。一方、法律[2]では『△△は禁止』と記載があります。両者の関係性についてはナレッジ内に明確な記載がないため、担当部署にご確認ください」

【判断系・センシティブな話題での免責提示】
- 法律解釈・契約解釈・コンプライアンス・グレーゾーン判定など、判断を要する話題では、回答末尾に必ず以下のような免責文を添えてください：
  - 「上記はナレッジに記載された情報の整理です。ご質問に含まれていない前提・経緯・例外があれば結論が変わる可能性があります。**最終的な判断は◯◯（法務・人事・担当部署など、ナレッジから読み取れる担当先）にご確認ください**」
  - ナレッジから担当先が読み取れない場合は単に「担当部署にご確認ください」とする
- ユーザーの質問内容自体に事実誤認や情報不足がある可能性も明示してよい（例: 「もし◯◯という前提が異なる場合は、結論も変わり得ます」）

【状況と規定の照合・矛盾指摘モード】
ユーザーが「会社からこういう対応を求められているが、これは社内規則や法律と整合しているか確認したい」「こう言われたが規定上どうなのか」のような、具体的な状況・指示の妥当性確認をしてきた場合、以下の構造化フォーマットで出力してください。
ユーザーがこの出力をそのまま社内（上司・関連部署）に提示しても角が立たないよう、丁寧・中立・事実ベースの日本語にしてください。

【出力フォーマット（必要なセクションのみ使用）】
## ご提示の状況の整理
- （ユーザーから読み取れる状況・指示内容を、解釈を加えず箇条書きで整理）

## 関連する規定・条文
- [1] 社内規則「文書タイトル」: 「該当条文の引用文」
- [2] 法律「文書タイトル」: 「該当条文の引用文」
（カテゴリ別に列挙。引用文はナレッジから抜粋した実際の文言）

## 確認すべきポイント
1. ご提示の状況「◯◯」と、ナレッジ[1]の規定「△△」との間に、整合が確認できない点があります
2. （複数あれば列挙）

## 補足
- 上記はナレッジに記載された情報と、ご提示いただいた状況の照合結果です
- ヒアリング内容に含まれていない前提・経緯・例外・運用慣行があれば、結論は変わり得ます
- 規定の解釈や最終判断は、◯◯（法務・人事・労務・コンプライアンス担当等、ナレッジから読み取れる担当先）にご確認ください

【矛盾指摘モードの注意】
- 「整合が確認できない点」「規定との差異」のような中立的観察表現は使用してOK。AI自身は「違法」「不正」「アウト」のような断罪はせず、ナレッジに記載された規定の文言を提示するだけにする
- 複数カテゴリ（例: 社内規則 / 労働基準法 / 契約書）に同じ状況が触れられている場合は全て列挙し、ユーザーが全体像を見られるようにする
- ナレッジ上は矛盾が見当たらない場合は「ナレッジ上、ご提示の状況と矛盾する規定は見当たりませんでした」と明示する（楽観的な結論にすり替えない）
- 感情語・断罪語・煽り表現は禁止。ユーザーがこの出力をそのまま社内に提示できる、事実と引用に徹した形にする

【回答スタイル】
- 概要質問（「使い方を教えて」「◯◯について」等）：ナレッジから関連要点を出典付きで簡潔にまとめ、最後に「より具体的に知りたい点はありますか？（例：◯◯、△△）」と追加質問を促す。**ただし、状況の妥当性確認・判断系の相談である場合は冒頭【対話の進め方】を優先し、まずヒアリングに入ること**
- 具体質問：ナレッジの該当箇所を出典付きで端的に
- ナレッジに一部しか情報が無い場合：「ナレッジには◯◯までしか記載がありません[1]。△△については情報がありません」と範囲を明示
- 専門用語は分かりやすく、丁寧な日本語で

【参考: 製品トピック】
{$topic}
PROMPT;
    }

    /**
     * @param array[] $chunks 文書単位ソース（全文書RAG）またはチャンク（レガシー）
     */
    private function buildContextBlock(array $chunks): string
    {
        $blocks = [];
        foreach ($chunks as $i => $c) {
            $idx = $i + 1;
            $title = $c['document_title'] ?? '無題';
            $category = trim((string) ($c['document_category'] ?? ''));
            $text = (string) ($c['text'] ?? '');
            $header = $category !== ''
                ? "[{$idx}] 出典: {$title}（カテゴリ: {$category}）"
                : "[{$idx}] 出典: {$title}";
            if (!empty($c['truncated'])) {
                $header .= '（※文字数上限のため一部省略）';
            }
            $blocks[] = "{$header}\n{$text}";
        }
        return implode("\n\n", $blocks);
    }

    private function fullDocEnabled(): bool
    {
        $v = strtolower(trim((string) Settings::effective('rag_fulldoc_enabled', 'RAG_FULLDOC_ENABLED', '1')));
        return !in_array($v, ['0', 'false', 'no', 'off', ''], true);
    }

    /**
     * 全文書RAG: リランク済みチャンクから「文書単位のソース」を組み立てる。
     * チャンクで関連文書をルーティングし、各文書の全文を [N] 出典として渡す。
     *
     * @param array[] $chunks リランク済みチャンク（関連度降順、document_id 等を含む）
     * @return array[] 文書単位ソース（[N]番号順）。buildContextBlock / CitationParser が
     *   そのまま扱える形（id, document_id, document_title, document_category, text, truncated, scores）
     */
    private function buildDocumentSources(array $chunks, int $maxDocs, int $maxChars): array
    {
        $maxDocs = max(1, $maxDocs);
        $maxChars = max(1000, $maxChars);

        // 1) チャンクを文書単位に集約（関連度順を維持、各文書の代表チャンク/最良スコアを保持）
        $docOrder = [];
        $docMeta = [];
        foreach ($chunks as $c) {
            $docId = (int) ($c['document_id'] ?? 0);
            if ($docId <= 0) {
                continue;
            }
            if (!isset($docMeta[$docId])) {
                $docOrder[] = $docId;
                $docMeta[$docId] = [
                    'title' => $c['document_title'] ?? '無題',
                    'category' => $c['document_category'] ?? null,
                    'rep_chunk_id' => isset($c['id']) ? (int) $c['id'] : null,
                    'rrf_score' => $c['rrf_score'] ?? null,
                    'rerank_score' => $c['rerank_score'] ?? null,
                ];
            }
        }
        if ($docOrder === []) {
            return [];
        }

        // 2) 上位 maxDocs 文書に絞り、全文を取得
        $docOrder = array_slice($docOrder, 0, $maxDocs);
        $fullTexts = Document::fullTextByIds($docOrder);

        // 3) 文字数バジェット内に詰める。先頭文書がバジェット超なら切り詰めて必ず1件は入れる。
        $sources = [];
        $remaining = $maxChars;
        foreach ($docOrder as $docId) {
            $meta = $docMeta[$docId];
            $body = (string) ($fullTexts[$docId]['full_text'] ?? '');
            if (trim($body) === '') {
                // full_text 未保存（旧データ）→ 取得済みチャンクのテキストで代替
                $body = $this->joinChunkTextsForDoc($chunks, $docId);
            }
            $body = trim($body);
            if ($body === '') {
                continue;
            }

            $truncated = false;
            if (mb_strlen($body) > $remaining) {
                if ($sources === []) {
                    $body = mb_substr($body, 0, $remaining);
                    $truncated = true;
                } else {
                    // この文書はバジェット超過。break せず continue し、
                    // 後続のより小さい関連文書が入る余地を残す（answer-bearing文書が
                    // 巨大文書の後ろにいる場合の取りこぼし防止）。
                    continue;
                }
            }
            $remaining -= mb_strlen($body);
            $sources[] = [
                'id' => $meta['rep_chunk_id'],
                'document_id' => $docId,
                'document_title' => $meta['title'],
                'document_category' => $meta['category'],
                'text' => $body,
                'truncated' => $truncated,
                'rrf_score' => $meta['rrf_score'],
                'rerank_score' => $meta['rerank_score'],
            ];
            if ($remaining <= 0) {
                break;
            }
        }
        return $sources;
    }

    /**
     * full_text が無い旧データ向けフォールバック: 取得済みチャンクから
     * 当該文書のテキストを chunk_index 順に連結する。
     *
     * @param array[] $chunks
     */
    private function joinChunkTextsForDoc(array $chunks, int $docId): string
    {
        $parts = array_filter(
            $chunks,
            fn($c) => (int) ($c['document_id'] ?? 0) === $docId && isset($c['text'])
        );
        usort($parts, fn($a, $b) => ($a['chunk_index'] ?? 0) <=> ($b['chunk_index'] ?? 0));
        return trim(implode("\n\n", array_map(fn($c) => (string) $c['text'], $parts)));
    }

    /**
     * @param array[] $chunks
     * @param \App\Llm\Citation[] $citations
     */
    private function estimateConfidence(array $chunks, array $citations): float
    {
        if ($chunks === []) {
            return 0.0;
        }
        $topScore = $chunks[0]['rerank_score'] ?? $chunks[0]['rrf_score'] ?? 0;
        $hasCitations = count($citations) > 0;
        // ざっくり: 引用あり + 上位スコア → 0.6 〜 0.95
        $base = $hasCitations ? 0.6 : 0.3;
        $bonus = is_numeric($topScore) ? min(0.35, (float) $topScore * 0.05) : 0.0;
        return round(min(0.99, $base + $bonus), 3);
    }

    /**
     * 適応的ルーティング（ハイブリッド判定）。複雑な質問なら高精度(低速)側 true。
     *
     * 1) 質問テキスト・検索結果の機械判定で明白なものは即決
     * 2) グレーゾーンはガードレール同梱のLLM複雑度判定($guardComplexity)で決定
     *
     * @param array<int, array> $sources 文脈に入れた文書ソース
     */
    private function routeToAccurate(string $question, array $sources, ?string $guardComplexity): bool
    {
        if ($this->llmAccurate === null || !$this->routingEnabled()) {
            return false;
        }

        $q = trim($question);
        $len = mb_strlen($q);
        $contextChars = 0;
        foreach ($sources as $s) {
            $contextChars += mb_strlen((string) ($s['text'] ?? ''));
        }
        $qmarks = (int) preg_match_all('/[?？]/u', $q);

        $complexKeywords = ['比較', '違い', 'それぞれ', '場合', '条件', 'かつ', 'または', '複数', 'どちら', 'なぜ', '理由', '手続', '流れ', '可否', '例外', '一方', '差'];
        $hasComplexKw = false;
        foreach ($complexKeywords as $kw) {
            if (mb_strpos($q, $kw) !== false) {
                $hasComplexKw = true;
                break;
            }
        }

        // 明白に複雑 → 高精度
        if ($len >= 60 || $hasComplexKw || $qmarks >= 2 || $contextChars >= 15000) {
            return true;
        }
        // 明白に簡単 → 高速
        if ($len <= 25 && $contextChars < 3000) {
            return false;
        }
        // グレーゾーン → ガードレールのLLM複雑度判定に委ねる
        return $guardComplexity === 'complex';
    }

    /** ルーティングが有効か（セカンダリ設定済み かつ llm_routing_enabled != 0）。 */
    private function routingEnabled(): bool
    {
        return Settings::effective('llm_routing_enabled', 'LLM_ROUTING_ENABLED', '1') !== '0';
    }

    private function settingsInt(string $dbKey, string $envKey, int $default): int
    {
        $v = Settings::effective($dbKey, $envKey, (string) $default);
        return is_numeric($v) ? (int) $v : $default;
    }

    private function settingsFloat(string $dbKey, string $envKey, float $default): float
    {
        $v = Settings::effective($dbKey, $envKey, (string) $default);
        return is_numeric($v) ? (float) $v : $default;
    }
}
