# 納品手順書（DELIVERY）

埋め込みAIアシスタント（Obsidian連携・顧客サーバ設置型）を、顧客に**ベンダー全代行**で導入するための手順。
顧客の作業は原則 **「ナレッジ提供」「LLM方針の決定」「検収」の3点のみ**。設置・設定・埋め込み1行はベンダーが実施する。

---

## 0. 導入時の役割分担（サマリ）

| 誰が | 何を |
|---|---|
| **顧客** | ①回答源ナレッジ(Markdown/PDF/Word等)の提供 ②LLM方針の決定(社外秘でローカル必須か等) ③検収 |
| **ベンダー** | サーバ設置・DB作成・`.env`設定・ドメイン設定・LLM設定・vault同期・埋め込み`<script>`貼付・動作確認 |

---

## 1. サーバ要件（顧客サーバ / Coreserver・Xサーバ等の共用でも可）

- PHP **8.2 以上**（ディレクトリ単位でPHPバージョンを選べるホスティングなら、アプリ設置ディレクトリを8.2+に）
- MySQL / MariaDB（アプリ専用DBを1つ。WordPress本体のDBとは分ける）
- 必要なPHP拡張: `pdo_mysql` `mbstring` `openssl` `curl` `gd` `zip` `fileinfo`
- 書き込み可能な `storage/`（uploads/logs）
- ※ **LLM推論はサーバ上では動かない**（共用サーバにGPU無し）。生成は外部委譲（後述）

---

## 2. 設置

1. アプリ一式を設置（例 `public_html/rag/`）。`vendor/` は**ローカルで `composer install` 済みのものを含めてアップロード**（共用サーバでcomposer不可でもよい）。
2. 公開ルートは `public/`。`src/` `storage/` `.env` は公開ディレクトリ外、または `.htaccess` で直接アクセス禁止にする。
3. DB作成＋スキーマ投入:
   ```bash
   mysql -u <user> -p <dbname> < sql/schema.sql
   mysql -u <user> -p <dbname> < sql/migration_fulltext.sql
   mysql -u <user> -p <dbname> < sql/migration_insights.sql
   mysql -u <user> -p <dbname> < sql/migration_source.sql   # Obsidian連携用
   ```

---

## 3. `.env` 設定（ベンダーが記入。最小構成）

```env
APP_NAME="（ボット名の内部識別）"
APP_ENV=production
APP_DEBUG=false

# DB
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=<dbname>
DB_USER=<user>
DB_PASSWORD=<password>

# --- LLM（ヒアリングで決定。下記いずれか） ---
# (A) クラウドAPI（例: Groq無料枠 / Gemini無料枠）
LLM_PROVIDER=groq
GROQ_API_KEY=<key>
GROQ_MODEL=llama-3.3-70b-versatile
# (B) ローカルLLM委譲（顧客PC+Cloudflare Tunnel / Oracle Free VM 等。OpenAI互換)
# LLM_PROVIDER=ollama
# OLLAMA_BASE_URL=https://<公開エンドポイント>/v1
# OLLAMA_API_KEY=<bearer token>
# OLLAMA_MODEL=<model>

# 埋め込み（BM25のみで運用するなら none。ベクトル検索する場合は ollama 等）
EMBEDDING_PROVIDER=none

# Obsidian vault（回答源。サーバ上のパス）
OBSIDIAN_VAULT_PATH=/path/to/vault

# 埋め込み許可（クロスオリジンiframe時のみ。同一オリジン設置なら未設定でよい）
# CHAT_FRAME_ANCESTORS="https://customer.example.com"

# 管理ログイン（下記コマンドで生成した値を貼る）
ADMIN_USERNAME=admin
ADMIN_PASSWORD_HASH=<password_hash>

# 暗号化キー（DBに保存するAPIキー等をAES-256-GCMで暗号化。サーバごとに新規生成）
APP_ENCRYPTION_KEY=<base64:...>

# ガードレール（業種に合わせて範囲を定義）
GUARD_ENABLED=true
GUARD_TOPIC_DESCRIPTION="（例）当店のメニュー・営業時間・予約に関する質問にのみ回答します。"
```

**秘密情報の生成コマンド:**
```bash
# 管理パスワードのハッシュ
php -r "echo password_hash('（強いパスワード）', PASSWORD_DEFAULT), PHP_EOL;"
# 暗号化キー
php -r "require 'vendor/autoload.php'; App\Config::load('.'); echo App\Models\Settings::generateEncryptionKey().PHP_EOL;"
```
※ `.env` は**絶対にリポジトリ/バックアップ/共有で漏らさない**。APIキーはヒアリングで顧客から預かるか、提案用に無料枠で発行。

---

## 4. 業種に合わせたドメイン設定（管理画面 or `.env`）

同じエンジンで多業種に対応。業種差は次の設定のみ（**ベンダーが納品時に設定**、顧客は変更しない）:

| 設定 | 例（社内規程） | 例（飲食店） | 例（アニメ作品） |
|---|---|---|---|
| ボット名 `product_name` | 社内ヘルプAI | 〇〇店 案内AI | 〇〇 作品ガイド |
| あいさつ `welcome_message` | 社内文書についてご案内します | ご来店ありがとうございます | 作品について案内します |
| クイックリプライ `opening_quick_replies` | 有給/経費/規程… | メニュー/営業時間/予約 | あらすじ/登場人物… |
| ガードレール `GUARD_TOPIC_DESCRIPTION` | 社内文書に関する質問のみ | メニュー等に関する質問のみ | 作品〇〇に関する質問のみ |

→ ガードレールにより範囲外質問（例: 飲食店Botに天気を聞く）は「〜に関する質問のみお答えします」と適切に断る。

---

## 5. ナレッジ投入（サーバ上のMarkdownフォルダ ＝ 実体。Obsidianは任意）

`OBSIDIAN_VAULT_PATH` は**サーバ上のMarkdownフォルダ**を指すだけで、Obsidianアプリの導入は不要。
（顧客がObsidianで執筆したい場合のみ §8① の同期を仕込む。）

**データ忠実性の方針**: 取込時、文章・数字・条文などの**内容は一切改変しない**。
除去するのは「YAMLフロントマター（メタ情報）」「`[[wikilink]]`の括弧」「BOM・末尾空白」等の
**Obsidian記法/整形のみ**。回答は文書全文（`documents.full_text`）を根拠に生成するため、
ボットの知識＝元`.md`の内容と一致する（＝方針A）。フロントマター等も原文のまま残したい場合は
`cleanBody()` の整形を無効化する拡張が可能（ただし回答にメタ情報が混じる）。

1. 顧客から受領した文書を vault フォルダ（`OBSIDIAN_VAULT_PATH`）に配置（`.md` 推奨。PDF/Word/Excelもアップロード取込可）。
2. 同期:
   ```bash
   php scripts/sync_obsidian.php            # .env の OBSIDIAN_VAULT_PATH を使用
   php scripts/sync_obsidian.php /path/vault # パス明示も可
   ```
3. 定期反映は cron:
   ```
   */30 * * * * cd /path/to/rag && php scripts/sync_obsidian.php >> storage/logs/sync.log 2>&1
   ```

---

## 6. 埋め込み（顧客HPに1行。ベンダーが代行可）

顧客サイト（WordPress等どのCMSでも可）の任意ページに以下を1行追加:
```html
<script src="https://<顧客ホスト>/rag/public/embed.js"
        data-title="社内ヘルプAI" data-color="#2563eb"></script>
```
- `data-title` ボタン/ヘッダ名、`data-color` アクセント色、`data-position` "right"/"left"、`data-src` チャットURL明示（省略時は embed.js と同階層の `chat/?embed=1`）。
- WordPress: フッター（`footer.php`）/「カスタムHTML」ブロック/WPCode等で貼付。
- 仕組み: RAGホスト上のチャットページを iframe 読み込み → API通信は同一オリジンで **CORS不要・顧客CSSと非干渉**。

---

## 7. 検収（動作確認）

- [ ] `https://<ホスト>/rag/public/` でチャットが開く
- [ ] 管理画面 `/rag/public/admin/` に設定した管理者でログインできる（`admin/admin`では入れない）
- [ ] 管理画面でLLM接続テストが成功する
- [ ] サンプル質問で**出典付き**回答が返る
- [ ] 範囲外質問が適切に断られる（ガードレール）
- [ ] 顧客HP上の埋め込みボタン→チャットが動作
- [ ] （ローカルLLM委譲の場合）委譲先が稼働・到達可能

---

## 8. ナレッジ更新の運用・課金（2ティア）

**大前提**: 回答源の実体は「サーバ上のMarkdownフォルダ」。**Obsidianアプリの利用は任意**。
初期設定・初期データ投入は**どちらのティアでもベンダーが全て案内・構築して納品**する（顧客を最初から独りにしない）。

### ① 買い切り（価格高め）＝ 顧客が以降を自己管理
- 納品時: ベンダーがサーバ設置・設定・初期ナレッジ投入まで実施し、運用手順を案内。
- 以降の追加: 顧客が自分で行う。サーバへ置く手段は以下2パターンから選択（納品時にベンダーが用意）:
  - **パターンA: Obsidian自動同期** → 顧客がObsidian(+Gitプラグイン)でpush、サーバは `scripts/pull_and_sync.sh` を cron 実行（`git pull`＋`sync_obsidian.php`）。顧客は「書くだけで自動反映」。
  - **パターンB: サーバ格納** → `.md` を SFTP 等でサーバに直接配置し、`scripts/pull_and_sync.sh`（git管理外ならpullは自動スキップ）or `sync_obsidian.php` で反映。
  - ※コンテンツ追加用のWeb画面は提供しない方針。
- 向く顧客: 技術リテラシー高め。

### ② サブスク（初期費用安く＋月額）＝ ベンダーが更新代行
- 顧客は追加したい情報を**メモ/メール等で送るだけ**。
- ベンダーが `.md` 化（Obsidian/任意エディタ）→ **顧客確認** → サーバ格納 → 同期。
- 「確認後に格納」で品質担保・責任範囲を明確化。月◯件/都度課金として契約に落とす。
- 向く顧客: 手間をかけたくない多数派。

> Obsidianを実際に使うのは①で顧客が執筆を選んだ場合のみ。標準構成ではObsidianアプリは不要（サーバ上の`.md`を読むだけ）。

---

## 9. Go-Live セキュリティ・チェックリスト（`REVIEW.md` 準拠）

導入済みコードで概ね対応済み。公開前に**設定値だけ**要確認:
- [ ] `.env` の `ADMIN_PASSWORD_HASH` を強いパスワードで設定（未設定だとfail-loudで誰もログイン不可＝安全側）
- [ ] `APP_ENCRYPTION_KEY` を新規生成値に設定（未設定/不正だとfail-loudで例外）
- [ ] `.env`・`src/`・`storage/` が公開ディレクトリから直接アクセスできない
- [ ] 提案検証用に発行したAPIキーは無料枠・低上限、納品後は本番キーへ差替
- [ ] HTTPS で提供（管理cookieの secure 判定はリバプロ対応済み）
- [ ] （確認）chat応答のエラーはユーザーに汎用文言のみ（APIキー非漏洩）

---

## 10. ローカルLLM運用時の推奨スペック（★顧客説明必須）

**ローカルLLMを希望する顧客には、導入前に必ず次を伝えること:**
「**回答の速度と精度は、動かすPCのスペック（特にGPU/VRAM）で大きく変わります**」。
安価なPCやGPU無しでは、遅すぎる／回答がずれる（出典を守れない）ため実用になりません。

### スペック別の目安（日本語RAG・出典付き回答）

> 方針: **後から「遅い・精度が低い」と言われないよう、ローカルは★推奨（下記）を満たす場合のみ提案**する。
> ★推奨を満たさない環境（GPU無し等）では**ローカル運用は勧めず、クラウドAPI（無料枠）を案内**すること。

| ティア | GPU / VRAM | システムRAM | 推奨モデル | 用途・精度 |
|---|---|---|---|---|
| **★推奨（ローカルはこの要件で提案）** | VRAM **48GB以上**（RTX A6000 / L40S / GPU複数） | **64〜128GB** | 32B〜70B | 高精度・多数同時・将来拡張に**長期安心**。クレームが出にくい |
| Mac代替 | Apple Silicon 統合メモリ **64GB以上**（M3/M4 Max） | （統合） | 32B | GPU無しWindowsの代替 |
| 上記未満 | ★推奨を満たさない（GPU無し等） | — | — | **ローカル不可** → クラウドAPI（無料枠）を案内 |

**共通要件**: CPU 8コア以上（Core i7 / Ryzen 7 相当以降）、**NVMe SSD**（モデル用に空き50GB以上）、ランタイムは Ollama / LM Studio 等。

**この推奨を高めに設定している理由**（顧客に説明できるように）:
- 出典付きの安定回答には**7B超（実用は14B〜32B）**が必要（3B級は破綻＝実測済み）。
- RAGは**大きな文脈（最大約24,000字）**をLLMへ渡すため、KVキャッシュ用にVRAMの余裕が要る。
- 埋め込みウィジェットは**複数ユーザーの同時アクセス**があり得るため、VRAM/GPUに余裕がないと待ち行列で遅くなる。
- ナレッジ量・利用者数は**将来増える**前提で、初期から余裕を見ておく方がクレームを防げる。

### 顧客に必ず伝える技術ポイント
- **モデルはVRAMに載るサイズを選ぶ**。載りきらないとCPUに退避して激遅になる。
- **RAGは大きな文脈（本製品は最大約24,000字）をLLMへ渡す**ため、VRAMに余裕（KVキャッシュ分）が必要。
- **日本語は Qwen2.5系 または 日本語チューニング済みモデル**を推奨。
- **3B級は出典遵守が不安定**（下記実測でハルシネーション・出典0）。**7B以上**を推奨。

### 実測根拠（本プロジェクトでの検証・2026-07-03）
- **GPU無しノート（Core i7-8650U / GPU無し / RAM 32GB）× Qwen2.5 3B**: 生成 約2.4 tok/秒、1回答 **97〜120秒**、**出典0件・ハルシネーション・中国語混入** → **実用不可**。
- **社内LAN機（AMD Ryzen AI 9 HX 370 / RTX 3090 24GB + RX 7900 XT 20GB / RAM 128GB）× 35B（qwen3.6-35b-a3b, Q4）**: 正確・出典付き・良好な日本語。ただし **約5分/回答** と遅い。**原因はハード不足ではなく設定**（コンテキストを128Kでロード→巨大なKVキャッシュが24GB VRAMを溢れてCPU退避、および混在GPU環境でRTX 3090が使われていない可能性）。**コンテキストを8〜32Kに絞りRTX 3090(CUDA)へフルオフロードすれば数秒に短縮可能** → オンプレでも「高速・高品質・データ主権」を三立できる好例。
- **クラウド（Gemini 2.5 Flash 無料枠）**: 正確・出典付き・**約35秒** → 手軽さ重視ならこちら。

> まとめ: **「データ主権（社外に出さない）を取るならGPU付きオンプレ機が必須」**。GPUに投資できないなら、素直にクラウドAPI（無料枠）を勧めるのが顧客のためになる。プロバイダ抽象化により後からの乗り換えも容易。
