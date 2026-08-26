<?php
declare(strict_types=1);

namespace App\Models;

final class Feedback
{
    public const RATING_UP = 'up';
    public const RATING_DOWN = 'down';
    public const RATING_RESOLVED = 'resolved';
    public const RATING_UNRESOLVED = 'unresolved';

    public static function create(int $messageId, string $rating, ?string $comment = null): int
    {
        $sql = 'INSERT INTO feedback (message_id, rating, comment) VALUES (:m, :r, :c)';
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([':m' => $messageId, ':r' => $rating, ':c' => $comment]);
        return (int) Database::pdo()->lastInsertId();
    }

    /**
     * アンケートの自由記述・理由が入力されたフィードバックを新着順に取得。
     * 今後の改善のための一覧表示に使う。
     * @return array[]
     */
    public static function recentWithComments(int $limit = 50, ?int $days = null): array
    {
        $limit = max(1, min($limit, 500));
        $dayFilter = $days !== null ? ' AND f.created_at >= (NOW() - INTERVAL :days DAY)' : '';
        $sql = "SELECT f.*, m.content AS message_content, m.conversation_id
                FROM feedback f
                INNER JOIN messages m ON m.id = f.message_id
                WHERE f.comment IS NOT NULL AND f.comment <> ''{$dayFilter}
                ORDER BY f.id DESC
                LIMIT :lim";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        if ($days !== null) {
            $stmt->bindValue(':days', max(1, min($days, 365)), \PDO::PARAM_INT);
        }
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** @return array[] */
    public static function recentNegatives(int $limit = 50): array
    {
        $limit = max(1, min($limit, 500));
        $sql = 'SELECT f.*, m.content AS message_content, m.conversation_id
                FROM feedback f
                INNER JOIN messages m ON m.id = f.message_id
                WHERE f.rating IN (:r1, :r2)
                ORDER BY f.id DESC
                LIMIT :lim';
        $stmt = Database::pdo()->prepare($sql);
        $stmt->bindValue(':r1', self::RATING_DOWN);
        $stmt->bindValue(':r2', self::RATING_UNRESOLVED);
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public static function stats(?int $days = 7): array
    {
        $where = $days === null ? '' : 'WHERE created_at >= NOW() - INTERVAL ' . (int) $days . ' DAY';
        $sql = "SELECT
                  SUM(CASE WHEN rating = 'up' THEN 1 ELSE 0 END) AS up,
                  SUM(CASE WHEN rating = 'down' THEN 1 ELSE 0 END) AS down,
                  SUM(CASE WHEN rating = 'resolved' THEN 1 ELSE 0 END) AS resolved,
                  SUM(CASE WHEN rating = 'unresolved' THEN 1 ELSE 0 END) AS unresolved,
                  COUNT(*) AS total
                FROM feedback {$where}";
        $row = Database::pdo()->query($sql)->fetch();
        return [
            'up' => (int) ($row['up'] ?? 0),
            'down' => (int) ($row['down'] ?? 0),
            'resolved' => (int) ($row['resolved'] ?? 0),
            'unresolved' => (int) ($row['unresolved'] ?? 0),
            'total' => (int) ($row['total'] ?? 0),
        ];
    }
}
