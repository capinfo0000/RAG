-- ============================================================
-- migration_source: ナレッジ源(Obsidian等)の追跡カラムを documents に追加
-- ============================================================
-- source_type : 取込元の種別（例: 'obsidian', 'upload', 'notion', 'crawl'）
-- source_ref  : 取込元での識別子（Obsidianなら vault からの相対パス）
-- source_hash : 取込時点の内容ハッシュ（sha1）。増分同期の変更検知に使う
-- ------------------------------------------------------------
-- MariaDB 10.4+ は ADD COLUMN / INDEX の IF NOT EXISTS をサポート

ALTER TABLE documents
    ADD COLUMN IF NOT EXISTS source_type VARCHAR(50)   DEFAULT NULL AFTER file_type,
    ADD COLUMN IF NOT EXISTS source_ref  VARCHAR(1000) DEFAULT NULL AFTER source_type,
    ADD COLUMN IF NOT EXISTS source_hash CHAR(40)      DEFAULT NULL AFTER source_ref;

ALTER TABLE documents
    ADD INDEX IF NOT EXISTS idx_source_type (source_type);
