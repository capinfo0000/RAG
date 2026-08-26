<?php
declare(strict_types=1);

namespace App\Embedding;

/**
 * テキスト埋め込み生成プロバイダーの統一インターフェース。
 *
 * 実装: GeminiEmbedding / OpenAiEmbedding / VoyageEmbedding / OllamaEmbedding
 *
 * taskType（任意）:
 *   - 'document' : ドキュメント側エンベディング（既定）
 *   - 'query'    : 検索クエリ側エンベディング
 *   プロバイダーが対応している場合だけ反映される（Gemini/Voyage）。
 */
interface EmbeddingProviderInterface
{
    /**
     * 1件以上のテキストをまとめてベクトル化する。
     *
     * @param string[] $texts 1〜N件
     * @param string $taskType 'document' | 'query'
     * @return float[][] 入力件数 × dimension の配列
     */
    public function embed(array $texts, string $taskType = 'document'): array;

    /**
     * 単一テキストのショートカット。
     *
     * @return float[]
     */
    public function embedOne(string $text, string $taskType = 'query'): array;

    public function getDimension(): int;

    public function getProviderName(): string;

    public function getModelName(): string;
}
