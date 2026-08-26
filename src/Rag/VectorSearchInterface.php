<?php
declare(strict_types=1);

namespace App\Rag;

/**
 * ベクトル検索バックエンドの抽象。
 *
 * 既定実装は {@see DbVectorSearch}（DB内コサイン類似度・外部サービス不要）。
 * 大規模化した場合はこのインターフェースを実装した QdrantVectorSearch 等へ
 * 差し替えるだけでよい（HybridSearch / Indexer は無改修）。
 */
interface VectorSearchInterface
{
    /**
     * クエリベクトルに対する k-NN 検索。
     *
     * @param float[] $vector 検索クエリのembedding
     * @param int $topK
     * @return array<int, array{chunk_id:int, score:float}>
     */
    public function search(array $vector, int $topK): array;

    /**
     * インデックスへの登録（upsert）。
     *
     * @param array<int, array{chunk_id:int, vector:float[], payload:array}> $points
     */
    public function upsert(array $points): void;

    public function delete(array $chunkIds): void;
}
