-- ============================================================
-- RAG Chatbot Demo - Database Schema
-- ============================================================
-- 文字コード: utf8mb4
-- エンジン: InnoDB（基本） / MyISAM（FULLTEXT検索用テーブル）

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;

-- ============================================================
-- documents: アップロードされた元文書
-- ============================================================
CREATE TABLE IF NOT EXISTS documents (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid VARCHAR(36) NOT NULL UNIQUE,
    title VARCHAR(500) NOT NULL,
    original_filename VARCHAR(500) NOT NULL,
    storage_path VARCHAR(1000) NOT NULL,
    file_type VARCHAR(50) NOT NULL,        -- pdf, docx, xlsx, txt, md, url
    source_type VARCHAR(50) DEFAULT NULL,  -- 取込元種別: obsidian(vault同期) / upload / notion / crawl
    source_ref VARCHAR(1000) DEFAULT NULL, -- 取込元識別子: vault同期なら相対パス（増分同期の突き合わせキー）
    source_hash CHAR(40) DEFAULT NULL,     -- 取込時の内容sha1（変更検知用）
    file_size_bytes BIGINT UNSIGNED DEFAULT 0,
    category VARCHAR(100) DEFAULT NULL,
    tags VARCHAR(500) DEFAULT NULL,
    status ENUM('uploaded','processing','indexed','failed') NOT NULL DEFAULT 'uploaded',
    error_message TEXT,
    full_text LONGTEXT,                    -- 抽出全文（全文書RAG: ヒット文書を丸ごとLLMへ渡す用）
    chunk_count INT UNSIGNED DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_source_type (source_type),
    INDEX idx_category (category),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- chunks: 文書チャンク（FULLTEXT検索可、BM25代替）
-- ============================================================
CREATE TABLE IF NOT EXISTS chunks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    document_id BIGINT UNSIGNED NOT NULL,
    chunk_index INT UNSIGNED NOT NULL,
    text MEDIUMTEXT NOT NULL,
    context_text MEDIUMTEXT,               -- Contextual Chunking用の文脈付与テキスト
    token_count INT UNSIGNED DEFAULT 0,
    vector_id VARCHAR(100),                -- ベクトル済みマーカー / 外部ベクトルDBのpoint ID
    embedding LONGTEXT,                    -- DB内ベクトル検索用: float配列をJSON文字列で保存
    embedding_dim SMALLINT UNSIGNED,       -- ベクトル次元数（検索時の次元一致フィルタ用）
    embedding_model VARCHAR(100),          -- 生成に使ったモデル名（差し替え時の再ベクトル化判定）
    metadata JSON,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_document (document_id),
    INDEX idx_vector_id (vector_id),
    INDEX idx_embedding_dim (embedding_dim),
    FULLTEXT KEY ft_text (text, context_text),
    CONSTRAINT fk_chunks_documents FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- conversations: 会話セッション
-- ============================================================
CREATE TABLE IF NOT EXISTS conversations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_uuid VARCHAR(36) NOT NULL UNIQUE,
    user_identifier VARCHAR(100) DEFAULT NULL,  -- 匿名訪問者ID（cookie）
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ended_at DATETIME DEFAULT NULL,
    message_count INT UNSIGNED DEFAULT 0,
    resolved TINYINT(1) DEFAULT NULL,          -- NULL=未確認, 1=解決, 0=未解決
    escalated_to_human TINYINT(1) DEFAULT 0,
    metadata JSON,
    INDEX idx_session (session_uuid),
    INDEX idx_started (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- messages: 個別メッセージ
-- ============================================================
CREATE TABLE IF NOT EXISTS messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    conversation_id BIGINT UNSIGNED NOT NULL,
    role ENUM('user','assistant','system') NOT NULL,
    content MEDIUMTEXT NOT NULL,
    citations JSON,                            -- [{chunk_id, document_id, text, start, end}, ...]
    retrieved_chunks JSON,                     -- どのチャンクを使ったか
    confidence_score DECIMAL(4,3) DEFAULT NULL,
    response_type ENUM('answer','refusal','clarification','escalation') DEFAULT 'answer',
    latency_ms INT UNSIGNED DEFAULT NULL,
    token_usage JSON,                          -- {input, output, total}
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_conversation (conversation_id),
    INDEX idx_created (created_at),
    CONSTRAINT fk_messages_conversations FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- feedback: 👍👎・解決フィードバック
-- ============================================================
CREATE TABLE IF NOT EXISTS feedback (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    message_id BIGINT UNSIGNED NOT NULL,
    rating ENUM('up','down','resolved','unresolved') NOT NULL,
    comment TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_message (message_id),
    CONSTRAINT fk_feedback_messages FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- unanswered_clusters: 未回答質問クラスタ（運用ダッシュボード用）
-- ============================================================
CREATE TABLE IF NOT EXISTS unanswered_clusters (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cluster_label VARCHAR(500) NOT NULL,
    representative_question TEXT NOT NULL,
    question_count INT UNSIGNED DEFAULT 1,
    sample_message_ids JSON,
    suggested_action TEXT,
    status ENUM('open','addressed','dismissed') NOT NULL DEFAULT 'open',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_count (question_count)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- products: デモ用商品マスタ（製品プラン例）
-- ============================================================
CREATE TABLE IF NOT EXISTS products (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sku VARCHAR(100) NOT NULL UNIQUE,
    name VARCHAR(500) NOT NULL,
    category VARCHAR(100),
    price_monthly INT UNSIGNED DEFAULT 0,
    price_initial INT UNSIGNED DEFAULT 0,
    description TEXT,
    features JSON,
    image_url VARCHAR(1000),
    tags VARCHAR(500),
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_category (category),
    INDEX idx_active (is_active),
    FULLTEXT KEY ft_search (name, description)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- settings: アプリケーション設定（管理画面から編集可能）
-- ============================================================
CREATE TABLE IF NOT EXISTS settings (
    setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
    setting_value TEXT,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- analysis_runs: 改善分析の実行履歴（管理画面ボタンで都度実行）
-- ============================================================
CREATE TABLE IF NOT EXISTS analysis_runs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    period_days INT UNSIGNED NOT NULL DEFAULT 30,
    question_count INT UNSIGNED DEFAULT 0,      -- 分析対象とした質問数
    feedback_count INT UNSIGNED DEFAULT 0,      -- 分析対象としたフィードバック数
    topic_count INT UNSIGNED DEFAULT 0,         -- 生成された改善トピック数
    llm_provider VARCHAR(50),
    llm_model VARCHAR(100),
    token_usage JSON,                           -- {input, output}
    status ENUM('running','completed','failed') NOT NULL DEFAULT 'running',
    error_message TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- improvement_suggestions: 分析が出したトピック別の改善案
-- ============================================================
CREATE TABLE IF NOT EXISTS improvement_suggestions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    run_id BIGINT UNSIGNED NOT NULL,
    topic_label VARCHAR(500) NOT NULL,          -- 例: 派遣法
    representative_question TEXT,               -- 代表的な質問
    question_count INT UNSIGNED DEFAULT 0,      -- そのトピックの質問件数
    unresolved_count INT UNSIGNED DEFAULT 0,    -- うち未解決件数
    sample_message_ids JSON,                    -- 根拠 messages.id 配列
    suggested_action TEXT,                      -- 改善案（例: 派遣法のナレッジ追加を推奨）
    priority ENUM('high','medium','low') NOT NULL DEFAULT 'medium',
    status ENUM('open','addressed','dismissed') NOT NULL DEFAULT 'open',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_run (run_id),
    INDEX idx_status (status),
    INDEX idx_priority (priority),
    CONSTRAINT fk_suggestions_runs FOREIGN KEY (run_id) REFERENCES analysis_runs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- トークン使用量の統一台帳（今月のご利用状況＝この合算 / 上限ガードも参照）。
-- kind: chat / embed_query / embed_ingest / compose / insight
-- estimated=1 は推定値（埋め込みはAPIがトークン数を返さないため文字数から推定）。
CREATE TABLE IF NOT EXISTS token_usage_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    kind VARCHAR(20) NOT NULL,                    -- 消費区分
    model VARCHAR(64) NULL,                        -- 使用モデル（分かる場合）
    tokens INT UNSIGNED NOT NULL DEFAULT 0,        -- 消費トークン
    estimated TINYINT(1) NOT NULL DEFAULT 0,       -- 1=推定（埋め込み）/ 0=実測（LLM生成）
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_created_at (created_at),
    KEY idx_kind_created (kind, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 初期設定値（LLM/Embedding設定は管理画面から編集可能）
INSERT INTO settings (setting_key, setting_value) VALUES
    -- ★汎用製品としての中立な既定。納品時に管理画面→設定で顧客ごとに変更する
    --   （product_name=ボット名 / welcome_message=あいさつ / opening_quick_replies=候補質問 / topic_description=回答範囲）。
    --   HRデモ用の表示に一括で戻したい場合は sql/_seed_hr_domain.sql を実行。
    ('product_name', 'AIアシスタント'),
    ('welcome_message', 'こんにちは！ご質問を入力してください。登録された資料をもとにお答えします。'),
    ('opening_quick_replies', '[]'),
    ('topic_description', 'このチャットボットは、登録された資料（マニュアル・FAQ・各種ドキュメント等）に基づいて質問に回答します。'),
    ('escalation_email', ''),
    -- サービス稼働フラグ（1=稼働 / 0=停止）。統括コンソール・管理画面のボタンで切替。
    -- .env の SERVICE_ACTIVE=0 は強制停止の上書き（DBより優先）。
    ('service_active', '1'),
    -- LLM プロバイダー設定（管理画面で上書き可、空なら .env の値を使う）
    -- ★既定は空＝.env（LLM_PROVIDER/GEMINI_MODEL 等）に委ねる。納品時は .env で決める運用。
    --   ローカルLLM等に切り替えたい場合のみ管理画面で設定する。
    ('llm_provider', ''),
    ('llm_model', ''),
    ('llm_api_key_encrypted', ''),
    ('llm_temperature', '0.3'),
    ('llm_max_tokens', '8192'),
    ('llm_base_url', ''),
    -- Embedding プロバイダー設定（空なら .env の EMBEDDING_PROVIDER を使う）
    ('embedding_provider', ''),
    ('embedding_model', ''),
    ('embedding_api_key_encrypted', ''),
    -- Vector DB
    ('vector_db_enabled', '0'),
    ('qdrant_url', ''),
    ('qdrant_api_key_encrypted', ''),
    ('qdrant_collection', 'rag_chunks'),
    -- Rerank
    ('rerank_provider', 'none'),
    ('rerank_api_key_encrypted', ''),
    -- RAG チューニング（全文書RAG: 小規模ナレッジ向けに広めに取る）
    -- リランク保持チャンク数 / 文脈に入れる最大文書数 / 文脈の最大文字数
    ('rag_top_k_rerank', '10'),
    ('rag_fulldoc_max_docs', '5'),
    ('rag_context_max_chars', '40000'),
    -- 適応的ルーティング: 簡単な質問=プライマリ(高速) / 複雑な質問=セカンダリ(高精度・低速)
    -- llm2_* が未設定ならルーティングは自動的に無効（プライマリ単独運用）
    ('llm_routing_enabled', '1'),
    ('llm2_provider', ''),
    ('llm2_model', ''),
    ('llm2_base_url', ''),
    ('llm2_api_key_encrypted', '');

SET FOREIGN_KEY_CHECKS=1;
