<?php
declare(strict_types=1);

namespace App\Rag;

use App\Embedding\EmbeddingFactory;
use App\Embedding\EmbeddingProviderInterface;
use App\Models\Chunk;
use App\Models\UsageLog;

/**
 * キーワード検索（日本語向け n-gram LIKE）＋ ベクトル検索（DB内コサイン類似度）の
 * Hybrid Search。
 *
 * EMBEDDING_PROVIDER=none の場合はキーワード検索のみで縮退する（精度は落ちるが完全動作）。
 * 埋め込みプロバイダを設定すると DbVectorSearch によるセマンティック検索が加わる。
 *
 * 統合は RRF（Reciprocal Rank Fusion, k=60 が定番）。
 */
final class HybridSearch
{
    public function __construct(
        private readonly ?EmbeddingProviderInterface $embedding = null,
        private readonly ?VectorSearchInterface $vectorIndex = null,
        private readonly int $rrfK = 60,
    ) {
    }

    public static function fromConfig(): self
    {
        // 埋め込みプロバイダがある場合のみ DB内ベクトル検索を有効化する。
        // EMBEDDING_PROVIDER=none なら embedding=null → キーワード検索のみで縮退。
        $embedding = EmbeddingFactory::create();
        return new self(
            embedding: $embedding,
            vectorIndex: $embedding !== null ? new DbVectorSearch($embedding->getModelName()) : null,
        );
    }

    /**
     * @return array[] チャンク行（rrf_score 付き）。先頭ほど関連度高い。
     */
    public function search(string $query, int $topK = 20): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }
        $topK = max(1, min($topK, 100));
        $candidatePoolSize = max($topK * 4, 20);

        $resultLists = [];

        // --- BM25（必須） ---
        $bm25 = Chunk::fulltextSearch($query, $candidatePoolSize);
        if ($bm25 !== []) {
            $resultLists[] = $bm25;
        }

        // --- ベクトル検索（任意） ---
        if ($this->embedding !== null && $this->vectorIndex !== null) {
            try {
                $vec = $this->embedding->embedOne($query, 'query');
                // 質問のベクトル化も API 消費。埋め込みはトークン数を返さないため推定値を台帳へ（best-effort）。
                UsageLog::record(
                    UsageLog::KIND_EMBED_QUERY,
                    UsageLog::estimateTokens($query),
                    $this->embedding->getModelName(),
                    true
                );
                $vecHits = $this->vectorIndex->search($vec, $candidatePoolSize);
                if ($vecHits !== []) {
                    // vector_id → chunks へマッピング
                    $chunkIds = array_map(fn(array $h) => (int) $h['chunk_id'], $vecHits);
                    $vecRows = Chunk::findByIds($chunkIds);
                    if ($vecRows !== []) {
                        $resultLists[] = $vecRows;
                    }
                }
            } catch (\Throwable) {
                // ベクトル検索失敗時は BM25 だけで続行
            }
        }

        if ($resultLists === []) {
            return [];
        }

        return $this->rrf($resultLists, $topK);
    }

    /**
     * Reciprocal Rank Fusion: score = sum( 1 / (k + rank) )
     *
     * @param array<int, array[]> $lists 各リストはチャンク行の配列、スコア順
     * @return array[] 統合済み上位 $topK
     */
    private function rrf(array $lists, int $topK): array
    {
        $scores = [];
        $rows = [];
        foreach ($lists as $list) {
            $rank = 1;
            foreach ($list as $row) {
                if (!isset($row['id'])) {
                    continue;
                }
                $id = (int) $row['id'];
                $scores[$id] = ($scores[$id] ?? 0.0) + 1.0 / ($this->rrfK + $rank);
                $rows[$id] = $row;
                $rank++;
            }
        }
        arsort($scores);
        $topIds = array_slice(array_keys($scores), 0, $topK);

        $out = [];
        foreach ($topIds as $id) {
            $row = $rows[$id];
            $row['rrf_score'] = round($scores[$id], 6);
            $out[] = $row;
        }
        return $out;
    }
}
