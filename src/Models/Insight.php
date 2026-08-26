<?php
declare(strict_types=1);

namespace App\Models;

/**
 * 改善分析（analysis_runs / improvement_suggestions）のDB操作。
 *
 * analysis_runs:           1回の分析実行の履歴（実行時刻・対象件数・トークン・成否）
 * improvement_suggestions: その実行が出したトピック別の改善案（run_id で紐づく）
 */
final class Insight
{
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    public const SUG_OPEN = 'open';
    public const SUG_ADDRESSED = 'addressed';
    public const SUG_DISMISSED = 'dismissed';

    // ---------- analysis_runs ----------

    /** 実行を開始（status=running）し、run_id を返す */
    public static function createRun(int $periodDays, ?string $provider, ?string $model): int
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO analysis_runs (period_days, llm_provider, llm_model, status)
             VALUES (:d, :p, :m, :s)'
        );
        $stmt->execute([
            ':d' => $periodDays,
            ':p' => $provider,
            ':m' => $model,
            ':s' => self::STATUS_RUNNING,
        ]);
        return (int) Database::pdo()->lastInsertId();
    }

    public static function completeRun(
        int $runId,
        int $questionCount,
        int $feedbackCount,
        int $topicCount,
        ?array $tokenUsage
    ): void {
        $stmt = Database::pdo()->prepare(
            'UPDATE analysis_runs
             SET status = :s, question_count = :q, feedback_count = :f,
                 topic_count = :t, token_usage = :u
             WHERE id = :id'
        );
        $stmt->execute([
            ':s' => self::STATUS_COMPLETED,
            ':q' => $questionCount,
            ':f' => $feedbackCount,
            ':t' => $topicCount,
            ':u' => $tokenUsage !== null ? json_encode($tokenUsage, JSON_UNESCAPED_UNICODE) : null,
            ':id' => $runId,
        ]);
    }

    public static function failRun(int $runId, string $error): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE analysis_runs SET status = :s, error_message = :e WHERE id = :id'
        );
        $stmt->execute([
            ':s' => self::STATUS_FAILED,
            ':e' => mb_substr($error, 0, 2000),
            ':id' => $runId,
        ]);
    }

    public static function findRun(int $runId): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM analysis_runs WHERE id = :id');
        $stmt->execute([':id' => $runId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** 完了済みの最新 run を返す（無ければ null） */
    public static function latestCompletedRun(): ?array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT * FROM analysis_runs WHERE status = :s ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([':s' => self::STATUS_COMPLETED]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @return array[] 実行履歴（新しい順） */
    public static function listRuns(int $limit = 20): array
    {
        $limit = max(1, min($limit, 200));
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM analysis_runs ORDER BY id DESC LIMIT :lim'
        );
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    // ---------- improvement_suggestions ----------

    /**
     * トピック別改善案をまとめて保存。
     * @param array<int, array{label:string, representative_question:string, question_count:int,
     *        unresolved_count:int, sample_message_ids:int[], suggested_action:string, priority:string}> $topics
     */
    public static function saveSuggestions(int $runId, array $topics): void
    {
        if ($topics === []) {
            return;
        }
        Database::transaction(function ($pdo) use ($runId, $topics) {
            $stmt = $pdo->prepare(
                'INSERT INTO improvement_suggestions
                 (run_id, topic_label, representative_question, question_count,
                  unresolved_count, sample_message_ids, suggested_action, priority)
                 VALUES (:r, :l, :rq, :qc, :uc, :ids, :sa, :pr)'
            );
            foreach ($topics as $t) {
                $stmt->execute([
                    ':r' => $runId,
                    ':l' => $t['label'],
                    ':rq' => $t['representative_question'] ?? '',
                    ':qc' => (int) ($t['question_count'] ?? 0),
                    ':uc' => (int) ($t['unresolved_count'] ?? 0),
                    ':ids' => json_encode($t['sample_message_ids'] ?? [], JSON_UNESCAPED_UNICODE),
                    ':sa' => $t['suggested_action'] ?? '',
                    ':pr' => in_array($t['priority'] ?? 'medium', ['high', 'medium', 'low'], true)
                        ? $t['priority'] : 'medium',
                ]);
            }
        });
    }

    /** @return array[] 指定 run の改善案。優先度(high→low)→質問数の多い順 */
    public static function listSuggestionsByRun(int $runId): array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT * FROM improvement_suggestions
             WHERE run_id = :r
             ORDER BY FIELD(priority, 'high', 'medium', 'low'), question_count DESC, id ASC"
        );
        $stmt->execute([':r' => $runId]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            if (!empty($row['sample_message_ids']) && is_string($row['sample_message_ids'])) {
                $decoded = json_decode($row['sample_message_ids'], true);
                $row['sample_message_ids'] = is_array($decoded) ? $decoded : [];
            } else {
                $row['sample_message_ids'] = [];
            }
        }
        return $rows;
    }

    public static function updateSuggestionStatus(int $suggestionId, string $status): bool
    {
        if (!in_array($status, [self::SUG_OPEN, self::SUG_ADDRESSED, self::SUG_DISMISSED], true)) {
            return false;
        }
        $stmt = Database::pdo()->prepare(
            'UPDATE improvement_suggestions SET status = :s WHERE id = :id'
        );
        $stmt->execute([':s' => $status, ':id' => $suggestionId]);
        // rowCount は値が変わらない UPDATE で 0 を返すため、対象行の存在で成否を判定する
        $check = Database::pdo()->prepare('SELECT 1 FROM improvement_suggestions WHERE id = :id');
        $check->execute([':id' => $suggestionId]);
        return $check->fetchColumn() !== false;
    }
}
