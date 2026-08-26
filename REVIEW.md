---
project: rag-chatbot-demo
reviewed: 2026-05-26
depth: standard+
files_reviewed: 41
status: issues_found
findings:
  critical: 6
  high: 9
  medium: 12
  low: 8
  total: 35
---

# RAG Chatbot Demo — コードレビュー

**目的:** クライアント提案デモ前の品質確保
**結論:** **デモ前に CRITICAL 6 件 + HIGH 9 件は必ず修正してください。** 特に APIキー漏洩（CR-01 / CR-02）、暗号化キーのフォールバック（CR-03）、認証フォールバック（CR-04）、ファイル物理削除の壊れたパスチェック（CR-05）、`feedback.php` の認可欠落（CR-06）はその場でデモ崩壊・情報漏洩につながります。

> 既知バグの再発確認（修正済み）: ✅ SQL プレースホルダ別名化、✅ `\openssl_*` の global namespace、✅ Guzzle CurlHandler 強制、✅ LlmFactory が Settings::effective 経由。本レビューでは追加の同種パターンは見つかりませんでした。

---

## デモ前に直すべき項目（優先度マップ）

| 優先 | 件数 | 対応目安 | 対応しないとどうなるか |
|------|------|---------|-----------------------|
| **CRITICAL** | 6 | 必須・1〜2時間 | 顧客の画面に API キーや内部スタックトレースが丸見えになる／管理画面が `admin/admin` で素通し |
| **HIGH** | 9 | 必須・2〜4時間 | コスト爆発・他人の feedback 改ざん・XSS・セッション固定 |
| **MEDIUM** | 12 | 推奨 | 提案後の不具合 |
| **LOW** | 8 | 余裕があれば | コード品質・保守性 |

---

# CRITICAL

## CR-01: LLMプロバイダー例外メッセージに APIキーを含む URL が漏洩する

**ファイル:**
- `src/Llm/GeminiProvider.php:343-344` （最重要）
- `src/Llm/OpenAiProvider.php:290-291`
- `src/Llm/AnthropicProvider.php:310-311`
- `src/Embedding/GeminiEmbedding.php:170-171`
- `src/Embedding/OpenAiEmbedding.php:175-176`
- `src/Embedding/VoyageEmbedding.php:154-155`

**問題:**
Gemini は URL 末尾に `?key=AIza...` でキーを乗せる。Guzzle の `RequestException::getMessage()` は失敗時に **リクエストURLを含む文字列** を返す（`cURL error 7: ... while requesting POST https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=AIzaSyC0-6JENhvUfczQwzylnbA9rdAojn4F-fQ`）。これが `wrapException()` で `$e->getMessage()` として詰められ、`LlmException::getMessage()` 経由で：

1. `public/api/chat.php:113` → `sse_send('error', ['message' => $e->getMessage()])` → **ブラウザに直接配信**
2. `admin/settings.php:63` → `'❌ LLM接続エラー: ' . $e->getMessage()` → **管理画面に表示**
3. `Generator.php:185` → `yield ['type' => 'error', 'message' => $e->getMessage()]` → **ブラウザに直接配信**

`.env` には実 GEMINI_API_KEY `AIzaSyC0-6JENhvUfczQwzylnbA9rdAojn4F-fQ` が入っており、デモ環境でレート上限・ネットワーク不調が起きた瞬間に画面に出ます。**今すぐ Google Cloud Console で当該キーをローテーションしてください。**

**修正方針（共通ヘルパ案）:**

`LlmException` に「公開可能なメッセージ」と「内部ログ用メッセージ」を分離するか、`wrapException` 側で URL を除去：

```php
// GeminiProvider.php 等の wrapException を下記に置換
private function wrapException(RequestException $e, string $op): LlmException
{
    $resp = $e->getResponse();
    $bodyExcerpt = null;
    if ($resp !== null) {
        try {
            $bodyExcerpt = (string) $resp->getBody();
        } catch (\Throwable) {
            $bodyExcerpt = null;
        }
    }
    // 機微情報のサニタイズ
    $safeMsg = self::sanitizeMessage($e->getMessage());
    // 内部ログには元メッセージを残す（前段でログ出力する想定）
    error_log('[' . self::PROVIDER_NAME . "] {$op} raw: " . $e->getMessage());

    return new LlmException(
        sprintf('%s %s failed (HTTP %s)', self::PROVIDER_NAME, $op, $resp?->getStatusCode() ?? '?'),
        self::PROVIDER_NAME,
        $resp?->getStatusCode(),
        self::sanitizeMessage($bodyExcerpt ?? ''),
        $e,
    );
}

private static function sanitizeMessage(string $msg): string
{
    // ?key=... / Authorization: Bearer ... / x-api-key: ... を伏字化
    $msg = preg_replace('/([?&](?:key|api[_-]?key|access[_-]?token)=)[^&\s"\']+/i', '$1***REDACTED***', $msg) ?? $msg;
    $msg = preg_replace('/(Authorization:\s*Bearer\s+)\S+/i', '$1***REDACTED***', $msg) ?? $msg;
    $msg = preg_replace('/(x-api-key:\s*)\S+/i', '$1***REDACTED***', $msg) ?? $msg;
    // Gemini / Anthropic / Voyage 等の典型的なキー形式
    $msg = preg_replace('/AIza[0-9A-Za-z_\-]{20,}/', '***REDACTED***', $msg) ?? $msg;
    $msg = preg_replace('/sk-[A-Za-z0-9_\-]{20,}/', '***REDACTED***', $msg) ?? $msg;
    $msg = preg_replace('/pa-[A-Za-z0-9_\-]{20,}/', '***REDACTED***', $msg) ?? $msg; // Voyage
    return $msg;
}
```

さらに `public/api/chat.php:113` / `Generator.php:185` で **ユーザー向けに一般化したメッセージのみ送る**:

```php
// chat.php
} catch (\Throwable $e) {
    error_log('[chat.php] ' . $e::class . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    sse_send('error', ['message' => 'システムエラーが発生しました。時間をおいて再度お試しください。']);
}
```

```php
// Generator.php:184-187
} catch (\Throwable $e) {
    error_log('[Generator] ' . $e::class . ': ' . $e->getMessage());
    yield ['type' => 'error', 'message' => '生成中にエラーが発生しました。'];
    return;
}
```

---

## CR-02: `.env` に実 GEMINI_API_KEY が平文で残っている / リポジトリ侵害時に漏洩

**ファイル:** `.env:21`

```env
GEMINI_API_KEY=AIzaSyC0-6JENhvUfczQwzylnbA9rdAojn4F-fQ
```

**問題:**
- `.gitignore` で除外されているが、デモ環境のディスク/バックアップ/共有スクショから容易に漏れる
- CR-01 の経路で実際に流出済みの可能性が高い

**修正:**
1. **このキーを今すぐ Google Cloud Console で revoke**（CR-01 で既に外部に出た前提）
2. 新しいキーを発行し、`.env` には新キーをセット
3. 提案デモ用に**別アカウント（個人ではなく顧客提案用）** のキーを発行し、無料枠の上限を低く設定
4. `git log -p -- .env` を確認し、コミット履歴に過去キーが残っていないか確認（残っていたら BFG Repo Cleaner 等で除去）

---

## CR-03: `APP_ENCRYPTION_KEY` が初期文字列のままで暗号化が事実上無効

**ファイル:**
- `.env:97` → `APP_ENCRYPTION_KEY=base64:replace-with-random-32-bytes-on-deploy`
- `src/Models/Settings.php:173-185`

**問題:**

```php
private static function getEncryptionKey(): string
{
    $raw = (string) Config::get('APP_ENCRYPTION_KEY', '');
    if (str_starts_with($raw, 'base64:')) {
        $key = base64_decode(substr($raw, 7), true);
        if ($key !== false && strlen($key) === 32) {
            return $key;
        }
    }
    // 未設定 or 不正形式 → ハッシュ化して32バイトに揃える（デモ用フォールバック）
    $seed = $raw !== '' ? $raw : 'rag-chatbot-demo-default-please-change';
    return hash('sha256', $seed, true);
}
```

現在の `.env` 値 `base64:replace-with-random-32-bytes-on-deploy` は `base64_decode('replace-with-random-32-bytes-on-deploy', true)` が失敗（`-` は base64 文字だが strict モードでは padding 不整合）。フォールバック分岐に入り、`hash('sha256', 'base64:replace-with-random-32-bytes-on-deploy')` という**公開リポジトリで誰でも再現可能なキー**で暗号化される。

つまり、**管理画面でDBに保存した APIキーは、`.env` を見れば誰でも復号できる状態。**

**修正:**

1. **デモ前にキーを生成して `.env` を更新**:

```bash
# composer install 済みの環境で
php -r "require 'vendor/autoload.php'; App\Config::load('.'); echo App\Models\Settings::generateEncryptionKey() . PHP_EOL;"
# → base64:abc1230xyz...== をコピペで .env に書き込む
```

2. **フォールバック動作を「fail-loud」に変更**（暗号化に予期せず弱いキーが使われるのを防ぐ）:

```php
private static function getEncryptionKey(): string
{
    $raw = (string) Config::get('APP_ENCRYPTION_KEY', '');
    if (!str_starts_with($raw, 'base64:')) {
        throw new \RuntimeException(
            'APP_ENCRYPTION_KEY must be set as "base64:<32-bytes-base64>". '
            . 'Generate one with: Settings::generateEncryptionKey()'
        );
    }
    $key = base64_decode(substr($raw, 7), true);
    if ($key === false || strlen($key) !== 32) {
        throw new \RuntimeException('APP_ENCRYPTION_KEY is invalid (must decode to exactly 32 bytes).');
    }
    return $key;
}
```

（フォールバックの「親切設計」は本番では脆弱性。デモ用なら起動時の `bootstrap.php` で fail-fast にする方が安全。）

---

## CR-04: ADMIN_PASSWORD_HASH 未設定で `admin/admin` 素通し動線が生きている

**ファイル:** `public/admin/auth.php:45-54`、`.env:75` （`ADMIN_PASSWORD_HASH=` 空）

**問題:**

```php
if ($expectedHash !== '') {
    if (!password_verify($password, $expectedHash)) {
        return false;
    }
} else {
    $plain = (string) Config::get('ADMIN_PASSWORD', 'admin'); // 開発用デフォルト
    if (!hash_equals($plain, $password)) {
        return false;
    }
}
```

現状の `.env` には `ADMIN_PASSWORD_HASH=` （空）かつ `ADMIN_PASSWORD` 未定義。フォールバックで `Config::get('ADMIN_PASSWORD', 'admin')` が `'admin'` を返すため、**ユーザー名 `admin` / パスワード `admin` で管理画面に入れます。**

デモのURLを顧客が他人に共有しただけでも管理画面が乗っ取られ、LLMキーをDBから盗まれます（CR-03 と組み合わせると暗号化も無効）。

**修正:**

1. デモ前に `.env` に強いハッシュをセット：
```bash
php -r "echo password_hash('your-strong-demo-password', PASSWORD_DEFAULT) . PHP_EOL;"
# 出力結果を .env の ADMIN_PASSWORD_HASH= 右辺にコピペ
```
2. フォールバック動線を本番では完全に殺す（`auth.php`）：
```php
function admin_login(string $username, string $password): bool
{
    $expectedUser = (string) Config::get('ADMIN_USERNAME', 'admin');
    $expectedHash = (string) Config::get('ADMIN_PASSWORD_HASH', '');

    if ($expectedUser === '' || $expectedHash === '') {
        // fail-loud: ハッシュ未設定なら誰もログインさせない
        error_log('[admin_login] ADMIN_USERNAME or ADMIN_PASSWORD_HASH not configured');
        return false;
    }
    if (!hash_equals($expectedUser, $username)) {
        return false; // ユーザー名照合も timing-safe に
    }
    if (!password_verify($password, $expectedHash)) {
        return false;
    }
    session_regenerate_id(true);
    $_SESSION['admin_user'] = $username;
    $_SESSION['admin_login_at'] = time();
    return true;
}
```

3. `login.php:49` の表示「初期パスワードは .env の ADMIN_PASSWORD を参照」も顧客に見えると印象悪い。プロダクション風に削除を：

```php
// login.php
<p class="text-xs text-gray-400 mt-4 text-center">
  管理者にお問い合わせください
</p>
```

---

## CR-05: knowledge.php の物理ファイル削除が常に失敗する（`'NULL'` フォールバックバグ）

**ファイル:** `public/admin/knowledge.php:64`

**問題:**

```php
if ($doc && str_starts_with($doc['storage_path'], realpath(UPLOAD_DIR) ?: 'NULL')) {
    @unlink($doc['storage_path']);
}
```

`realpath(UPLOAD_DIR)` は存在しないディレクトリで `false` を返し、`?:` で `'NULL'` に置換される。`storage_path` は実パスなので `str_starts_with(..., 'NULL')` は**常に false**。
→ DB レコードは消えるが**storage/uploads/ のファイルが永久に残る**（ディスク肥大化、機密文書の残留）。さらに `realpath` が解決成功するときは正規化された絶対パスが返るが、`Indexer::ingestFile()` が `storage_path = $filePath`（呼び出し元が渡したパスのまま）を保存しているため、**Windows のパス区切り `\` vs `/` の差や `..` 含有で `str_starts_with` 比較が失敗するケースが多い**。

**修正:**

```php
} elseif ($action === 'delete') {
    $id = (int) ($_POST['document_id'] ?? 0);
    try {
        $doc = Document::find($id);
        Document::delete($id); // CASCADE で chunks/qa も削除

        // 物理ファイル削除: realpath で正規化してから比較（path traversal 対策込み）
        if ($doc !== null && isset($doc['storage_path']) && $doc['storage_path'] !== '') {
            $uploadRoot = realpath(UPLOAD_DIR);
            $filePath = realpath($doc['storage_path']);
            if (
                $uploadRoot !== false
                && $filePath !== false
                && str_starts_with($filePath, $uploadRoot . DIRECTORY_SEPARATOR)
            ) {
                @unlink($filePath);
            } else {
                error_log("[knowledge.delete] Refused unlink outside upload dir: " . ($doc['storage_path'] ?? 'NULL'));
            }
        }
        $message = "🗑 削除しました: document_id={$id}";
        $messageType = 'success';
    } catch (\Throwable $e) { ... }
}
```

合わせて `Indexer::ingestFile()` で `storage_path` 保存時に **`realpath($filePath)` で正規化**しておくと比較が確実になります（`src/Knowledge/Indexer.php:60`）。

---

## CR-06: `feedback.php` に認証・認可・CSRF が一切なく、他者のメッセージへの偽装フィードバック可能

**ファイル:** `public/api/feedback.php` 全体、`public/admin/index.php:37` （解決率に直接反映）

**問題:**

```php
// feedback.php
$messageId = (int) ($payload['message_id'] ?? 0);
$rating = (string) ($payload['rating'] ?? '');
// → session_uuid / 認証チェックなし。誰でも任意のmessage_id にフィードバック可能
```

ダッシュボードに表示される「解決率」「👍/👎 数」が荒らしで自由に操作できる状態。提案デモ中に画面でリアルタイム反映してしまえば信頼性に直結。
さらに `conversation_id` がペイロードから取れているのに、その `session_uuid` と message が紐付くかの検証なし → 任意の他人の会話を「解決済み」にマーク可能。

**修正:**

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use App\Models\Conversation;
use App\Models\Database;
use App\Models\Feedback;

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$payload = $raw ? json_decode($raw, true) : null;
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid payload']);
    exit;
}

$messageId = (int) ($payload['message_id'] ?? 0);
$rating = (string) ($payload['rating'] ?? '');
$comment = isset($payload['comment']) ? mb_substr((string) $payload['comment'], 0, 2000) : null;
$sessionUuid = (string) ($payload['session_uuid'] ?? '');

$validRatings = [Feedback::RATING_UP, Feedback::RATING_DOWN, Feedback::RATING_RESOLVED, Feedback::RATING_UNRESOLVED];
if ($messageId <= 0 || !in_array($rating, $validRatings, true) || $sessionUuid === '') {
    http_response_code(400);
    echo json_encode(['error' => 'message_id, rating, session_uuid required']);
    exit;
}

// session_uuid と message_id の整合性チェック
$stmt = Database::pdo()->prepare(
    'SELECT m.id, m.conversation_id
     FROM messages m
     INNER JOIN conversations c ON c.id = m.conversation_id
     WHERE m.id = :mid AND c.session_uuid = :sess
     LIMIT 1'
);
$stmt->execute([':mid' => $messageId, ':sess' => $sessionUuid]);
$found = $stmt->fetch();
if ($found === false) {
    http_response_code(403);
    echo json_encode(['error' => 'message not found in this session']);
    exit;
}
$conversationId = (int) $found['conversation_id'];

try {
    $fbId = Feedback::create($messageId, $rating, $comment);
    if (in_array($rating, [Feedback::RATING_RESOLVED, Feedback::RATING_UNRESOLVED], true)) {
        Conversation::markResolved($conversationId, $rating === Feedback::RATING_RESOLVED);
    }
    echo json_encode(['ok' => true, 'feedback_id' => $fbId]);
} catch (\Throwable $e) {
    error_log('[feedback.php] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'internal error']);
}
```

`chat.js` 側も `session_uuid: this.sessionUuid` を必ず送る形は既にあるので OK（160行）。

---

# HIGH

## HR-01: `/api/chat.php` にレート制限・入力長制限が一切ない（コスト・DoS リスク）

**ファイル:** `public/api/chat.php:58-61`

**問題:**
- `message` 長さチェックなし → 100KB の入力を 1000回叩かれただけで Gemini 課金が爆発
- 同一 IP / cookie からの連投制限なし
- セッション生成も無制限（DBの conversations を肥大化させられる）

**修正（最低限）:**

```php
// chat.php — JSON parse の直後
$message = trim((string) ($payload['message'] ?? ''));
if ($message === '') {
    sse_fail('message is required', 400);
}
// 文字数制限（日本語想定、4000文字 ≒ 6000 tokens 程度）
if (mb_strlen($message) > 4000) {
    sse_fail('入力が長すぎます（最大4000文字）', 413);
}

// 簡易レート制限（同一IPで 1分20リクエスト）
$ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$ip = explode(',', $ip)[0]; // 先頭IP
$rateKey = sys_get_temp_dir() . '/ratelimit_' . sha1($ip);
$now = time();
$window = 60;
$limit = 20;
$entries = is_file($rateKey) ? array_filter(
    explode(',', file_get_contents($rateKey) ?: ''),
    fn($t) => (int)$t > $now - $window
) : [];
if (count($entries) >= $limit) {
    sse_fail('リクエストが多すぎます。少し時間をおいてください。', 429);
}
$entries[] = (string) $now;
@file_put_contents($rateKey, implode(',', $entries), LOCK_EX);
```

本格運用では Redis / APCu / cache テーブルで実装。デモは tmp ファイルで十分。

---

## HR-02: 管理ログインに試行回数制限がない（Brute force）

**ファイル:** `public/admin/login.php` 全体、`public/admin/auth.php:35-60`

**問題:**
顧客環境の URL が共有されただけで `admin` ユーザー名は固定 → パスワードへの総当たり可能。

**修正:**

`admin/login.php` の POST 受領前に簡易ロック：

```php
// login.php — POST 処理の前
$ipKey = sys_get_temp_dir() . '/adminlogin_' . sha1($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$now = time();
$record = is_file($ipKey) ? json_decode(file_get_contents($ipKey) ?: '{}', true) : [];
$failCount = (int) ($record['count'] ?? 0);
$lockUntil = (int) ($record['lock_until'] ?? 0);

if ($lockUntil > $now) {
    $error = 'ログイン試行が多すぎます。' . ceil(($lockUntil - $now) / 60) . '分後に再試行してください。';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = trim((string) ($_POST['username'] ?? ''));
    $pass = (string) ($_POST['password'] ?? '');
    if (admin_login($user, $pass)) {
        @unlink($ipKey);
        header('Location: index.php');
        exit;
    }
    $failCount++;
    $newRecord = ['count' => $failCount, 'lock_until' => 0];
    if ($failCount >= 5) {
        $newRecord['lock_until'] = $now + 600; // 10分ロック
        $newRecord['count'] = 0;
    }
    @file_put_contents($ipKey, json_encode($newRecord));
    $error = 'ユーザー名またはパスワードが違います。';
    // タイミング攻撃対策の遅延
    usleep(random_int(200_000, 600_000));
}
```

---

## HR-03: `Generator::generate()` で Guardrail 判定前に user メッセージを DB 保存（unsafe 内容が永久残留）

**ファイル:** `src/Rag/Generator.php:81-87`

**問題:**

```php
// user メッセージを保存
Message::create([
    'conversation_id' => $conversationId,
    'role' => Message::ROLE_USER,
    'content' => $userMessage,
]);

// 1) ガードレール
$guard = $this->guardrail->check($userMessage);
if (in_array($guard['verdict'], [Guardrail::VERDICT_OUT_OF_SCOPE, Guardrail::VERDICT_UNSAFE], true)) {
    ...
}
```

unsafe 判定された質問（プロンプトインジェクション、性的、攻撃的）も先にDBに入る。クライアントが「会話履歴」を顧客サポートに見せた瞬間、有害コンテンツが含まれる。さらに次ターンで `Message::listByConversation()` が同じ unsafe 文を `history` に詰めて LLM に送り返してしまうため、ガードレールを **すり抜ける** 経路にもなり得る。

**修正:** unsafe / out_of_scope なら user メッセージも保存しないか、`response_type` 相当のフラグを user メッセージにも付ける：

```php
public function generate(string $userMessage, int $conversationId, array $history = []): \Generator
{
    $start = microtime(true);
    $userMessage = trim($userMessage);
    if ($userMessage === '') {
        yield ['type' => 'error', 'message' => 'empty message'];
        return;
    }

    // 1) ガードレール（user メッセージ保存より前）
    $guard = $this->guardrail->check($userMessage);
    $isUnsafe = in_array($guard['verdict'], [Guardrail::VERDICT_OUT_OF_SCOPE, Guardrail::VERDICT_UNSAFE], true);

    // user メッセージは保存するが、unsafe ならメタデータでマーク
    Message::create([
        'conversation_id' => $conversationId,
        'role' => Message::ROLE_USER,
        'content' => $userMessage,
        // unsafe ならフラグを立てて history から除外できるようにする
        // ※ messages.metadata カラムが無いので citations JSON カラムに ['flagged'=>true] を入れるか、
        //   migration で metadata JSON 列を追加する
    ]);

    if ($isUnsafe) { ... yield refusal ... return; }
```

加えて `chat.php:84-88` の history 構築で「unsafe フラグ付きメッセージを除外」する処理を追加してください。

---

## HR-04: SSE 切断時に PHP プロセスが LLM 課金を続ける（`ignore_user_abort` 未設定）

**ファイル:** `public/api/chat.php:107-109`

**問題:**

```php
foreach ($generator->generate($message, $conversationId, $history) as $event) {
    $type = $event['type'] ?? 'token';
    sse_send($type, $event);
    if (connection_aborted()) {
        break;
    }
}
```

`connection_aborted()` は `flush()` を経由した送信があって初めて検知される。トークン生成中（特に最初のトークンが届く前の数秒）はクライアントが閉じてもPHP側は気付かず、Gemini ストリーミングを最後まで受信＝**課金**する。
さらに PHP のデフォルト動作だと、出力時にクライアント切断検知で **スクリプト全体が強制終了され、`Message::create()` （assistant 保存）が走らない**ケースがある（履歴破損）。

**修正:**

```php
// chat.php — header 群の直後
ignore_user_abort(true); // クライアント切断時もスクリプトを最後まで走らせる
set_time_limit(180);     // 念のため上限
```

そして `connection_aborted()` チェックの粒度を上げる：

```php
foreach ($generator->generate($message, $conversationId, $history) as $event) {
    $type = $event['type'] ?? 'token';
    sse_send($type, $event);
    if (connection_aborted()) {
        // LLM ストリームを途中でも assistant メッセージ保存まで走らせたいので
        // ループを break ではなく continue（done だけ来たら保存される）でも可。
        // 完全に止めるなら Generator にもキャンセル機構が必要。
        break;
    }
}
```

実装の最も簡単な改善:
1. `ignore_user_abort(true)` を入れて save まで完走させる
2. または Generator が internal state で「保存だけは必ずやる」よう `finally` ブロックを使う

---

## HR-05: セッション cookie の `secure` 判定が脆弱（HTTPS proxy 配下で平文送信）

**ファイル:** `public/admin/auth.php:13`

```php
'secure' => (($_SERVER['HTTPS'] ?? '') === 'on'),
```

**問題:**
- 一部の環境（CloudFront / Cloudflare / nginx reverse proxy）では `$_SERVER['HTTPS']` がセットされず `$_SERVER['HTTP_X_FORWARDED_PROTO']` のみ。secure=false で送られ、HTTP の中間者攻撃でセッションクッキー盗難。
- `SameSite=Lax` は CSRF に対しある程度有効だが、`Strict` の方が望ましい（管理画面の外部リンクからの遷移はほぼ無い前提なら）

**修正:**

```php
// auth.php
if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (($_SERVER['HTTPS'] ?? '') === 'on')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443);

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Strict', // 管理画面はLaxよりStrictが望ましい
    ]);
    session_name('rag_admin');
    session_start();
}
```

なお `samesite: Strict` にすると外部からのリンクで session が送られなくなるため、ログインしたタブを閉じてからクリック → 再ログイン要求になります。デモでは Lax のままでも可。

---

## HR-06: チャットUIの `renderMarkdown` で `title` 属性が DOMPurify ホワイトリストに追加（情報漏洩リスク）

**ファイル:** `public/assets/js/chat.js:42`

```js
return (typeof DOMPurify !== 'undefined')
    ? DOMPurify.sanitize(html, { ADD_ATTR: ['title'] })
    : html;
```

**問題:**
`title` 自体は基本的に無害だが、LLM が `<a title="...">` 等を出力した場合に title でユーザー情報を引き出す phishing UI が作れる。さらに DOMPurify は CDN ロードに失敗すると `html` をそのまま返すため、CDN障害時に**完全に XSS が通る**。

**修正:**

```js
renderMarkdown(text) {
    if (!text) return '';
    const decorated = text.replace(/\[(\d+(?:\s*,\s*\d+)*)\]/g, (m, group) => {
        return group.split(/\s*,\s*/).map(n =>
            `<span class="cite-ref" data-cite="${n}">[${n}]</span>`
        ).join('');
    });
    // DOMPurify が無ければ markdown 描画を諦めてプレーン表示
    if (typeof DOMPurify === 'undefined' || typeof marked === 'undefined') {
        // テキストとしてエスケープして表示
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    const html = marked.parse(decorated);
    return DOMPurify.sanitize(html, {
        // title は外す（情報漏洩・phishing 対策）
        FORBID_ATTR: ['style', 'on*'],
    });
},
```

加えて、`index.php:39-40` の CDN 読み込みに SRI を付けると Supply Chain Attack 耐性が上がります：

```html
<script src="https://cdn.jsdelivr.net/npm/marked@10.0.0/marked.min.js"
        integrity="sha384-..." crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/dompurify@3.0.6/dist/purify.min.js"
        integrity="sha384-..." crossorigin="anonymous"></script>
```

---

## HR-07: `Conversation` の `user_identifier` が cookie 経由で任意の値を受け入れる

**ファイル:** `public/api/chat.php:72-73`

```php
$userIdent = $_COOKIE['rag_visitor_id'] ?? null;
$newConv = Conversation::create($userIdent);
```

**問題:**
- どこにも `rag_visitor_id` cookie を **set** している場所が無い（grep 全体で `setcookie.*rag_visitor` ヒットなし）→ 機能不在
- にもかかわらず外部入力をそのままDBの `user_identifier` (VARCHAR(100)) に保存。長さチェック・文字種チェックなし。
- 後で管理画面で `htmlspecialchars` していれば XSS は防げているが、`conversations.user_identifier` が空文字混在になり統計が壊れる

**修正:**

```php
// chat.php
$userIdent = isset($_COOKIE['rag_visitor_id']) ? trim((string) $_COOKIE['rag_visitor_id']) : '';
// UUID 形式のみ許可（任意文字列を許すと荒らされる）
if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $userIdent)) {
    // cookie 無効 or 不正なら新規発行
    $userIdent = \Ramsey\Uuid\Uuid::uuid4()->toString();
    setcookie('rag_visitor_id', $userIdent, [
        'expires' => time() + 86400 * 365,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => isset($_SERVER['HTTPS']) || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https',
    ]);
}
$newConv = Conversation::create($userIdent);
```

---

## HR-08: Reranker の rerank が失敗時に **元順序のまま** 上位 N を返すが、Hybrid 検索の RRF スコア順が「BM25 + ベクトル」順で最終回答に直結

**ファイル:** `src/Rag/Reranker.php:86-93`

```php
try {
    $resp = $this->provider->generate(...);
} catch (\Throwable) {
    return array_slice($chunks, 0, $topK);  // ← 例外を握りつぶす
}
```

**問題:**
- 例外を `error_log` もせず捨てているので、運用中に rerank が全く動いていない（APIキー間違い等）ことに気付けない
- さらに `parseScores` も失敗時にエラー情報を残さず空配列で fallthrough する

**修正:**

```php
try {
    $resp = $this->provider->generate(...);
} catch (\Throwable $e) {
    error_log('[Reranker] LLM failed, falling back to candidate order: ' . $e->getMessage());
    return array_slice($chunks, 0, $topK);
}

$scores = $this->parseScores($resp->content);
if ($scores === []) {
    error_log('[Reranker] Score parse failed. Raw response: ' . mb_substr($resp->content, 0, 300));
    return array_slice($chunks, 0, $topK);
}
```

これは「コードレビュー 重点項目 - 1.5 Generator が history を 10件にトリムしているがトークン制限への配慮が雑」も同じ問題で、Generator.php:184 の catch も `error_log` なしで握りつぶしています。

---

## HR-09: Markdown 描画前の `[N]` 置換が誤検出する（既存テキスト中の数字列）

**ファイル:** `public/assets/js/chat.js:37-39`

```js
const decorated = text.replace(/\[(\d+(?:\s*,\s*\d+)*)\]/g, (m, group) => {
    return group.split(/\s*,\s*/).map(n => `<span class="cite-ref" title="出典 ${n} を参照">[${n}]</span>`).join('');
});
```

**問題:**
- LLM が `[2024, 2025]` という年号や `[1, 2, 3, 4, 5]` のような単なる数字列を出力すると引用扱いされる
- さらに **Markdown が解釈される前に `<span>` を挿入**しているので、`marked.parse()` がコードブロック・テーブル等に含まれる場合は HTML が壊れる可能性

**修正:**

```js
renderMarkdown(text) {
    if (!text) return '';
    // Markdown を先に解釈してから、citation 番号を装飾する
    const html = marked.parse(text);
    // HTML に置換する場合、コードブロック内は除外したいので DOM 操作で行う
    const parser = new DOMParser();
    const doc = parser.parseFromString(html, 'text/html');

    // CitationParser と同期した最大値（chunks.length）を持っておけば誤検出を減らせる
    const maxCite = (this.messages[botIdx]?.citations?.length) || 99;

    const walk = (node) => {
        if (node.nodeType === Node.TEXT_NODE) {
            const replaced = node.textContent.replace(/\[(\d+(?:\s*,\s*\d+)*)\]/g, (m, group) => {
                const nums = group.split(/\s*,\s*/).map(n => parseInt(n, 10));
                if (nums.every(n => n >= 1 && n <= maxCite)) {
                    return nums.map(n => ` CITE${n} `).join('');
                }
                return m;
            });
            if (replaced !== node.textContent) {
                const tmp = document.createElement('span');
                tmp.innerHTML = replaced.replace(/ CITE(\d+) /g,
                    '<span class="cite-ref" data-cite="$1">[$1]</span>');
                node.replaceWith(...tmp.childNodes);
            }
        } else if (node.tagName !== 'CODE' && node.tagName !== 'PRE') {
            Array.from(node.childNodes).forEach(walk);
        }
    };
    walk(doc.body);

    return DOMPurify.sanitize(doc.body.innerHTML, { FORBID_ATTR: ['style', 'on*'] });
},
```

簡易版（時間がないなら）：CitationParser がサーバ側で正当性チェックしているので、フロントは正規表現を厳しくしておく：

```js
// 1-99 の番号のみ citation 扱い、年号などは除外
const decorated = text.replace(/\[(\d{1,2}(?:\s*,\s*\d{1,2})*)\]/g, ...);
```

---

# MEDIUM

## MR-01: 既知バグの再発: 「同名プレースホルダ」を使った prepare が他にも疑わしい

**ファイル:** `src/Models/Database.php:24` （emulate=false）、`src/Models/Settings.php:38-43`

`Settings::set()` で `INSERT ... ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)` を使っているのは安全（`VALUES()` 関数で参照しているのでプレースホルダ重複なし）。`Chunk::fulltextSearch` は別名化済み。他に該当箇所は無さそうですが、新しい SQL を書くときは「**emulate prepares=false の MySQL では同名プレースホルダ再利用禁止**」を CLAUDE.md に追記しておくと再発防止。

**修正提案（CLAUDE.md or `docs/CODING_RULES.md`）:**
```markdown
## SQL コーディング規約
- すべてのSQLは PDO プリペアドステートメント使用
- `Database::pdo()` は `ATTR_EMULATE_PREPARES => false` なので、同名プレースホルダの再利用は禁止
  - NG: `WHERE a = :x OR b = :x`
  - OK: `WHERE a = :xa OR b = :xb` （別名化）
- ループ内 prepare は避け、1回 prepare → 複数回 execute
```

## MR-02: Generator が `history` を直近 10件だけで打ち切るがトークン上限考慮なし

**ファイル:** `src/Rag/Generator.php:163`

```php
foreach (array_slice($history, -10) as $turn) {
```

5000文字の質問が10回続いたら 50000文字 ≒ Gemini Flash の context window の数十%。`max_tokens=2048` を引いた残りでオーバーする可能性。

**修正:**

```php
private const HISTORY_MAX_CHARS = 8000;

// foreach の前
$slimHistory = [];
$budget = self::HISTORY_MAX_CHARS;
foreach (array_reverse(array_slice($history, -10)) as $turn) {
    $len = mb_strlen((string) $turn['content']);
    if ($len > $budget) break;
    $budget -= $len;
    array_unshift($slimHistory, $turn);
}
foreach ($slimHistory as $turn) {
    $role = $turn['role'] === 'assistant' ? LlmMessage::ROLE_ASSISTANT : LlmMessage::ROLE_USER;
    $messages[] = new LlmMessage($role, (string) $turn['content']);
}
```

## MR-03: `Settings::all()` 経由でAPIキーが平文DBにキャッシュされる動線

**ファイル:** `src/Models/Settings.php:96-106`

```php
public static function all(): array
{
    $stmt = Database::pdo()->query('SELECT setting_key, setting_value FROM settings');
    $rows = $stmt->fetchAll();
    foreach ($rows as $row) {
        $out[$row['setting_key']] = $row['setting_value'];
        self::$cache[$row['setting_key']] = $row['setting_value']; // ← 暗号化値をキャッシュに
    }
}
```

`all()` 自体は呼ばれてないですが、もし将来 admin UI で全設定一覧を作ると暗号化値（base64）も渡される。利用箇所ゼロなら削除候補。

**修正:** 利用予定が無いなら削除、または暗号化キーをフィルタ：

```php
public static function all(): array
{
    $stmt = Database::pdo()->query('SELECT setting_key, setting_value FROM settings');
    $rows = $stmt->fetchAll();
    $out = [];
    foreach ($rows as $row) {
        // 暗号化保存キーは外す（生暗号文も漏らしたくない）
        if (str_ends_with($row['setting_key'], '_encrypted')) {
            continue;
        }
        $out[$row['setting_key']] = $row['setting_value'];
        self::$cache[$row['setting_key']] = $row['setting_value'];
    }
    return $out;
}
```

## MR-04: `CitationParser` の `preg_split` が失敗時 null を返すケース未考慮

**ファイル:** `src/Rag/CitationParser.php:45, 82`

```php
foreach (preg_split('/\s*,\s*/', $group) as $num) {
```

`preg_split` は失敗時 `false` を返します。`foreach (false as ...)` は PHP 8.x で TypeError 系の Warning。デリミタが安定なので発生確率は低いですが、defensive に。

**修正:**

```php
foreach ((preg_split('/\s*,\s*/', $group) ?: []) as $num) {
```

## MR-05: `XlsxExtractor` の `setReadDataOnly(true)` は良いが、空セルの判定で `array_filter` の結果と元配列の長さで判定がズレている

**ファイル:** `src/Knowledge/XlsxExtractor.php:33-38`

```php
$filtered = array_filter($row, fn($c) => $c !== null && $c !== '');
if ($filtered === []) {
    continue;
}
$lines[] = implode("\t", array_map(fn($c) => (string) $c, $row));  // ← $row のまま使用
```

問題は無いが、`$filtered` で判定して `$row` を出力するので、null 混じりの行が `(empty)\t(empty)\tA\t(empty)` のように見栄えが悪い。

**修正（任意）:**

```php
$lines[] = implode("\t", array_map(fn($c) => $c === null ? '' : (string) $c, $row));
```

## MR-06: Chunker の `mb_strlen` vs `strlen` 混在判定

**ファイル:** `src/Knowledge/Chunker.php`

確認したところ `Chunker::slidingWindow` は **すべて mb_strlen** で統一されています。問題なし。レビュー観点のメモとして残します。

## MR-07: `Indexer::indexVectors` で vector_id を chunk_id にしているがエラー処理が不在

**ファイル:** `src/Knowledge/Indexer.php:148-172`

```php
private function indexVectors(array $chunkIds, array $chunkData): void
{
    ...
    try {
        $vectors = $this->embedding->embed($texts, 'document');
    } catch (\Throwable) {
        // Embedding失敗時はvector_idなしのままBM25のみで動く
        return;
    }
```

例外メッセージをログに残さないので、Embedding が機能していないことに気付けない。

**修正:**

```php
} catch (\Throwable $e) {
    error_log('[Indexer.indexVectors] Embedding failed for ' . count($texts) . ' chunks: ' . $e->getMessage());
    return;
}
```

## MR-08: `auth.php` の admin_csrf_check が GET でも `_csrf` を見ない

**ファイル:** `public/admin/auth.php:93-101`

```php
$sent = $_POST['_csrf'] ?? '';
```

REST的な操作（DELETE 相当を GET で行うとか）には対応していないが、現状の admin 画面はすべて POST フォームなのでOK。ただし `formaction="?"` で settings.php が `test_llm`/`test_embedding` を POST submit していて、これは `_csrf` が同フォーム内に hidden で入っているので問題なし（確認済み 152, 229行）。

問題なし。記録のみ。

## MR-09: `Document::create` で `storage_path` を保存するが URL/絶対パスの正規化なし

**ファイル:** `src/Models/Document.php:20-39`、`src/Knowledge/Indexer.php:60`

`storage_path` には `__DIR__ . '/../../storage/uploads/<uuid>.pdf'` のような相対 + 正規化前パスが入る。CR-05 のパス比較が壊れる原因でもある。

**修正:**

```php
// Indexer.php ingestFile() — Document::create に渡す前に
'storage_path' => realpath($filePath) ?: $filePath,
```

## MR-10: 管理画面 settings.php `htmlspecialchars` は概ねOKだが、入力テストエラーで `$e->getMessage()` を生のまま echo（XSS可能性）

**ファイル:** `public/admin/settings.php:63, 86`

```php
$message = '❌ LLM接続エラー: ' . $e->getMessage();
```

`$message` は最終的に `htmlspecialchars($message, ENT_QUOTES, 'UTF-8')` （144行）で出力されているのでXSSは防御済み。**問題なし**ですが、CR-01 と組み合わせると `$e->getMessage()` に APIキー URL が含まれて画面表示される問題は残ります。CR-01 修正で同時解決。

## MR-11: `qa-review.php` で `$_SESSION['admin_user']` の存在前提だがダウンキャストなし

**ファイル:** `public/admin/qa-review.php:15`

```php
$admin = $_SESSION['admin_user'] ?? 'admin';
```

`admin_require_login()` を通過しているので `$_SESSION['admin_user']` は string が保証されているが、`?? 'admin'` フォールバックは不要。安全側だが意図不明。

**修正（任意・可読性向上）:**

```php
$admin = (string) ($_SESSION['admin_user'] ?? '');
```

## MR-12: `Chunk::fulltextSearch` の n-gram 内に LIKE ワイルドカードが含まれる場合の誤マッチ

**ファイル:** `src/Models/Chunk.php:156`

```php
$value = '%' . $g . '%';
```

ユーザー入力が `_` `%` を含むと LIKE のワイルドカードとして動作 → 関連性の低いマッチが top に来る可能性。SQLi にはならない（プリペア済み）が検索精度低下。

**修正:**

```php
// n-gram 作成後、LIKE 用にエスケープ
foreach ($ngrams as $i => $g) {
    $escaped = strtr($g, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']);
    $value = '%' . $escaped . '%';
    ...
}
```

ただし `makeNgrams` が `preg_replace('/\s+/u', '', $query)` で空白除去のみで他の制御文字は残るため、念のため日本語以外の制御文字も `[\p{Cc}\p{Cf}]` で落とすと良いです。

---

# LOW

## LR-01: `error_log` が散発的で Monolog logger を活用していない

**ファイル:** `composer.json` で `monolog/monolog: ^3.7` を要求しているが grep してもどこからも `use Monolog\...` していない。

依存だけ追加されて使われていない（不要 dependency）。デモなら `error_log` で十分なので Monolog を外すか、`src/Logger.php` のような薄いラッパを作って使う。

## LR-02: ハードコードされた日本語メッセージ（i18n非対応）

`Generator.php:137`、`auth.php` ほぼ全部、エラーメッセージ多数。
デモ用途なら問題なし。提案後の本番化フェーズで `lang/ja.php` 等に切り出す前提を README に追記。

## LR-03: `Settings::effective / Settings::effectiveApiKey` の二段フォールバックロジックが理解しづらい

**ファイル:** `src/Models/Settings.php:68-93`

`effective()` と `effectiveApiKey()` の `null` / 空文字 / `$default` の扱いが微妙に違う。

```php
// effective: DB空→env→default
// effectiveApiKey: DB空→env、ただし decode失敗時は env、双方空→null
```

**修正提案（リファクタ）:**

ロジックは合っているので、メソッドに docblock で挙動を明記するだけで十分：

```php
/**
 * 優先順: DB(settings) > env > default。空文字は「未設定」とみなしフォールバック。
 *
 * @return string|null DB/envどちらにも値が無く defaultもnullなら null を返す
 */
public static function effective(string $dbKey, string $envKey, ?string $default = null): ?string
```

## LR-04: `Reranker::parseScores` の正規表現 `[.*]` が greedy で末尾の文字列も巻き込む

**ファイル:** `src/Rag/Reranker.php:114-115`

```php
if (preg_match('/\[.*\]/s', $text, $m)) {
    $text = $m[0];
}
```

`s` フラグ + greedy `.*` なので、複数の JSON 配列が混じった応答だと最初の `[` から最後の `]` まで全部取る → `json_decode` 失敗。

**修正:**

```php
if (preg_match('/\[.*?\](?=\s*$|\s*[^,\]])/s', $text, $m)) {
    $text = $m[0];
}
// または、シンプルに最初の '[' から対応する ']' を見つける
```

## LR-05: `Database::pdo()` のシングルトンが失敗時にキャッシュされない（リトライ動作）

**ファイル:** `src/Models/Database.php:25-54`

接続失敗時に `RuntimeException` を投げるが `self::$instance = $pdo` は実行されないので、次回呼び出しでまたDB接続を試みる。これは正しい動作。**問題なし**、記録のみ。

## LR-06: 不要 `use` ステートメント

**ファイル:** `src/Rag/HybridSearch.php:6` `use App\Embedding\EmbeddingFactory;` （ファクトリ未使用、`fromConfig` で `EmbeddingFactory::create()` 呼んでるので使用中）→ 問題なし

**ファイル:** `src/Rag/Generator.php:7` `use App\Config;` （ファイル内で `Config::` 未使用）→ 削除可
**ファイル:** `src/Rag/QueryRewriter.php:6` `use App\Llm\LlmFactory;` → 削除可（fromConfig が default null → factory 呼ばないので未使用）

実際は `QueryRewriter::fromConfig` で `LlmFactory::create()` 呼んでいるので使用中。`Generator.php` の `use App\Config;` のみ未使用。

## LR-07: `chat.js` の `botIdx` 計算が race condition の可能性

**ファイル:** `public/assets/js/chat.js:67-69`

```js
const botIdx = this.messages.length;
this.messages.push({ role: 'user', content: message });
// botIdx は user message の位置と一致してしまう（off-by-one かに見えるが順番に注意）
this.messages.push({ role: 'assistant', content: '', citations: [] });
```

これは順序的に正しい（user push の前に length を取り、その後 user + assistant の2件を push。assistant の index は `length + 1` ではなく…）。
よく読むと：`botIdx = this.messages.length` （= 0 件目）→ user を push（messages[0]）→ assistant を push（messages[1]）。`botIdx = 0` だと user のほうを参照してしまう。

**実際の挙動:** よく読むと `botIdx = this.messages.length` （= n）の時点で push 前なので、その後 user を push したら `messages[n] = user`、assistant を push したら `messages[n+1] = assistant`。**`botIdx` は user メッセージの index を指している**。

ただし `handleSseFrame` で `this.messages[botIdx].content += data.delta` （138行）と書いているので、**user メッセージに assistant の token が追記される**バグ。

**待った、これはバグの可能性ありです。** 動作テストで気付かれているかもしれないですが、再確認推奨：

**修正:**

```js
// chat.js send() の最初
this.messages.push({ role: 'user', content: message });
this.messages.push({ role: 'assistant', content: '', citations: [] });
const botIdx = this.messages.length - 1;  // ← assistant の index
```

→ 改めて HIGH に格上げ：

### HR-10（追加）: `chat.js` `botIdx` のオフセット計算ミスで、assistant メッセージのストリーミングが user メッセージ側に追記される

**ファイル:** `public/assets/js/chat.js:67-69`

上記の通り。動作確認してください。もし「動いているように見える」なら、Alpine.js の `:key="idx"` バインドの再描画で気付かなかった可能性。

実機での確認方法：
1. `this.messages.length` のログを `console.log` で `send()` の冒頭、user push後、assistant push後にそれぞれ出す
2. token 到着時に `this.messages[botIdx].role` を出す

修正版：

```js
async send(message) {
    this.messages.push({ role: 'user', content: message });
    this.messages.push({ role: 'assistant', content: '', citations: [] });
    const botIdx = this.messages.length - 1;
    this.isLoading = true;
    this.isThinking = true;
    ...
}
```

## LR-08: `composer.json` `monolog/monolog` 等の依存がアプリコードで未使用

**ファイル:** `composer.json:13`

`league/commonmark`（Markdown→HTML、フロントで marked 使っているので不要）と `monolog/monolog`（未使用）はベンダーサイズ稼ぐだけになっている。

**修正:** 不要なら削除して composer サイズを軽量化（デモなら任意）。

---

## 確認したが問題なしの項目（記録）

- **SQL Injection** (Chunk/Settings/Conversation/Message/Feedback/Document/qa-review.php): 全てPDOプリペア、変数バインド、`emulate=false` で安全。`qa-review.php` の動的 `$where` も `(int) $days` で型強制済み。問題なし。
- **CSRF（管理画面フォーム）**: settings.php / knowledge.php / qa-review.php すべて `admin_csrf_token()` + `admin_csrf_check()` 経由。問題なし。
- **session_regenerate_id(true)**: `auth.php:56` で login 時に呼ばれている。Session Fixation 対策OK。
- **パスワード比較**: `hash_equals` 使用済み（timing-safe）。
- **AES-256-GCM 実装**: iv(12) + tag(16) + ciphertext のフォーマット、`OPENSSL_RAW_DATA` フラグ、tag 長 16、`\openssl_*` で global namespace 明示。実装は正しい。問題はキー管理（CR-03）のみ。
- **ファイルアップロード**: 拡張子ホワイトリスト、20MB制限、UUIDリネーム、storage/uploads（public外）保存。基本は安全。MIME検証も追加するとより安全（MR枠で追記）。
- **CSP（Content-Security-Policy）**: 未設定。Tailwind/Alpine/marked/DOMPurify CDN を使うため厳格設定は難しいが、`default-src 'self' cdn.tailwindcss.com unpkg.com cdn.jsdelivr.net; script-src 'self' 'unsafe-inline' ...` 程度入れると XSS 耐性が上がります（HIGHに昇格してもいいレベル）。

---

## 修正優先度サマリ（このコメ一読で漏れなく直すには）

### **本番デモ前に必ず（CRITICAL）**
- [ ] **CR-01**: `wrapException` 群を `sanitizeMessage` 化 + ユーザー向けエラーメッセージ汎用化（3プロバイダ + 3 embedding + chat.php + Generator.php）
- [ ] **CR-02**: `.env` の `GEMINI_API_KEY` を **revoke して再発行**
- [ ] **CR-03**: `APP_ENCRYPTION_KEY` を `Settings::generateEncryptionKey()` で生成して `.env` に書き込み、fallback を fail-loud に
- [ ] **CR-04**: `ADMIN_PASSWORD_HASH` を `password_hash()` で生成、平文フォールバック削除、login.php の「.env の ADMIN_PASSWORD を参照」文言を削除
- [ ] **CR-05**: `knowledge.php` 削除時の realpath 比較を修正、Indexer 側で storage_path 正規化
- [ ] **CR-06**: `feedback.php` に session_uuid 検証追加

### **本番デモ前に強く推奨（HIGH）**
- [ ] **HR-01**: chat.php に入力長制限（4000文字）+ 簡易レート制限（20req/min/IP）
- [ ] **HR-02**: login.php に試行回数制限（5回失敗で10分ロック）
- [ ] **HR-03**: Generator で Guardrail を user 保存より先に
- [ ] **HR-04**: chat.php に `ignore_user_abort(true)` + `set_time_limit(180)`
- [ ] **HR-05**: auth.php の secure cookie 判定を proxy 対応に
- [ ] **HR-06**: chat.js の DOMPurify fallback を「テキストエスケープ」に
- [ ] **HR-07**: chat.php の `$_COOKIE['rag_visitor_id']` 検証 + setcookie 実装
- [ ] **HR-08**: Reranker / Generator の例外を `error_log`
- [ ] **HR-09**: chat.js の citation 正規表現を `\d{1,2}` に絞る or DOM 経由置換
- [ ] **HR-10**: chat.js の `botIdx` をオフセット修正

### **時間があれば（MEDIUM以下）**
- [ ] MR-01〜MR-12: 個別対応

---

_Reviewer: Claude_
_Review Depth: standard（重点項目に対する精読 + パターンマッチング）_
_Last updated: 2026-05-26_
