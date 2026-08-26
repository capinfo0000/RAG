<?php
declare(strict_types=1);

namespace App\Rag;

use App\Llm\LlmFactory;
use App\Llm\LlmMessage;
use App\Llm\LlmProviderInterface;

/**
 * ユーザー質問を検索クエリに書き換えるユーティリティ。
 *
 * - 代名詞解消（前ターンの会話文脈を踏まえる）
 * - 検索キーワード抽出（BM25の精度向上）
 * - 多視点クエリ生成（複数クエリでHybrid Searchを並列実行する用途）
 *
 * 失敗時は原文をそのまま返す（fail-safe）。
 */
final class QueryRewriter
{
    public function __construct(
        private readonly ?LlmProviderInterface $provider = null,
    ) {
    }

    public static function fromConfig(?LlmProviderInterface $provider = null): self
    {
        return new self($provider ?? LlmFactory::create());
    }

    /**
     * 単一クエリへの書き換え。
     *
     * @param array<int, array{role:string, content:string}> $history 直近会話履歴
     */
    public function rewrite(string $userInput, array $history = []): string
    {
        if ($this->provider === null || trim($userInput) === '') {
            return $userInput;
        }

        $historyText = '';
        foreach (array_slice($history, -6) as $turn) {
            $role = $turn['role'] === 'user' ? 'ユーザー' : 'アシスタント';
            $historyText .= "- {$role}: {$turn['content']}\n";
        }

        $system = <<<PROMPT
あなたは検索クエリ書き換えアシスタントです。次の質問を、製品ナレッジベースを検索するための短い独立クエリに書き換えてください。

要件:
- 代名詞（それ・あれ・そのプラン等）は履歴を参照して具体名に置換
- 「教えて」「知りたい」などの依頼表現は削除
- 検索に有効なキーワード中心の表現にする
- 1文・80文字以内
- 書き換えた検索クエリのみを出力（前置き・コードブロック・引用符不要）
PROMPT;

        $userPrompt = ($historyText === '' ? '' : "【直近の会話】\n{$historyText}\n") . "【質問】\n{$userInput}";

        try {
            $resp = $this->provider->generate(
                messages: [LlmMessage::user($userPrompt)],
                options: ['system' => $system, 'temperature' => 0.0, 'max_tokens' => 120],
            );
            $rewritten = trim($resp->content);
            $rewritten = preg_replace('/^[「『"\'\`]\s*|\s*[」』"\'\`]$/u', '', $rewritten) ?? $rewritten;
            return $rewritten !== '' ? $rewritten : $userInput;
        } catch (\Throwable) {
            return $userInput;
        }
    }

    /**
     * 多視点クエリ生成（HyDE的アプローチ）。N 件の異なる切り口のクエリを返す。
     *
     * @return string[] 元クエリ + 派生クエリ
     */
    public function expand(string $userInput, int $n = 3): array
    {
        if ($this->provider === null || trim($userInput) === '' || $n <= 1) {
            return [$userInput];
        }
        $system = <<<PROMPT
あなたは検索クエリ拡張アシスタントです。元の質問と意味的に近い、しかし表現や視点が異なる検索クエリを生成してください。

要件:
- 出力は JSON 配列のみ。例: ["クエリ1","クエリ2","クエリ3"]
- 元クエリと近いが用語や視点が異なるもの
- 各クエリは80文字以内
- 不要な前置き・コードブロック・改行を含めない
PROMPT;

        try {
            $resp = $this->provider->generate(
                messages: [LlmMessage::user("元の質問: {$userInput}\n\n上記の質問に対し、{$n}件の検索クエリを生成してください。")],
                options: [
                    'system' => $system,
                    'temperature' => 0.4,
                    'max_tokens' => 400,
                    'response_json' => true,
                ],
            );
            $text = trim($resp->content);
            // コードブロックや前置きを除去
            if (preg_match('/\[.*\]/s', $text, $m)) {
                $text = $m[0];
            }
            $decoded = json_decode($text, true);
            if (is_array($decoded) && $decoded !== []) {
                $arr = array_values(array_filter(array_map('strval', $decoded), fn($s) => trim($s) !== ''));
                array_unshift($arr, $userInput);
                return array_values(array_unique($arr));
            }
        } catch (\Throwable) {
            // fall through
        }
        return [$userInput];
    }
}
