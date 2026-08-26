# RAG Chatbot (PHP Full Stack)

社内規程・制度・FAQ等のナレッジに、**出典付き**で答える埋め込み型RAGチャットボット。
顧客のHPに `<script>` 1行で設置でき、顧客サーバ設置型（共用PHP+MySQLでも動作）で運用する。

> 関連ドキュメント: `DELIVERY.md`（納品手順・多業種設定・課金2ティア・推奨スペック）、
> `../SESSION_LOG.md`（設計判断の全記録）

---

## 1. 技術スタック（現状の実装）

- **PHP 8.2+ / Composer**
- **MySQL 8.x / MariaDB 10.4+**（本文・チャンク・ベクトル・会話ログを保持）
- フロント: HTML + Tailwind (CDN) + Alpine.js (CDN)、Markdown描画は marked + DOMPurify
- **検索 = Hybrid（RRF統合）**
  - キーワード検索：日本語向け **n-gram LIKE**（MariaDBのFULLTEXTは日本語に弱いためアプリ側でn-gram化）
  - ベクトル検索：**DB内コサイン類似度**（`chunks.embedding` に格納・外部サービス不要）
    - `EMBEDDING_PROVIDER=none` ならキーワード検索のみで縮退（完全動作）
- **LLM（生成・ガードレール・クエリ書換・リランク）= マルチプロバイダ**
  - クラウド: Gemini / OpenAI / Anthropic / Groq、ローカル: Ollama・LM Studio・vLLM（OpenAI互換）
  - `.env` / 管理画面から動的切替。高精度側への適応ルーティングあり
- **Embedding = マルチプロバイダ**: Gemini / OpenAI / Voyage / Ollama
- **ナレッジ取込**: PDF / DOCX / XLSX / TXT / MD 抽出、Obsidian vault 増分同期、Gemini OCR
- 出典 [N]、confidence、フィードバック/アンケート、管理画面（設定・ナレッジ・インサイト・QAレビュー）

> 外部ベクトルDB（Qdrant等）や Cohere Rerank は**任意**。既定は上記の自己完結構成で動く。

## 2. ディレクトリ構成

```
demo/
├── public/                # Web公開ルート
│   ├── index.php          # ランディング
│   ├── chat/              # 公開チャットUI（?embed=1 で埋め込みモード）
│   ├── admin/             # 管理画面（要ログイン）
│   ├── api/               # chat.php(SSE) / feedback.php
│   ├── embed.js           # 埋め込みウィジェット・ローダ（1行設置）
│   └── assets/            # CSS/JS
├── src/                   # アプリケーションコード（公開外）
│   ├── Rag/               # HybridSearch / DbVectorSearch / Generator / Guardrail / Reranker ...
│   ├── Knowledge/         # 抽出・チャンキング・Indexer・Obsidian連携
│   ├── Embedding/         # Embeddingプロバイダ
│   ├── Llm/               # LLMプロバイダ
│   └── Models/            # DB操作
├── scripts/               # sync_obsidian / backfill_embeddings / eval_rag ...
├── sql/                   # schema.sql + migration_*.sql
├── knowledge_samples/     # サンプルナレッジ（人事規程 / 飲食店）
├── composer.json
├── .env.example
└── README.md
```

## 3. セットアップ

```bash
cd demo
composer install

# .env 作成（LLM/Embeddingプロバイダとキーを設定）
cp .env.example .env
#   クラウド既定: LLM_PROVIDER=gemini / EMBEDDING_PROVIDER=gemini（GEMINI_API_KEYを共用）
#   ローカル完結: LLM_PROVIDER=ollama / EMBEDDING_PROVIDER=ollama（LM Studio等）

# DB作成 + スキーマ適用
mysql -u root -e "CREATE DATABASE rag_chatbot CHARACTER SET utf8mb4;"
mysql -u root rag_chatbot < sql/schema.sql
# 既存DBに後から追加した列を入れる場合はマイグレーションも適用
for f in sql/migration_*.sql; do mysql -u root rag_chatbot < "$f"; done

# ナレッジ取込（例: 人事規程サンプル）
php scripts/sync_obsidian.php knowledge_samples/ocr

# ベクトル検索を後から有効化 / モデル変更した場合は再ベクトル化
php scripts/backfill_embeddings.php          # 未ベクトル化のみ
php scripts/backfill_embeddings.php --all      # 全再生成（モデル変更時）

# 開発サーバ起動
php -S localhost:8080 -t public
#   チャット   http://localhost:8080/chat/
#   管理画面   http://localhost:8080/admin/
#   埋め込みデモ http://localhost:8080/company-demo.html
```

## 4. 検索の仕組み（Hybrid + RRF）

1. クエリ書換（会話履歴で代名詞解消・検索クエリ化）
2. **キーワード検索**（n-gram LIKE）と **ベクトル検索**（DB内コサイン類似度）を並行実行
3. **RRF**（Reciprocal Rank Fusion, k=60）で統合 → 上位候補
4. リランク → 全文書(parent-document) retrieval → プロンプト構築 → LLMストリーム
5. 出典 [N] を構造化、confidence 算出

ベクトル検索は `chunks.embedding`（JSON配列）に対する総当りコサイン類似度。
数百〜数千チャンク規模を想定（`RAG_VECTOR_MAX_CANDIDATES` で上限調整）。大規模化時は
`VectorSearchInterface` を実装した外部バックエンド（Qdrant等）へ差し替え可能。

## 5. LLM / Embedding の切替

| 用途 | env | 例 |
|---|---|---|
| 生成LLM | `LLM_PROVIDER` | `gemini` / `openai` / `anthropic` / `groq` / `ollama` |
| 埋め込み | `EMBEDDING_PROVIDER` | `gemini` / `openai` / `voyage` / `ollama` / `none` |

- 管理画面（`/admin/settings.php`）からも変更可（DB設定が `.env` より優先）。APIキーは AES-256-GCM で暗号化保存。
- **クラウド既定**：手軽さ優先。データはLLM提供元へ渡る。
- **ローカル完結**：データ主権・課金ゼロ。GPU/VRAM等のスペックが必要（`DELIVERY.md` §推奨スペック参照）。

## 6. 導入・納品

- 顧客サーバ設置型。埋め込みは汎用 `<script src=".../embed.js">` 1行（iframe方式・CORS不要・CSS非干渉）。
- ナレッジ更新は Obsidian（Markdownフォルダ）を回答源に増分同期。Web投入画面は設けない。
- 詳細な納品手順・多業種設定・課金2ティア・推奨スペックは `DELIVERY.md` を参照。
