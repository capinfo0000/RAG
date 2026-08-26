<?php
declare(strict_types=1);

namespace App\Models;

use Ramsey\Uuid\Uuid;

/**
 * アップロードされた元文書（documents テーブル）の操作。
 */
final class Document
{
    public const STATUS_UPLOADED = 'uploaded';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_INDEXED = 'indexed';
    public const STATUS_FAILED = 'failed';

    /**
     * 既存の（NULLでない）カテゴリ一覧を使用頻度の高い順に返す。
     * AIによる自動分類で「既存カテゴリの再利用」を促すために使う。
     * @return string[]
     */
    public static function distinctCategories(int $limit = 50): array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT category FROM documents
             WHERE category IS NOT NULL AND category <> ''
             GROUP BY category ORDER BY COUNT(*) DESC, category ASC
             LIMIT {$limit}"
        );
        $stmt->execute();
        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []);
    }

    public static function create(array $data): int
    {
        $uuid = $data['uuid'] ?? Uuid::uuid4()->toString();
        $sql = 'INSERT INTO documents
                (uuid, title, original_filename, storage_path, file_type,
                 file_size_bytes, category, tags, status, full_text)
                VALUES (:uuid, :title, :orig, :path, :type,
                        :size, :cat, :tags, :status, :full_text)';
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([
            ':uuid' => $uuid,
            ':title' => $data['title'],
            ':orig' => $data['original_filename'],
            ':path' => $data['storage_path'],
            ':type' => $data['file_type'],
            ':size' => $data['file_size_bytes'] ?? 0,
            ':cat' => $data['category'] ?? null,
            ':tags' => $data['tags'] ?? null,
            ':status' => $data['status'] ?? self::STATUS_UPLOADED,
            ':full_text' => $data['full_text'] ?? null,
        ]);
        return (int) Database::pdo()->lastInsertId();
    }

    /** 抽出全文を更新する（再インデックス時など）。 */
    public static function updateFullText(int $id, ?string $fullText): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE documents SET full_text = :t WHERE id = :id'
        );
        $stmt->execute([':t' => $fullText, ':id' => $id]);
    }

    /**
     * 指定ID群の全文と見出し情報を取得する（全文書RAGの文脈組み立て用）。
     *
     * @param int[] $ids
     * @return array<int, array{id:int, title:string, category:?string, full_text:?string}>
     *         キーは document_id
     */
    public static function fullTextByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($n) => $n > 0)));
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = Database::pdo()->prepare(
            "SELECT id, title, category, full_text FROM documents WHERE id IN ({$placeholders})"
        );
        $stmt->execute($ids);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(int) $row['id']] = [
                'id' => (int) $row['id'],
                'title' => (string) $row['title'],
                'category' => $row['category'] ?? null,
                'full_text' => $row['full_text'] ?? null,
            ];
        }
        return $out;
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM documents WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public static function findByUuid(string $uuid): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM documents WHERE uuid = :u');
        $stmt->execute([':u' => $uuid]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @return array[] */
    public static function listRecent(int $limit = 50, int $offset = 0): array
    {
        $limit = max(1, min($limit, 500));
        $offset = max(0, $offset);
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM documents ORDER BY created_at DESC LIMIT :lim OFFSET :off'
        );
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public static function updateStatus(int $id, string $status, ?string $errorMessage = null): void
    {
        $sql = 'UPDATE documents SET status = :s, error_message = :e WHERE id = :id';
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([
            ':s' => $status,
            ':e' => $errorMessage,
            ':id' => $id,
        ]);
    }

    public static function updateChunkCount(int $id, int $chunkCount): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE documents SET chunk_count = :c WHERE id = :id'
        );
        $stmt->execute([':c' => $chunkCount, ':id' => $id]);
    }

    public static function delete(int $id): void
    {
        // CASCADE 設定により chunks も削除される
        $stmt = Database::pdo()->prepare('DELETE FROM documents WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    /**
     * 取込元（Obsidian等）の追跡情報を記録する。
     * migration_source.sql で追加した source_type / source_ref / source_hash を更新。
     */
    public static function setSource(int $id, string $sourceType, string $sourceRef, string $sourceHash): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE documents SET source_type = :t, source_ref = :r, source_hash = :h WHERE id = :id'
        );
        $stmt->execute([':t' => $sourceType, ':r' => $sourceRef, ':h' => $sourceHash, ':id' => $id]);
    }

    /**
     * 指定 source_type の文書を source_ref をキーにして返す（増分同期の突き合わせ用）。
     *
     * @return array<string, array{id:int, source_ref:string, source_hash:?string, title:string}>
     */
    public static function listBySourceType(string $sourceType): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id, source_ref, source_hash, title FROM documents WHERE source_type = :t'
        );
        $stmt->execute([':t' => $sourceType]);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $ref = (string) ($row['source_ref'] ?? '');
            if ($ref === '') {
                continue;
            }
            $out[$ref] = [
                'id' => (int) $row['id'],
                'source_ref' => $ref,
                'source_hash' => $row['source_hash'] ?? null,
                'title' => (string) $row['title'],
            ];
        }
        return $out;
    }

    public static function count(): int
    {
        $stmt = Database::pdo()->query('SELECT COUNT(*) AS c FROM documents');
        $row = $stmt->fetch();
        return (int) ($row['c'] ?? 0);
    }
}
