-- ============================================================
-- migration: 全文書RAG（parent-document retrieval）用
-- documents に抽出全文を保持する full_text 列を追加する。
-- 検索でヒット文書を特定し、その文書を丸ごとLLMへ渡すために使う。
-- ============================================================

ALTER TABLE documents
    ADD COLUMN full_text LONGTEXT NULL AFTER error_message;
