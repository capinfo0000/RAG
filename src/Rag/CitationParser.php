<?php
declare(strict_types=1);

namespace App\Rag;

use App\Llm\Citation;

/**
 * 生成テキスト中の [1] [2] パターンを Citation[] に変換する。
 *
 * プロバイダー（Gemini / OpenAI / Ollama）が公式citation APIを持たないので、
 * プロンプトで「[N]形式の引用必須」を強制し、回答テキストを parse してメタデータと結合する。
 *
 * 検出パターン:
 *   - [1]
 *   - [1, 2]
 *   - [1, 2, 3]
 *   - [1][2]   ← 単に連結
 *   - 【1】（全角ブラケット）
 */
final class CitationParser
{
    /**
     * @param string $text 回答テキスト
     * @param array[] $sourceChunks 検索ヒットチャンク（順序が[N]の番号に対応）
     *   各要素: ['id'=>int, 'document_id'=>int, 'document_title'=>?string, 'text'=>string]
     *
     * @return Citation[] 出現順、重複除去済み
     */
    public static function parse(string $text, array $sourceChunks): array
    {
        if ($text === '' || $sourceChunks === []) {
            return [];
        }

        // 半角[…]と全角【…】の両方を捕まえる
        // 先頭の引用番号群(group1)を捕捉。`[1, 第2条1項]` のように番号の後へ
        // 条項テキストが続く形も許容する（LLMが多用するため）。全角カンマ「、」も可。
        $pattern = '/[\[\【]\s*(\d+(?:\s*[,、]\s*\d+)*)(?:\s*[,、]\s*[^\]\】\d][^\]\】]*)?\s*[\]\】]/u';
        $found = [];

        if (preg_match_all($pattern, $text, $matches) === false) {
            return [];
        }

        foreach ($matches[1] as $group) {
            foreach (preg_split('/\s*[,、]\s*/', $group) as $num) {
                $idx = (int) $num;
                if ($idx >= 1 && $idx <= count($sourceChunks) && !isset($found[$idx])) {
                    $found[$idx] = true;
                }
            }
        }

        $citations = [];
        foreach (array_keys($found) as $idx) {
            $chunk = $sourceChunks[$idx - 1];
            $citations[] = new Citation(
                index: $idx,
                chunkId: isset($chunk['id']) ? (int) $chunk['id'] : null,
                documentId: isset($chunk['document_id']) ? (int) $chunk['document_id'] : null,
                documentTitle: $chunk['document_title'] ?? null,
                citedText: isset($chunk['text']) ? mb_substr((string) $chunk['text'], 0, 300) : null,
            );
        }
        return $citations;
    }

    /**
     * 検出されたが範囲外（[99] など）の引用番号を返す。デバッグ・警告用。
     *
     * @param array[] $sourceChunks
     * @return int[]
     */
    public static function findInvalidIndices(string $text, array $sourceChunks): array
    {
        // 先頭の引用番号群(group1)を捕捉。`[1, 第2条1項]` のように番号の後へ
        // 条項テキストが続く形も許容する（LLMが多用するため）。全角カンマ「、」も可。
        $pattern = '/[\[\【]\s*(\d+(?:\s*[,、]\s*\d+)*)(?:\s*[,、]\s*[^\]\】\d][^\]\】]*)?\s*[\]\】]/u';
        $invalid = [];
        if (preg_match_all($pattern, $text, $matches) === false) {
            return [];
        }
        $max = count($sourceChunks);
        foreach ($matches[1] as $group) {
            foreach (preg_split('/\s*[,、]\s*/', $group) as $num) {
                $idx = (int) $num;
                if ($idx < 1 || $idx > $max) {
                    $invalid[] = $idx;
                }
            }
        }
        return array_values(array_unique($invalid));
    }
}
