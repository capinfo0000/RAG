<?php
declare(strict_types=1);

namespace App\Models;

/**
 * トークン使用量の統一台帳（token_usage_log）。
 *
 * チャット回答・質問/取り込みのベクトル化・AIで作成(compose)・改善提案(insight) など、
 * Gemini API を消費するすべての処理をここに1行ずつ記録する。
 * ダッシュボードの「今月のご利用状況」と、生成の上限ガードはこの合算を参照する。
 *
 * 記録は best-effort（失敗しても本処理を止めない）。
 * 埋め込み分はAPIがトークン数を返さないため estimateTokens() の推定値を estimated=1 で記録する。
 */
final class UsageLog
{
    public const KIND_CHAT = 'chat';
    public const KIND_EMBED_QUERY = 'embed_query';
    public const KIND_EMBED_INGEST = 'embed_ingest';
    public const KIND_COMPOSE = 'compose';
    public const KIND_INSIGHT = 'insight';

    /** 埋め込みトークンの粗い推定係数（文字数 ÷ この値）。日本語想定でやや多め=保守的。必要なら調整可。 */
    private const CHARS_PER_TOKEN = 2.5;

    /**
     * 消費を1件記録する。tokens<=0 は何もしない。DBエラーは握りつぶす（記録失敗で本処理を止めない）。
     */
    public static function record(string $kind, int $tokens, ?string $model = null, bool $estimated = false): void
    {
        if ($tokens <= 0) {
            return;
        }
        try {
            $stmt = Database::pdo()->prepare(
                'INSERT INTO token_usage_log (kind, model, tokens, estimated)
                 VALUES (:kind, :model, :tokens, :est)'
            );
            $stmt->execute([
                ':kind' => mb_substr($kind, 0, 20),
                ':model' => $model !== null ? mb_substr($model, 0, 64) : null,
                ':tokens' => $tokens,
                ':est' => $estimated ? 1 : 0,
            ]);
        } catch (\Throwable $e) {
            error_log('[UsageLog.record] ' . $e->getMessage());
        }
    }

    /**
     * 今月（毎月1日基準）の消費トークン合計。Message::monthlyTokenTotal() と同じ月初基準。
     */
    public static function monthlyTotal(): int
    {
        try {
            $sql = "SELECT COALESCE(SUM(tokens), 0) AS t
                    FROM token_usage_log
                    WHERE created_at >= DATE_FORMAT(NOW(), '%Y-%m-01 00:00:00')";
            $row = Database::pdo()->query($sql)->fetch();
            return (int) ($row['t'] ?? 0);
        } catch (\Throwable $e) {
            error_log('[UsageLog.monthlyTotal] ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * 埋め込み対象テキストからトークン数を推定する（APIが実数を返さないため）。
     *
     * @param string|string[] $text
     */
    public static function estimateTokens(string|array $text): int
    {
        $joined = is_array($text) ? implode("\n", array_map('strval', $text)) : $text;
        $len = mb_strlen($joined);
        if ($len <= 0) {
            return 0;
        }
        return (int) ceil($len / self::CHARS_PER_TOKEN);
    }
}
