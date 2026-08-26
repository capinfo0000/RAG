<?php
declare(strict_types=1);

namespace App\Models;

/**
 * チャンク（chunks テーブル）の操作。
 * BM25 代替の FULLTEXT 検索もここに集約。
 */
final class Chunk
{
    public static function create(array $data): int
    {
        $sql = 'INSERT INTO chunks
                (document_id, chunk_index, text, context_text, token_count, vector_id, metadata)
                VALUES (:did, :idx, :text, :ctx, :tokens, :vec, :meta)';
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([
            ':did' => $data['document_id'],
            ':idx' => $data['chunk_index'],
            ':text' => $data['text'],
            ':ctx' => $data['context_text'] ?? null,
            ':tokens' => $data['token_count'] ?? 0,
            ':vec' => $data['vector_id'] ?? null,
            ':meta' => isset($data['metadata']) ? json_encode($data['metadata'], JSON_UNESCAPED_UNICODE) : null,
        ]);
        return (int) Database::pdo()->lastInsertId();
    }

    /**
     * チャンク群を一括INSERT（TX内）。
     * @param array<int, array> $rows
     * @return int[] 挿入されたID群
     */
    public static function createMany(array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        return Database::transaction(function ($pdo) use ($rows) {
            $ids = [];
            $sql = 'INSERT INTO chunks
                    (document_id, chunk_index, text, context_text, token_count, vector_id, metadata)
                    VALUES (:did, :idx, :text, :ctx, :tokens, :vec, :meta)';
            $stmt = $pdo->prepare($sql);
            foreach ($rows as $r) {
                $stmt->execute([
                    ':did' => $r['document_id'],
                    ':idx' => $r['chunk_index'],
                    ':text' => $r['text'],
                    ':ctx' => $r['context_text'] ?? null,
                    ':tokens' => $r['token_count'] ?? 0,
                    ':vec' => $r['vector_id'] ?? null,
                    ':meta' => isset($r['metadata']) ? json_encode($r['metadata'], JSON_UNESCAPED_UNICODE) : null,
                ]);
                $ids[] = (int) $pdo->lastInsertId();
            }
            return $ids;
        });
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM chunks WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @return array[] */
    public static function findByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $ids = array_values(array_map('intval', $ids));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT c.*, d.title AS document_title, d.uuid AS document_uuid, d.category AS document_category
                FROM chunks c
                INNER JOIN documents d ON d.id = c.document_id
                WHERE c.id IN ({$placeholders})";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($ids);
        $rows = $stmt->fetchAll();

        // 元の順序を維持
        $byId = [];
        foreach ($rows as $r) {
            $byId[(int) $r['id']] = $r;
        }
        $ordered = [];
        foreach ($ids as $i) {
            if (isset($byId[$i])) {
                $ordered[] = $byId[$i];
            }
        }
        return $ordered;
    }

    /** @return array[] */
    public static function findByDocumentId(int $documentId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM chunks WHERE document_id = :did ORDER BY chunk_index ASC'
        );
        $stmt->execute([':did' => $documentId]);
        return $stmt->fetchAll();
    }

    public static function deleteByDocumentId(int $documentId): int
    {
        $stmt = Database::pdo()->prepare('DELETE FROM chunks WHERE document_id = :did');
        $stmt->execute([':did' => $documentId]);
        return $stmt->rowCount();
    }

    /**
     * 日本語向けBM25代替: クエリを2-3文字のn-gramに分割してLIKE検索し、
     * マッチしたn-gram数でスコアリングする（簡易TF）。
     *
     * MariaDBの標準FULLTEXTは日本語に弱く、ngramパーサーも非対応のため
     * アプリ側でn-gram化する方式を採用。Phase 1.5でmroonga導入時に差し替え可能。
     *
     * @return array[] 各行に `score` が付く
     */
    public static function fulltextSearch(string $query, int $limit = 50): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }
        $limit = max(1, min($limit, 200));

        // クエリを2-gramに分割（日本語向け）。長すぎたら最大20個まで。
        $ngrams = self::makeNgrams($query, 2);
        if ($ngrams === []) {
            // 短すぎる場合は元クエリそのもので LIKE
            $ngrams = [$query];
        }
        $ngrams = array_slice($ngrams, 0, 20);

        // ネイティブPDOプリペアは同名プレースホルダの再利用を許さないため、
        // 同じ n-gram でも 4箇所（WHERE text / WHERE context / score text / score context）に
        // それぞれ別名のプレースホルダを割り当てる。
        $whereParts = [];
        $scoreParts = [];
        $params = [];
        foreach ($ngrams as $i => $g) {
            $kWt = ":wT{$i}";
            $kWc = ":wC{$i}";
            $kSt = ":sT{$i}";
            $kSc = ":sC{$i}";
            $whereParts[] = "(c.text LIKE {$kWt} OR c.context_text LIKE {$kWc})";
            $scoreParts[] = "(CASE WHEN c.text LIKE {$kSt} THEN 2 ELSE 0 END
                            + CASE WHEN c.context_text LIKE {$kSc} THEN 1 ELSE 0 END)";
            $value = '%' . $g . '%';
            $params[$kWt] = $value;
            $params[$kWc] = $value;
            $params[$kSt] = $value;
            $params[$kSc] = $value;
        }
        $whereClause = implode(' OR ', $whereParts);
        $scoreClause = '(' . implode(' + ', $scoreParts) . ')';

        $sql = "SELECT c.*,
                       d.title AS document_title,
                       d.uuid AS document_uuid,
                       d.category AS document_category,
                       {$scoreClause} AS score
                FROM chunks c
                INNER JOIN documents d ON d.id = c.document_id
                WHERE {$whereClause}
                ORDER BY score DESC, c.id ASC
                LIMIT :lim";

        $stmt = Database::pdo()->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r['score'] = (float) $r['score'];
        }
        return $rows;
    }

    /**
     * @return string[]
     */
    private static function makeNgrams(string $query, int $size = 2): array
    {
        $query = preg_replace('/\s+/u', '', $query) ?? $query;
        $len = mb_strlen($query);
        if ($len < $size) {
            return [];
        }
        $out = [];
        for ($i = 0; $i <= $len - $size; $i++) {
            $g = mb_substr($query, $i, $size);
            if ($g !== '') {
                $out[$g] = true;
            }
        }
        return array_keys($out);
    }

    public static function count(): int
    {
        $stmt = Database::pdo()->query('SELECT COUNT(*) AS c FROM chunks');
        $row = $stmt->fetch();
        return (int) ($row['c'] ?? 0);
    }
}
