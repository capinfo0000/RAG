-- ============================================================
-- migration_vector: チャンクにベクトル（embedding）を保持する
-- ============================================================
-- DB内ベクトル検索（外部サービス不要のコサイン類似度）を有効化するための列。
-- 外部ベクトルDB(Qdrant等)を使わず、共用PHP+MySQL環境でセマンティック検索を成立させる。
--
--   embedding       : float配列を JSON 文字列で保存（例: "[0.01,-0.02,...]"）
--   embedding_dim   : 次元数（検索時にクエリ側と一致する行だけを比較するため）
--   embedding_model : 生成に使ったモデル名（モデル差し替え時の再ベクトル化判定用）
--
-- 既存の vector_id 列は「ベクトル済みマーカー / 将来の外部DB point ID」として温存。
-- ------------------------------------------------------------
-- MariaDB 10.4+ / MySQL 8.0+ は ADD COLUMN IF NOT EXISTS をサポート
-- （MySQL 8 で未対応の場合は IF NOT EXISTS を外して一度だけ実行すること）

ALTER TABLE chunks
    ADD COLUMN IF NOT EXISTS embedding       LONGTEXT           DEFAULT NULL AFTER vector_id,
    ADD COLUMN IF NOT EXISTS embedding_dim   SMALLINT UNSIGNED  DEFAULT NULL AFTER embedding,
    ADD COLUMN IF NOT EXISTS embedding_model VARCHAR(100)       DEFAULT NULL AFTER embedding_dim;

-- 次元でフィルタして候補を絞るための軽いインデックス
ALTER TABLE chunks
    ADD INDEX IF NOT EXISTS idx_embedding_dim (embedding_dim);
