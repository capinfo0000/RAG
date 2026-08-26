-- ============================================================
-- マイグレーション: 改善分析機能（analysis_runs / improvement_suggestions）
-- ============================================================
-- 既存DBへの追加用。既存テーブルは変更しません（CREATE TABLE IF NOT EXISTS のみ）。
-- 適用方法（XAMPP例）:
--   mysql -u root rag_chatbot < sql/migration_insights.sql
-- ============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS analysis_runs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    period_days INT UNSIGNED NOT NULL DEFAULT 30,
    question_count INT UNSIGNED DEFAULT 0,
    feedback_count INT UNSIGNED DEFAULT 0,
    topic_count INT UNSIGNED DEFAULT 0,
    llm_provider VARCHAR(50),
    llm_model VARCHAR(100),
    token_usage JSON,
    status ENUM('running','completed','failed') NOT NULL DEFAULT 'running',
    error_message TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS improvement_suggestions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    run_id BIGINT UNSIGNED NOT NULL,
    topic_label VARCHAR(500) NOT NULL,
    representative_question TEXT,
    question_count INT UNSIGNED DEFAULT 0,
    unresolved_count INT UNSIGNED DEFAULT 0,
    sample_message_ids JSON,
    suggested_action TEXT,
    priority ENUM('high','medium','low') NOT NULL DEFAULT 'medium',
    status ENUM('open','addressed','dismissed') NOT NULL DEFAULT 'open',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_run (run_id),
    INDEX idx_status (status),
    INDEX idx_priority (priority),
    CONSTRAINT fk_suggestions_runs FOREIGN KEY (run_id) REFERENCES analysis_runs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
