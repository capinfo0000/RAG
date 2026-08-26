# ナレッジ自動同期（②フォルダ同期）セットアップ手順

顧客サーバ（CoreServer V2 等の共用ホスティング）に本製品を設置したあと、
「サーバ上の Markdown フォルダ（vault）に `.md` を置くだけでナレッジが反映される」
②フォルダ同期メイン運用を有効にするための手順書。**導入案件ごとに使い回す前提**で、
ドメイン名・パス・トークン等の「案件ごとに変わる値」はプレースホルダにしてある。

> 関連: 設置全体は `DELIVERY.md`。本書は「同期・CRON」に特化した実務手順。

---

## 0. なぜ「Web経由同期（sync-run.php）」なのか（重要・背景）

当初は CLI で `php scripts/sync_obsidian.php` を CRON 実行する想定だったが、
**共用ホスティングでは CLI の PHP に PDO 拡張が無い／CLIのPHPバージョンが古い**ことがあり、
CLI 実行が失敗するケースがある（CoreServer V2 で実測: `/usr/local/bin/php` は旧版で parse error、
`/opt/alt/php83/usr/bin/php` は `Class "PDO" not found`）。

一方 **Web 側（LiteSpeed の lsphp 等）は PDO を持つ**（サイトが動いている＝DB接続できている）。
そこで **Web からHTTPで同期を叩く**方式を採用する。CoreServer 公式の CRON 例
（`curl --silent http://.../cron.php`）と同じ考え方で、CLI の PHP 事情に依存せず確実。

- 同期の実体トリガ: `public/sync-run.php`（トークン保護のHTTPエンドポイント）
- 定期実行: CRON から `curl` で `sync-run.php` を叩く

> 補足: 設置先の CLI PHP に PDO がある環境（専用サーバ等）なら、従来どおり
> `cd <APP_DIR> && <php8.x> scripts/sync_obsidian.php` を CRON 実行してもよい。
> 迷ったら Web 経由（sync-run.php）が無難。

---

## 1. 案件ごとに変わる値（プレースホルダ）

| プレースホルダ | 意味 | 例（今回の検証環境） |
|---|---|---|
| `<HOST>` | 公開ホスト名（RAGを設置したサブドメイン等） | `rag.engineer.v2008.coreserver.jp` |
| `<HOME>` | サーバのホームディレクトリ絶対パス | `/home/engineer` |
| `<APP_DIR>` | アプリのルート（公開ルートは `<APP_DIR>/public`） | `<HOME>/domains/<HOST>/RAG_coreserver_deploy/app` |
| `<TOKEN>` | sync-run.php のアクセストークン（**案件ごとに新規生成**） | （下記コマンドで生成） |
| `<PHP_CLI>` | CLI実行する場合のPHP8.xバイナリ（Web経由なら不要） | 環境依存（例 `/opt/alt/php83/usr/bin/php`） |

- `<HOME>` は CoreServer なら「ツール > CRONジョブ > CRON JOB作成」画面のコマンド欄プレースホルダに表示される（例 `/home/xxxx`）。
- docroot（公開ルート）は `<APP_DIR>/public` に設定しておくこと（DELIVERY.md 参照）。

---

## 2. セットアップ手順

### 2-1. vault フォルダを用意
`<APP_DIR>/vault/` を作成し、そこに回答源の `.md` を置く（公開ルート `public/` の外なのでWeb直アクセス不可＝安全）。

- `.md` のみ同期対象。サブフォルダ可。
- **`README.md` と `_` 始まりの `.md` は同期対象外**（案内・下書き用。`ObsidianVaultSource::SKIP_FILES` と先頭 `_` で除外）。

### 2-2. `.env` に vault パスを設定
```
OBSIDIAN_VAULT_PATH=vault
```
- ※ Web経由(sync-run.php)では **sync-run.php 内で `__DIR__ . '/../vault'` の絶対パスを渡す**ため、
  実は `.env` の相対値に依存しない（相対 `vault` は実行時CWDに依存し、Web実行だと解決に失敗するため絶対指定にしている）。
- CLI実行併用する場合のみ、CRONで `cd <APP_DIR>` してから実行すれば相対 `vault` も解決する。

### 2-3. `sync-run.php` を設置（トークンを新規生成）
`public/sync-run.php` を公開ルートに置く。**トークンは案件ごとに必ず新規生成**する。

トークン生成（サーバ or ローカルのPHPで）:
```
php -r 'echo rtrim(strtr(base64_encode(random_bytes(24)),"+/","AB"),"=");'
```
`public/sync-run.php` の `$TOKEN = '...'` を生成値に差し替える。
（このファイルはトークン直書きのため **公開Gitリポジトリには含めない**＝`.gitignore` に `/public/sync-run.php` 済み）

`sync-run.php` の要点（同梱済み）:
- `?token=<TOKEN>` が一致しなければ 403
- 一致すれば `ObsidianVaultSource::fromConfig(__DIR__.'/../vault')->sync()` を実行
- 結果を `OK added=.. updated=.. deleted=.. unchanged=..` で返す

### 2-4. 動作確認（即時同期）
ブラウザで開く（または `curl`）:
```
https://<HOST>/sync-run.php?token=<TOKEN>
```
→ `OK added=N ...` が出れば成功。`/chat/` で vault に入れた内容を質問し、出典付きで答えるか検収。

### 2-5. CRON で自動同期
CoreServer:「ツール > CRONジョブ > CRON JOBを作成」。

- スケジュール（分/時/日/月/曜日）: 例 `*/30 * * * *`（30分ごと）
- コマンド:
```
curl -s "https://<HOST>/sync-run.php?token=<TOKEN>" >> <HOME>/sync.log 2>&1
```
- 実行ログは `<HOME>/sync.log` に追記される（成否確認用）。

---

## 3. 日常のナレッジ運用（納品後）

| やること | 操作 |
|---|---|
| 追加 | `<APP_DIR>/vault/` に `.md` を置く |
| 更新 | 対象 `.md` を上書き（内容が変わると自動で再取込） |
| 削除 | `.md` を消す（次回同期でDBからも削除＝孤立除去） |
| 反映 | CRONで自動（例:30分毎）／急ぎは `https://<HOST>/sync-run.php?token=<TOKEN>` を開く |
| PDF/Word/Excel | 管理画面 `/admin/` からアップロード（②は`.md`専用） |

**注意**: vault が「正」。フォルダを空にして同期するとDBの該当ナレッジ（`source_type=obsidian`）も消える。
vault と DB の定期バックアップ（`mysqldump`）を推奨。

---

## 4. トラブルシューティング（実測ベース）

| 症状 | 原因 | 対処 |
|---|---|---|
| `sync.log` に `PHP Parse error: unexpected '=>'` | CLIのPHPが古い（7.x等） | CLIは使わずWeb経由(sync-run.php)にする |
| `Class "PDO" not found` | CLIのPHPにPDO拡張が無い | 同上（Web/lsphpはPDOあり） |
| `Obsidian vault が見つかりません: vault` | `OBSIDIAN_VAULT_PATH` が相対で実行時CWDと不一致 | sync-run.php は `__DIR__.'/../vault'` で絶対指定済（要確認） |
| 公開URLで `Access denied for user 'root'` | `.env` 未読込（ファイル名が `.env` でない等） | `.env` の名前・設置場所を確認 |
| README.md 等が回答に混じる | 旧仕様で全 `.md` を取込 | 現仕様は README / `_` 始まりを除外。`ObsidianVaultSource.php` を最新に |
| 既存シードが消えた/残った | シードの `source_type` 次第 | `source_type=obsidian` のdocのみ同期管理・孤立削除される |

CLI PHP のパス調査（必要時、CRONに一時設定して `sync.log` で確認）:
```
{ for p in /usr/local/bin/php /usr/local/bin/php8.3 /opt/alt/php83/usr/bin/php /usr/local/lsws/lsphp83/bin/php; do [ -x "$p" ] && printf "%s | %s | pdo=[%s]\n" "$p" "$($p -v 2>/dev/null|head -1)" "$($p -m 2>/dev/null|grep -i pdo|tr '\n' ',')"; done; } >> <HOME>/sync.log 2>&1
```

---

## 5. セキュリティ

- **トークンは案件ごとに新規生成**し、`sync-run.php` に直書き（PHP実行されるのでソースは露出しない）。
- `sync-run.php` は **公開Gitに含めない**（`.gitignore` 済み）。
- トークンは URL クエリに乗る＝アクセスログに残る。より厳格にするなら:
  - トークンを `.env`（`Config::get('SYNC_TOKEN')`）から読む方式に変更、または
  - admin ログインセッションでゲート（管理画面からのみ実行可）に変更。
- 同期は「サーバ上の `.md` を読んでDBへ入れる」だけで外部送信はしないが、
  埋め込み時に Embedding API（Gemini等）を消費する点に留意（大量一括投入時は上限注意）。
