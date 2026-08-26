<?php
declare(strict_types=1);

namespace App\Models;

final class Message
{
    public const ROLE_USER = 'user';
    public const ROLE_ASSISTANT = 'assistant';
    public const ROLE_SYSTEM = 'system';

    public const TYPE_ANSWER = 'answer';
    public const TYPE_REFUSAL = 'refusal';
    public const TYPE_CLARIFICATION = 'clarification';
    public const TYPE_ESCALATION = 'escalation';

    public static function create(array $data): int
    {
        $sql = 'INSERT INTO messages
                (conversation_id, role, content, citations, retrieved_chunks,
                 confidence_score, response_type, latency_ms, token_usage)
                VALUES (:cid, :role, :content, :cit, :retr,
                        :conf, :type, :lat, :tok)';
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([
            ':cid' => $data['conversation_id'],
            ':role' => $data['role'],
            ':content' => $data['content'],
            ':cit' => isset($data['citations'])
                ? json_encode($data['citations'], JSON_UNESCAPED_UNICODE)
                : null,
            ':retr' => isset($data['retrieved_chunks'])
                ? json_encode($data['retrieved_chunks'], JSON_UNESCAPED_UNICODE)
                : null,
            ':conf' => $data['confidence_score'] ?? null,
            ':type' => $data['response_type'] ?? self::TYPE_ANSWER,
            ':lat' => $data['latency_ms'] ?? null,
            ':tok' => isset($data['token_usage'])
                ? json_encode($data['token_usage'], JSON_UNESCAPED_UNICODE)
                : null,
        ]);
        $id = (int) Database::pdo()->lastInsertId();
        Conversation::incrementMessageCount((int) $data['conversation_id']);
        return $id;
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM messages WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : self::hydrate($row);
    }

    /** @return array[] */
    public static function listByConversation(int $conversationId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM messages WHERE conversation_id = :cid ORDER BY id ASC'
        );
        $stmt->execute([':cid' => $conversationId]);
        return array_map([self::class, 'hydrate'], $stmt->fetchAll());
    }

    /** @return array[] 直近 N 件、新しい順 */
    public static function listRecent(int $limit = 50): array
    {
        $limit = max(1, min($limit, 500));
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM messages ORDER BY id DESC LIMIT :lim'
        );
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return array_map([self::class, 'hydrate'], $stmt->fetchAll());
    }

    /**
     * 改善分析用: 直近 $days 日のユーザー質問を、所属会話の解決状況付きで取得（新しい順）。
     * @return array<int, array{id:int, content:string, resolved:?string, created_at:string}>
     */
    public static function recentUserQuestionsWithResolution(int $days = 30, int $limit = 200): array
    {
        $days = max(1, min($days, 365));
        $limit = max(1, min($limit, 1000));
        $sql = "SELECT m.id, m.content, c.resolved, m.created_at
                FROM messages m
                INNER JOIN conversations c ON c.id = m.conversation_id
                WHERE m.role = :role
                  AND m.created_at >= (NOW() - INTERVAL :days DAY)
                ORDER BY m.id DESC
                LIMIT :lim";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->bindValue(':role', self::ROLE_USER);
        $stmt->bindValue(':days', $days, \PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[] = [
                'id' => (int) $r['id'],
                'content' => (string) $r['content'],
                'resolved' => $r['resolved'] === null ? null : (string) $r['resolved'],
                'created_at' => (string) $r['created_at'],
            ];
        }
        return $out;
    }

    /** 直近 $days 日のユーザー質問総数（分析対象母数の表示用） */
    public static function countUserQuestions(int $days = 30): int
    {
        $days = max(1, min($days, 365));
        $stmt = Database::pdo()->prepare(
            "SELECT COUNT(*) AS c FROM messages
             WHERE role = :role AND created_at >= (NOW() - INTERVAL :days DAY)"
        );
        $stmt->bindValue(':role', self::ROLE_USER);
        $stmt->bindValue(':days', $days, \PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();
        return (int) ($row['c'] ?? 0);
    }

    /**
     * 当月（暦月）の消費トークン合計。トークン上限判定＆ベンダー専用ダッシュボードで使用。
     * token_usage(JSON) の $.total を合算。毎月1日でリセット扱い（DATE_FORMAT基準）。
     */
    public static function monthlyTokenTotal(): int
    {
        $sql = 'SELECT COALESCE(SUM(CAST(JSON_EXTRACT(token_usage, \'$.total\') AS UNSIGNED)), 0) AS t
                FROM messages
                WHERE token_usage IS NOT NULL
                  AND created_at >= DATE_FORMAT(NOW(), \'%Y-%m-01 00:00:00\')';
        $row = Database::pdo()->query($sql)->fetch();
        return (int) ($row['t'] ?? 0);
    }

    /** JSON文字列を配列に展開して扱いやすくする */
    private static function hydrate(array $row): array
    {
        foreach (['citations', 'retrieved_chunks', 'token_usage'] as $jsonCol) {
            if (!empty($row[$jsonCol]) && is_string($row[$jsonCol])) {
                try {
                    $row[$jsonCol] = json_decode($row[$jsonCol], true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    // 既存の壊れたJSONはnullに落とす（運用画面で確認できる）
                    $row[$jsonCol] = null;
                }
            }
        }
        return $row;
    }
}
