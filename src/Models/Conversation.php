<?php
declare(strict_types=1);

namespace App\Models;

use Ramsey\Uuid\Uuid;

final class Conversation
{
    public static function create(?string $userIdentifier = null, array $metadata = []): array
    {
        $sessionUuid = Uuid::uuid4()->toString();
        $sql = 'INSERT INTO conversations (session_uuid, user_identifier, metadata)
                VALUES (:s, :u, :m)';
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([
            ':s' => $sessionUuid,
            ':u' => $userIdentifier,
            ':m' => $metadata === [] ? null : json_encode($metadata, JSON_UNESCAPED_UNICODE),
        ]);
        $id = (int) Database::pdo()->lastInsertId();
        return ['id' => $id, 'session_uuid' => $sessionUuid];
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM conversations WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public static function findBySessionUuid(string $sessionUuid): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM conversations WHERE session_uuid = :s'
        );
        $stmt->execute([':s' => $sessionUuid]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public static function incrementMessageCount(int $id): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE conversations SET message_count = message_count + 1 WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);
    }

    public static function markResolved(int $id, bool $resolved): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE conversations SET resolved = :r, ended_at = NOW() WHERE id = :id'
        );
        $stmt->execute([':r' => $resolved ? 1 : 0, ':id' => $id]);
    }

    public static function markEscalated(int $id): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE conversations SET escalated_to_human = 1, ended_at = NOW() WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);
    }

    /** @return array[] */
    public static function listRecent(int $limit = 50, int $offset = 0): array
    {
        $limit = max(1, min($limit, 500));
        $offset = max(0, $offset);
        $stmt = Database::pdo()->prepare(
            "SELECT c.*, (
                 SELECT m.content FROM messages m
                 WHERE m.conversation_id = c.id AND m.role = 'user'
                 ORDER BY m.id ASC LIMIT 1
             ) AS first_question
             FROM conversations c
             ORDER BY c.started_at DESC LIMIT :lim OFFSET :off"
        );
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** ダッシュボード集計 */
    public static function stats(?int $days = 7): array
    {
        $where = $days === null ? '' : 'WHERE started_at >= NOW() - INTERVAL ' . (int) $days . ' DAY';
        $sql = "SELECT
                  COUNT(*) AS total,
                  SUM(CASE WHEN resolved = 1 THEN 1 ELSE 0 END) AS resolved,
                  SUM(CASE WHEN resolved = 0 THEN 1 ELSE 0 END) AS unresolved,
                  SUM(CASE WHEN escalated_to_human = 1 THEN 1 ELSE 0 END) AS escalated
                FROM conversations
                {$where}";
        $row = Database::pdo()->query($sql)->fetch();
        return [
            'total' => (int) ($row['total'] ?? 0),
            'resolved' => (int) ($row['resolved'] ?? 0),
            'unresolved' => (int) ($row['unresolved'] ?? 0),
            'escalated' => (int) ($row['escalated'] ?? 0),
        ];
    }
}
