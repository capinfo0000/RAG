# 引き継ぎメモ（自分用・持ち帰り情報）

最終更新: 2026-07-03 / このファイルは .gitignore 対象（ローカルのみ・コミットされない）

## 1. 置き場所
- アプリ: `C:\Users\yonekura\Desktop\勉強会\RAG\demo`（git repo・branch `main`・12コミット）
- 計画書: `C:\Users\yonekura\.claude\plans\bright-hugging-wigderson.md`
- 納品書/スペック: `RAG\demo\DELIVERY.md`
- 提案資料: `RAG\00_README.md`〜`04_提案書骨子.md` / コードレビュー: `RAG\demo\REVIEW.md`

## 2. 起動手順（ローカル開発）
```bash
# PHPはXAMPPの8.2を使う（PATH先頭に追加）
export PATH="/c/xampp/php:$PATH"      # cmd/PSなら C:\xampp\php\php.exe を直接
# DB（MariaDB）起動
C:\xampp\mysql\bin\mysqld.exe --defaults-file=C:\xampp\mysql\bin\my.ini   # 別窓/バックグラウンド
# DBクライアント: C:\xampp\mysql\bin\mysql.exe -u root  （DB名 rag_chatbot / パスワード無）
# Webサーバ（demoディレクトリで）
php -S localhost:8080 -t public
#   公開チャット  : http://localhost:8080/chat/
#   管理画面      : http://localhost:8080/admin/
#   埋め込みデモ  : http://localhost:8080/embed-demo.html
```
- composer導入済み（`vendor/` あり）。php.iniで gd/zip を有効化済み（バックアップ: `C:\xampp\php\php.ini.bak_rag`）

## 3. 設定・秘密情報（.env と DB）
- `.env`（`RAG\demo\.env`・gitignore）… DB接続 / `GEMINI_API_KEY`(新AQキー) / `OLLAMA_*`(localhost:11434) / `ADMIN_PASSWORD_HASH` / `APP_ENCRYPTION_KEY`
- バックアップ: `.env.bak_proposal`(旧提案・旧キー), `.env.bak_ollama`（いずれもgitignore・**コミット厳禁**）
- **重要**: LLM設定は **DBの `settings` テーブルが .env より優先**される。切替はDBか管理画面で行う。

## 4. LLMの切替（プロバイダ抽象化）
現在は **Gemini**（`settings`: llm_provider=gemini / llm_model=gemini-2.5-flash）。
```sql
-- 例) LAN機のLM Studio(35B等)に戻す（再チューニング後）
UPDATE settings SET setting_value='ollama'                      WHERE setting_key='llm_provider';
UPDATE settings SET setting_value='http://192.168.0.3:1234/v1'  WHERE setting_key='llm_base_url';
UPDATE settings SET setting_value='<モデルID>'                  WHERE setting_key='llm_model';
-- 例) この端末のローカルOllama(qwen2.5:3b) … 遅い/低精度なので非推奨
--   llm_provider=ollama / llm_base_url=http://localhost:11434/v1 / llm_model=qwen2.5:3b
-- 例) Geminiに戻す … llm_provider=gemini / llm_model=gemini-2.5-flash / llm_base_url=''(空)
```
管理画面 `admin/settings.php` からもGUIで変更可（要adminログイン。パスワード不明なら
`php -r "echo password_hash('新パス', PASSWORD_DEFAULT);"` で生成→ .env の ADMIN_PASSWORD_HASH に貼る）。

## 5. ナレッジ操作
```bash
php scripts/sync_obsidian.php knowledge_samples/ocr      # 社内規程サンプルを同期
php scripts/sync_obsidian.php <vaultパス>                 # 任意フォルダ(.md)を同期(増分)
scripts/pull_and_sync.sh <vaultパス>                      # git pull + 同期（cron用）
```
- 回答源フォルダ: `knowledge_samples/ocr`（人事規程5本）, `knowledge_samples/restaurant`（飲食店デモ）
- 実体: サーバ上の `.md` フォルダ（Obsidianアプリは任意）。取込先はDB(documents/chunks)。

## 6. 検証コマンド
```bash
php scripts/test_local_llm.php            # LLM疎通＋速度
php scripts/test_e2e_chat.php "質問"      # RAGフル（出典付き回答）
php scripts/eval_rag.php <label>          # 検索精度 Recall@1/@3・MRR → eval/results_<label>.csv
php scripts/test_search.php "キーワード"  # BM25検索のみ
```

## 7. LAN機(MINIS_FORUM_AI)のLLM高速化
- スペック: Ryzen AI 9 HX 370 / RTX 3090(24GB)+RX7900XT(20GB) / RAM128GB
- 遅い原因: LM Studioが**コンテキスト128Kでロード**→KVキャッシュがVRAM溢れ→CPU退避（推定）
- 対処: そのPCで **Context 16K〜32K・RTX3090(CUDA)フルオフロード・Flash Attention ON** で再ロード
  （LM Studio再設定用の詳細プロンプトはチャット履歴に保存済み）
- 手っ取り早い高速化: **Qwen2.5 14B Instruct(Q4)** に変更（3090に余裕で載る）。変えたら上記SQLの
  `llm_model` を新IDに合わせる。

## 8. 今後のTODO（未完）
- [ ] LAN機のLM Studio再チューニング → 再計測（オンプレ高速の実データ取得）
- [ ] 発表資料（3者比較: LAN35B / この端末3B / Gemini ＋ 抜き差し3レイヤー ＋ 課金2ティア）
- [ ] Coreserverへ試験デプロイ
- [ ] （任意）精度Before/After用に大きめvault or ベクトル検索有効化
- [ ] （軽微）admin設定画面の接続テストのエラー表示サニタイズ

## 9. セキュリティ持ち出し注意
- `.env`（新Geminiキー含む）は絶対に公開/コミットしない
- 本番納品時は `ADMIN_PASSWORD_HASH` と `APP_ENCRYPTION_KEY` をサーバごとに新規生成
- 旧 `AIza…` キーは無効化済み（REVIEW.md CR-02）
