<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Settings;
use App\Rag\Generator;
use Ramsey\Uuid\Uuid;

// SSE 配信中もクライアント切断後も「assistant メッセージ保存まで」完走させる
ignore_user_abort(true);
// ローカル思考モデル＋全文書RAGは生成が長引くため上限を長めに（LLM_HTTP_TIMEOUTより少し上）
@set_time_limit((int) (\App\Config::get('LLM_HTTP_TIMEOUT', 600)) + 30);

// SSE ヘッダ
header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('X-Accel-Buffering: no'); // Nginx の buffering を切る
header('Connection: keep-alive');

// PHP 側のバッファリングを最小化
@ini_set('output_buffering', '0');
@ini_set('implicit_flush', '1');
while (ob_get_level() > 0) {
    ob_end_flush();
}
ob_implicit_flush(true);

/**
 * SSE フレーム1件を送る。
 */
function sse_send(string $event, mixed $data): void
{
    echo "event: {$event}\n";
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
    @flush();
}

function sse_fail(string $msg, int $status = 500): never
{
    http_response_code($status);
    sse_send('error', ['message' => $msg]);
    exit;
}

// ----------------------------------------
// レート制限（IP単位、1分20リクエスト）
// ----------------------------------------
$ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$ip = trim(explode(',', $ip)[0]);
$rateFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rag_chat_rl_' . sha1($ip);
$now = time();
$window = 60;
$limit = 20;
$entries = is_file($rateFile)
    ? array_filter(
        explode(',', (string) @file_get_contents($rateFile)),
        fn($t) => $t !== '' && (int) $t > $now - $window
    )
    : [];
if (count($entries) >= $limit) {
    sse_fail('リクエストが多すぎます。少し時間をおいてもう一度お試しください。', 429);
}
$entries[] = (string) $now;
@file_put_contents($rateFile, implode(',', $entries), LOCK_EX);

// ----------------------------------------
// 入力パース + バリデーション
// ----------------------------------------
$raw = file_get_contents('php://input');
if ($raw === false || $raw === '') {
    sse_fail('empty request body', 400);
}
// 入力サイズ（payload自体）の上限: 100KB
if (strlen($raw) > 100 * 1024) {
    sse_fail('リクエストが大きすぎます', 413);
}

try {
    $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
} catch (\JsonException $e) {
    sse_fail('invalid JSON: ' . $e->getMessage(), 400);
}
if (!is_array($payload)) {
    sse_fail('invalid payload', 400);
}

$message = trim((string) ($payload['message'] ?? ''));
if ($message === '') {
    sse_fail('message is required', 400);
}
// 文字数制限: 日本語想定で4000文字（≒ 6000 tokens 程度）
if (mb_strlen($message) > 4000) {
    sse_fail('入力が長すぎます（最大4000文字）。要点を絞ってお試しください。', 413);
}

$sessionUuid = (string) ($payload['session_uuid'] ?? '');
// UUID v4 形式以外は破棄（任意文字列を許すと荒らされる）
if ($sessionUuid !== '' && !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $sessionUuid)) {
    $sessionUuid = '';
}

// ----------------------------------------
// サービス稼働フラグ（キルスイッチ）
// 停止中は LLM を呼ばず中立メッセージだけ返して終了する。
// 用途: 保守料の未払い等でベンダーが接続を停止する。DB/ナレッジは消さず、再開すれば即復旧（可逆）。
// 判定:
//   (a) DB settings.service_active = '0'  … 統括コンソール/管理画面のボタンで切替（通常の停止）
//   (b) .env SERVICE_ACTIVE=0             … 強制停止の上書き（DBより優先。顧客・画面から解除不可）
//   どちらかが停止なら停止。
// ★文言はエンドユーザー（顧客サイトの訪問者=無関係な第三者）が見ても安全な中立文。
//   「未払い」等は書かない（顧客の面子・信用を損ねない）。ここではDB書き込みもしない。
// ----------------------------------------
$envForceOff = ((string) \App\Config::get('SERVICE_ACTIVE', '1') === '0');
$dbOff = false;
try {
    $dbOff = (Settings::get('service_active') === '0');
} catch (\Throwable) {
    $dbOff = false; // 設定行が無い等は「稼働」とみなす
}
if ($envForceOff || $dbOff) {
    $suspendMsg = trim((string) \App\Config::get('SERVICE_SUSPENDED_MESSAGE', ''));
    if ($suspendMsg === '') {
        $suspendMsg = '申し訳ございません。ただいまAIチャットは一時的にご利用いただけません。'
            . 'お手数をおかけしますが、時間をおいて再度お試しください。'
            . 'お急ぎの場合は、サイトのお問い合わせ窓口よりご連絡ください。';
    }
    sse_send('refusal', ['type' => 'refusal', 'message' => $suspendMsg]);
    sse_send('end', ['ok' => true]);
    exit;
}

// ----------------------------------------
// rag_visitor_id cookie の検証 + 未発行なら発行
// ----------------------------------------
$visitorId = isset($_COOKIE['rag_visitor_id']) ? trim((string) $_COOKIE['rag_visitor_id']) : '';
if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $visitorId)) {
    $visitorId = Uuid::uuid4()->toString();
    $isHttps = (($_SERVER['HTTPS'] ?? '') === 'on')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    // 埋め込み(iframe)は顧客サイト→ベンダーhostのサードパーティ文脈。
    // SameSite=None; Secure でないと Cookie が送受信されない（＝訪問者トラッキング不可）。
    // HTTPSなら None、非HTTPS(ローカル等)は None が無効なので Lax にフォールバック。
    // ※このCookieが使えなくてもチャットは動く（会話継続は session_uuid / sessionStorage 側）。
    setcookie('rag_visitor_id', $visitorId, [
        'expires' => time() + 86400 * 365,
        'path' => '/',
        'httponly' => true,
        'samesite' => $isHttps ? 'None' : 'Lax',
        'secure' => $isHttps,
    ]);
}

// ----------------------------------------
// 会話セッションの確保
// ----------------------------------------
$conv = null;
if ($sessionUuid !== '') {
    $conv = Conversation::findBySessionUuid($sessionUuid);
}
if ($conv === null) {
    $newConv = Conversation::create($visitorId);
    $conv = Conversation::find($newConv['id']);
    if ($conv === null) {
        sse_fail('failed to create conversation');
    }
}

$conversationId = (int) $conv['id'];

// 既存履歴をロード
$history = [];
foreach (Message::listByConversation($conversationId) as $m) {
    if ($m['role'] === Message::ROLE_USER || $m['role'] === Message::ROLE_ASSISTANT) {
        $history[] = ['role' => $m['role'], 'content' => $m['content']];
    }
}

// ----------------------------------------
// セッション情報を最初に送る（クライアントはこれを保存）
// ----------------------------------------
sse_send('session', [
    'session_uuid' => $conv['session_uuid'],
    'conversation_id' => $conversationId,
]);

// ----------------------------------------
// 生成パイプライン実行
// ----------------------------------------
try {
    $generator = Generator::fromConfig();
    foreach ($generator->generate($message, $conversationId, $history) as $event) {
        $type = $event['type'] ?? 'token';
        sse_send($type, $event);
        // 切断は記録するがループは続行（assistant メッセージ保存まで完走させたい）
        // ignore_user_abort(true) によりPHPは終了しない
        if (connection_aborted()) {
            // クライアント切断後はSSE送信意味なし、ループを抜けても Generator 側で
            // 既に assistant メッセージは保存されている（done イベント発火時点）
            break;
        }
    }
} catch (\Throwable $e) {
    // メッセージ・スタックトレースともに APIキー等の機密が混入しうるため必ずサニタイズしてから記録する。
    // （スタックトレースの引数に平文キーが載る可能性があるため getTraceAsString も対象にする）
    $logDetail = \App\Llm\LlmException::sanitize(
        $e::class . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString()
    );
    @file_put_contents(
        __DIR__ . '/../../storage/logs/chat-error.log',
        '[' . date('c') . '] ' . $logDetail . "\n\n",
        FILE_APPEND
    );
    error_log('[chat.php] ' . $logDetail);
    // ユーザー向けには汎用メッセージ（APIキー等の機密情報漏洩防止）
    $publicMsg = ($e instanceof \App\Llm\LlmException)
        ? $e->publicMessage()
        : 'システムエラーが発生しました。時間をおいて再度お試しください。';
    sse_send('error', ['message' => $publicMsg]);
}

sse_send('end', ['ok' => true]);
