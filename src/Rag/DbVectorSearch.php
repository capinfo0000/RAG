<?php
declare(strict_types=1);

namespace App\Rag;

use App\Config;
use App\Models\Database;
use PDO;

/**
 * DB内ベクトル検索（外部サービス不要のコサイン類似度）。
 *
 * embedding を chunks 列（JSON文字列）に保持し、検索時はクエリベクトルとの
 * コサイン類似度を PHP 側で総当り計算して top-K を返す。
 *
 * 想定規模: 社内規程・FAQ 等の数百〜数千チャンク。この規模なら 768次元の
 * 総当りでも 1クエリ数十ミリ秒で収まり、Qdrant 等の外部インフラを持ち込まずに
 * 「共用PHP+MySQL・APIキー＋埋め込み1行で動く」という製品要件を満たせる。
 *
 * 大規模化する場合は同じ {@see VectorSearchInterface} を実装した
 * QdrantVectorSearch 等へ差し替えるだけでよい（HybridSearch は無改修）。
 */
final class DbVectorSearch implements VectorSearchInterface
{
    /** 総当り対象の上限（安全弁）。超えたらログに残して打ち切る。0=env既定を使う。 */
    private const DEFAULT_MAX_CANDIDATES = 5000;

    public function __construct(
        private readonly string $model = '',
        private readonly int $maxCandidates = 0,
    ) {
    }

    /**
     * クエリベクトルに対する k-NN 検索（コサイン類似度）。
     *
     * @param float[] $vector
     * @return array<int, array{chunk_id:int, score:float}> スコア降順
     */
    public function search(array $vector, int $topK): array
    {
        $dim = count($vector);
        if ($dim === 0 || $topK <= 0) {
            return [];
        }

        // クエリ側のノルム（0なら比較不能）
        $qNorm = 0.0;
        foreach ($vector as $v) {
            $qNorm += $v * $v;
        }
        $qNorm = sqrt($qNorm);
        if ($qNorm <= 0.0) {
            return [];
        }

        $cap = $this->maxCandidates > 0
            ? $this->maxCandidates
            : Config::int('RAG_VECTOR_MAX_CANDIDATES', self::DEFAULT_MAX_CANDIDATES);

        // 次元が一致する（＝同じ埋め込みモデルで作られた）チャンクだけを対象にする。
        // モデル差し替え直後で次元が変わった古いベクトルは自然に無視される。
        $stmt = Database::pdo()->prepare(
            'SELECT id, embedding FROM chunks
             WHERE embedding IS NOT NULL AND embedding_dim = :dim'
        );
        $stmt->bindValue(':dim', $dim, PDO::PARAM_INT);
        $stmt->execute();

        $scores = [];
        $count = 0;
        while (($row = $stmt->fetch()) !== false) {
            if ($cap > 0 && ++$count > $cap) {
                error_log("[DbVectorSearch] candidate cap ({$cap}) reached; results truncated. "
                    . '大規模ナレッジでは外部ベクトルDBへの差し替えを検討してください。');
                break;
            }
            $emb = json_decode((string) $row['embedding'], true);
            if (!is_array($emb) || count($emb) !== $dim) {
                continue;
            }
            $dot = 0.0;
            $dNorm = 0.0;
            for ($i = 0; $i < $dim; $i++) {
                $e = (float) $emb[$i];
                $dot += $vector[$i] * $e;
                $dNorm += $e * $e;
            }
            if ($dNorm <= 0.0) {
                continue;
            }
            $scores[(int) $row['id']] = $dot / ($qNorm * sqrt($dNorm));
        }

        if ($scores === []) {
            return [];
        }
        arsort($scores);

        $out = [];
        foreach ($scores as $chunkId => $score) {
            $out[] = ['chunk_id' => $chunkId, 'score' => round($score, 6)];
            if (count($out) >= $topK) {
                break;
            }
        }
        return $out;
    }

    /**
     * ベクトルを chunks 列へ書き込む（upsert）。
     *
     * @param array<int, array{chunk_id:int, vector:float[], payload?:array}> $points
     */
    public function upsert(array $points): void
    {
        if ($points === []) {
            return;
        }
        Database::transaction(function (PDO $pdo) use ($points) {
            $stmt = $pdo->prepare(
                'UPDATE chunks
                 SET embedding = :emb, embedding_dim = :dim, embedding_model = :model, vector_id = :vid
                 WHERE id = :id'
            );
            foreach ($points as $p) {
                $chunkId = (int) ($p['chunk_id'] ?? 0);
                $vector = $p['vector'] ?? [];
                if ($chunkId <= 0 || !is_array($vector) || $vector === []) {
                    continue;
                }
                $stmt->execute([
                    ':emb' => json_encode(array_map('floatval', $vector)),
                    ':dim' => count($vector),
                    ':model' => $this->model !== '' ? $this->model : null,
                    ':vid' => (string) $chunkId,
                    ':id' => $chunkId,
                ]);
            }
        });
    }

    /**
     * @param int[] $chunkIds
     */
    public function delete(array $chunkIds): void
    {
        if ($chunkIds === []) {
            return;
        }
        // 通常はチャンク自体が documents の CASCADE で消えるため呼ばれないが、
        // 明示的にベクトルだけ落としたいケース用。
        $ids = array_values(array_map('intval', $chunkIds));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = Database::pdo()->prepare(
            "UPDATE chunks
             SET embedding = NULL, embedding_dim = NULL, embedding_model = NULL, vector_id = NULL
             WHERE id IN ({$placeholders})"
        );
        $stmt->execute($ids);
    }
}
