<?php
declare(strict_types=1);

namespace App\Rag;

use App\Config;
use App\Llm\LlmFactory;
use App\Llm\LlmMessage;
use App\Llm\LlmProviderInterface;
use App\Models\Settings;

/**
 * LLM ベースのリランカー。
 *
 * 50件などの候補に対し、軽量モデル（gemini-flash）で関連度を 1-10 でスコアリングし、
 * 上位 N 件に絞る。Cohere Rerank の代替として無料枠で機能。
 *
 * RERANK_PROVIDER=none の場合は素通し（順位を変えない）。
 */
final class Reranker
{
    public function __construct(
        private readonly ?LlmProviderInterface $provider = null,
        private readonly bool $enabled = true,
    ) {
    }

    public static function fromConfig(?LlmProviderInterface $provider = null): self
    {
        $rp = strtolower((string) Settings::effective('rerank_provider', 'RERANK_PROVIDER', 'none'));
        $enabled = $rp === 'llm';
        return new self(
            provider: $enabled ? ($provider ?? LlmFactory::create()) : null,
            enabled: $enabled,
        );
    }

    /**
     * @param array[] $chunks 各要素は ['id'=>int, 'text'=>string, ...]
     * @return array[] スコア降順で並べた上位 $topK
     */
    public function rerank(string $query, array $chunks, int $topK = 5): array
    {
        if ($chunks === []) {
            return [];
        }
        $topK = max(1, min($topK, count($chunks)));

        if (!$this->enabled || $this->provider === null) {
            return array_slice($chunks, 0, $topK);
        }

        // 入力長を抑える: チャンクごとに最大400文字に切り詰めた要約版を LLM に渡す
        $items = [];
        foreach ($chunks as $i => $c) {
            $excerpt = mb_substr((string) ($c['text'] ?? ''), 0, 400);
            $items[] = sprintf("[%d] %s", $i + 1, $excerpt);
        }
        $itemsText = implode("\n\n", $items);

        $system = <<<PROMPT
あなたは検索結果の関連度評価アシスタントです。
ユーザーの質問に対し、各文書チャンクの関連度を 0〜10 でスコアリングしてください。

要件:
- 質問への回答に直接使えるなら高得点（8-10）
- 部分的に関連するなら中程度（4-7）
- 無関係なら低得点（0-3）
- JSON配列のみで返す。例: [{"i":1,"s":9},{"i":2,"s":4},...]
- 全チャンク（[1]〜[N]）に必ずスコアを付ける
- 余計な前置き・コードブロック禁止
PROMPT;

        $user = "【質問】\n{$query}\n\n【チャンク】\n{$itemsText}";

        try {
            $resp = $this->provider->generate(
                messages: [LlmMessage::user($user)],
                options: [
                    'system' => $system,
                    'temperature' => 0.0,
                    'max_tokens' => 2048,
                    'response_json' => true,
                ],
            );
        } catch (\Throwable $e) {
            error_log('[Reranker] LLM failed, falling back to candidate order: ' . $e->getMessage());
            return array_slice($chunks, 0, $topK);
        }

        $scores = $this->parseScores($resp->content);
        if ($scores === []) {
            error_log('[Reranker] Score parse failed, falling back to candidate order. Raw: ' . mb_substr($resp->content, 0, 300));
            return array_slice($chunks, 0, $topK);
        }

        // スコアを各チャンクに付与してソート
        $scored = [];
        foreach ($chunks as $i => $c) {
            $idx = $i + 1;
            $score = $scores[$idx] ?? 0.0;
            $c['rerank_score'] = $score;
            $scored[] = $c;
        }
        usort($scored, fn($a, $b) => ($b['rerank_score'] ?? 0) <=> ($a['rerank_score'] ?? 0));

        return array_slice($scored, 0, $topK);
    }

    /**
     * @return array<int, float> index(1-based) => score
     */
    private function parseScores(string $text): array
    {
        $text = trim($text);
        if (preg_match('/\[.*\]/s', $text, $m)) {
            $text = $m[0];
        }
        try {
            $arr = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        if (!is_array($arr)) {
            return [];
        }
        $out = [];
        foreach ($arr as $item) {
            if (!is_array($item)) {
                continue;
            }
            $i = $item['i'] ?? $item['index'] ?? null;
            $s = $item['s'] ?? $item['score'] ?? null;
            if (is_numeric($i) && is_numeric($s)) {
                $out[(int) $i] = (float) $s;
            }
        }
        return $out;
    }
}
