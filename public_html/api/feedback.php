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
$reason = isset($payload['reason'])
    ? trim(mb_substr((string) $payload['reason'], 0, 200))
    : '';
$freeComment = isset($payload['comment'])
    ? trim(mb_substr((string) $payload['comment'], 0, 2000))
    : '';
// アンケート回答（理由＋自由記述）を1つの comment 列に構造化して保存（スキーマ変更なし）
$commentParts = [];
if ($reason !== '') {
    $commentParts[] = '【理由】' . $reason;
}
if ($freeComment !== '') {
    $commentParts[] = '【コメント】' . $freeComment;
}
$comment = $commentParts === [] ? null : implode("\n", $commentParts);
$sessionUuid = (string) ($payload['session_uuid'] ?? '');

$validRatings = [
    Feedback::RATING_UP,
    Feedback::RATING_DOWN,
    Feedback::RATING_RESOLVED,
    Feedback::RATING_UNRESOLVED,
];
if ($messageId <= 0 || !in_array($rating, $validRatings, true) || $sessionUuid === '') {
    http_response_code(400);
    echo json_encode(['error' => 'message_id, rating, session_uuid are required']);
    exit;
}

try {
    // 認可: session_uuid と message_id が同じ会話に属するか検証
    // 他人の会話を「解決済み」にされたり、👍👎 を任意のmessage_idに付けられたりするのを防ぐ
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
