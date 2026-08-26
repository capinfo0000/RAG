<?php
declare(strict_types=1);

namespace App\Knowledge;

use App\Llm\LlmFactory;
use App\Llm\LlmMessage;
use App\Llm\LlmProviderInterface;
use App\Models\Feedback;
use App\Models\Message;

/**
 * フィードバック・質問傾向を LLM で分析し、ナレッジ改善案を生成する。
 *
 * 管理画面のボタンから都度実行される（public/admin/insights.php）。
 * 直近 N 日のユーザー質問（解決状況付き）とフィードバックコメントを集計し、
 * トピック別にクラスタリング → 改善案（例: 派遣法のナレッジ追加を推奨）を返す。
 *
 * 推論ではなく集計に基づく提案を返す（件数・未解決数を根拠として明示）。
 */
final class InsightAnalyzer
{
    public function __construct(
        private readonly LlmProviderInterface $llm,
        private readonly int $maxQuestions = 200,   // LLMに渡す質問数の上限（トークン超過対策）
        private readonly int $maxQuestionChars = 200, // 各質問の最大文字数
    ) {
    }

    public static function fromConfig(?LlmProviderInterface $llm = null): self
    {
        return new self($llm ?? LlmFactory::create());
    }

    /**
     * 直近 $days 日のデータを集計して返す。LLMには渡さず、件数把握と画面表示にも使う。
     *
     * @return array{
     *   questions: array<int, array{id:int, content:string, resolved:?string}>,
     *   feedback: array<int, array{rating:string, comment:string}>,
     *   total_questions: int
     * }
     */
    public function collectDataset(int $days = 30): array
    {
        $rows = Message::recentUserQuestionsWithResolution($days, $this->maxQuestions);
        $questions = [];
        foreach ($rows as $r) {
            $questions[] = [
                'id' => $r['id'],
                'content' => mb_substr($r['content'], 0, $this->maxQuestionChars),
                'resolved' => $r['resolved'], // '1' | '0' | null
            ];
        }

        $feedback = [];
        foreach (Feedback::recentWithComments(200, $days) as $f) {
            $feedback[] = [
                'rating' => (string) ($f['rating'] ?? ''),
                'comment' => (string) ($f['comment'] ?? ''),
            ];
        }

        return [
            'questions' => $questions,
            'feedback' => $feedback,
            'total_questions' => Message::countUserQuestions($days),
        ];
    }

    /**
     * 分析を実行し、改善トピック配列＋トークン使用量を返す。
     *
     * @param array{questions:array, feedback:array, total_questions:int} $dataset
     * @return array{
     *   topics: array<int, array{label:string, representative_question:string, question_count:int,
     *           unresolved_count:int, sample_message_ids:int[], suggested_action:string, priority:string}>,
     *   summary: string,
     *   usage: ?array{input:int, output:int}
     * }
     * @throws \App\Llm\LlmException|\RuntimeException
     */
    public function analyze(array $dataset): array
    {
        $questions = $dataset['questions'] ?? [];
        $feedback = $dataset['feedback'] ?? [];
        if ($questions === []) {
            return ['topics' => [], 'summary' => '', 'usage' => null];
        }

        $system = $this->buildSystemPrompt();
        $userPayload = $this->buildUserPayload($questions, $feedback);

        $resp = $this->llm->generate(
            messages: [LlmMessage::user($userPayload)],
            options: [
                'system' => $system,
                'temperature' => 0.3,
                'max_tokens' => 8192,
                'thinking_budget' => 512,
                'response_json' => true,
            ],
        );

        $parsed = $this->parseResponse($resp->content);

        return [
            'topics' => $parsed['topics'],
            'summary' => $parsed['summary'],
            'usage' => [
                'input' => $resp->inputTokens,
                'output' => $resp->outputTokens,
            ],
        ];
    }

    private function buildSystemPrompt(): string
    {
        return <<<PROMPT
あなたはRAGチャットボットの運用改善アナリストです。
ユーザーが実際に投げた質問と、その解決状況・フィードバックを渡します。
これらを分析し、ナレッジ（社内文書・FAQ）をどう改善すべきかの提案をまとめてください。

【分析の方針】
- 似た意図の質問をトピックにまとめる（例:「派遣の3年ルール」「派遣の抵触日」→「派遣法」）
- 各トピックについて、質問件数と未解決（resolved=0）の件数を数える
- 未解決が多い／件数が多いトピックほど優先度を上げる
- 改善案（suggested_action）は、そのまま資料作成の「指示書」として使えるよう具体的に書く。抽象的な
  「ガイドを追加・拡充する」「構成を改善する」等はNG。次の要素を必ず含める:
    (1) 作成／改訂する資料の想定タイトル
    (2) その資料に載せるべき項目（見出し）を箇条書きで3〜6個。実際のユーザー質問から具体化する
    (3) その資料が答えるべき具体的なユーザー質問を2〜4個、原文に近い形で列挙
    (4) 新規作成か、既存資料の改訂か（判断できる範囲で）
  例:「資料『初期設定ガイド』を新規作成。載せる項目: (1)管理者アカウント作成 (2)資料のアップロード方法
      (3)埋め込みタグの設置 (4)よくあるつまずきと対処。答えるべき質問:『初期設定はどうやる?』
      『管理画面にログインできない』。」
- ただし手順の中身などの「事実」は創作しない（項目名・観点はユーザー質問から具体化してよいが、
  実際の操作手順や数値などの内容は書かず、資料作成者が埋める前提で"何を書くべきか"だけ示す）。
- 件数・未解決数は渡されたデータから数える
- sample_message_ids には、そのトピックの根拠となった質問の id を最大5件入れる

【出力形式】
必ず次のJSONのみを出力（前置き・コードブロック禁止）:
{
  "summary": "全体の傾向を2〜3文で",
  "topics": [
    {
      "label": "トピック名（例: 派遣法）",
      "representative_question": "代表的な質問文",
      "question_count": 整数,
      "unresolved_count": 整数,
      "sample_message_ids": [整数, ...],
      "suggested_action": "具体的な改善案",
      "priority": "high" | "medium" | "low"
    }
  ]
}
トピックは件数の多い順・優先度の高い順に最大15件。該当が無ければ topics は空配列。
PROMPT;
    }

    /**
     * @param array<int, array{id:int, content:string, resolved:?string}> $questions
     * @param array<int, array{rating:string, comment:string}> $feedback
     */
    private function buildUserPayload(array $questions, array $feedback): string
    {
        $lines = [];
        $lines[] = '【ユーザー質問一覧（id / 解決状況 / 質問）】';
        foreach ($questions as $q) {
            $status = match ($q['resolved']) {
                '1' => '解決済み',
                '0' => '未解決',
                default => '未確認',
            };
            $content = str_replace(["\r", "\n"], ' ', $q['content']);
            $lines[] = "- id={$q['id']} [{$status}] {$content}";
        }

        if ($feedback !== []) {
            $lines[] = '';
            $lines[] = '【フィードバックコメント（評価 / 内容）】';
            foreach ($feedback as $f) {
                $rating = match ($f['rating']) {
                    'up' => '👍',
                    'down' => '👎',
                    'resolved' => '解決',
                    'unresolved' => '未解決',
                    default => $f['rating'],
                };
                $comment = str_replace(["\r", "\n"], ' ', $f['comment']);
                $lines[] = "- [{$rating}] {$comment}";
            }
        }

        $lines[] = '';
        $lines[] = '上記を分析し、指定のJSON形式で改善提案を出力してください。';
        return implode("\n", $lines);
    }

    /**
     * @return array{topics:array, summary:string}
     */
    private function parseResponse(string $content): array
    {
        $text = trim($content);
        // JSONオブジェクト部分のみ抽出（前後の余計なテキスト・コードブロック対策）
        if (preg_match('/\{.*\}/s', $text, $m)) {
            $text = $m[0];
        }
        try {
            $data = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException('分析結果のJSONを解釈できませんでした。');
        }
        if (!is_array($data)) {
            throw new \RuntimeException('分析結果が不正な形式です。');
        }

        $summary = trim((string) ($data['summary'] ?? ''));
        $rawTopics = is_array($data['topics'] ?? null) ? $data['topics'] : [];

        $topics = [];
        foreach ($rawTopics as $t) {
            if (!is_array($t)) {
                continue;
            }
            $label = trim((string) ($t['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $priority = (string) ($t['priority'] ?? 'medium');
            if (!in_array($priority, ['high', 'medium', 'low'], true)) {
                $priority = 'medium';
            }
            $ids = [];
            if (is_array($t['sample_message_ids'] ?? null)) {
                foreach ($t['sample_message_ids'] as $id) {
                    if (is_numeric($id)) {
                        $ids[] = (int) $id;
                    }
                }
            }
            $topics[] = [
                'label' => mb_substr($label, 0, 200),
                'representative_question' => mb_substr(trim((string) ($t['representative_question'] ?? '')), 0, 1000),
                'question_count' => max(0, (int) ($t['question_count'] ?? 0)),
                'unresolved_count' => max(0, (int) ($t['unresolved_count'] ?? 0)),
                'sample_message_ids' => array_slice($ids, 0, 5),
                'suggested_action' => mb_substr(trim((string) ($t['suggested_action'] ?? '')), 0, 2000),
                'priority' => $priority,
            ];
        }

        return ['topics' => $topics, 'summary' => $summary];
    }
}
