<?php
declare(strict_types=1);

namespace App\Knowledge;

use App\Llm\LlmFactory;
use App\Llm\LlmMessage;
use App\Llm\LlmProviderInterface;

/**
 * アップロード文書から「想定質問100問」を自動生成する。
 *
 * 運用画面で承認 / 編集 / 却下できる（qa_generated テーブル）。
 *
 * 文書が長い場合はチャンク群を順に処理して、まとめて返す。
 */
final class QaGenerator
{
    public function __construct(
        private readonly LlmProviderInterface $llm,
        private readonly int $maxQuestionsPerCall = 20,
    ) {
    }

    public static function fromConfig(?LlmProviderInterface $llm = null): self
    {
        return new self($llm ?? LlmFactory::create());
    }

    /**
     * @param array<int, array{chunk_index:int, text:string}> $chunks
     * @return array<int, array{question:string, expected_answer:string, chunk_index:?int}>
     */
    public function generate(array $chunks, int $targetCount = 50): array
    {
        if ($chunks === []) {
            return [];
        }
        $questions = [];
        $perChunkTarget = max(1, (int) ceil($targetCount / count($chunks)));

        foreach ($chunks as $c) {
            $batch = $this->generateForChunk($c['text'], min($perChunkTarget, $this->maxQuestionsPerCall));
            foreach ($batch as $qa) {
                $qa['chunk_index'] = $c['chunk_index'];
                $questions[] = $qa;
                if (count($questions) >= $targetCount) {
                    break 2;
                }
            }
        }
        return $questions;
    }

    /**
     * @return array<int, array{question:string, expected_answer:string}>
     */
    private function generateForChunk(string $chunkText, int $n): array
    {
        $system = <<<PROMPT
あなたはサポート担当者向けのFAQ作成アシスタントです。
与えられた製品ナレッジから、エンドユーザーが実際に尋ねそうな質問とその回答を生成してください。

要件:
- ユーザーが自然な日本語で尋ねる言い回し（口語可）
- 回答は与えられたナレッジ内の情報のみを根拠にする
- 出力は JSON 配列のみ
- 形式: [{"q":"質問","a":"回答"}, ...]
- 余計な前置き・コードブロック禁止
PROMPT;

        $user = "【ナレッジ】\n{$chunkText}\n\n上記から {$n} 件のQ&Aを生成してください。";

        try {
            $resp = $this->llm->generate(
                messages: [LlmMessage::user($user)],
                options: [
                    'system' => $system,
                    'temperature' => 0.4,
                    'max_tokens' => 2048,
                    'response_json' => true,
                ],
            );
        } catch (\Throwable) {
            return [];
        }

        $text = trim($resp->content);
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
            $q = trim((string) ($item['q'] ?? $item['question'] ?? ''));
            $a = trim((string) ($item['a'] ?? $item['answer'] ?? ''));
            if ($q !== '' && $a !== '') {
                $out[] = ['question' => $q, 'expected_answer' => $a];
            }
        }
        return $out;
    }
}
