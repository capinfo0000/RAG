<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
admin_require_login();

use App\Models\Conversation;
use App\Models\Message;

$id = (int) ($_GET['id'] ?? 0);
$conv = $id > 0 ? Conversation::find($id) : null;
$messages = $conv ? Message::listByConversation($id) : [];

$roleMeta = [
    'user'      => ['label' => '質問',   'bubble' => 'background:#2563eb;color:#fff;'],
    'assistant' => ['label' => '回答',   'bubble' => 'background:#fff;border:1px solid #e5e7eb;color:#1f2937;'],
    'system'    => ['label' => 'system', 'bubble' => 'background:#f3f4f6;color:#6b7280;'],
];
$resolvedLabel = '';
if ($conv) {
    $resolvedLabel = ($conv['resolved'] === '1') ? '✅ 解決'
        : (($conv['resolved'] === '0') ? '❌ 未解決' : '─ 未確認');
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<title>会話詳細 - 管理画面</title>
<link rel="stylesheet" href="../assets/vendor/tailwind.min.css">
</head>
<body class="bg-gray-50">
<?php include __DIR__ . '/_nav.php'; ?>

<main class="max-w-4xl mx-auto px-6 py-8">
  <a href="index.php" class="text-sm text-blue-600 hover:underline">← ダッシュボードに戻る</a>

  <?php if (!$conv): ?>
    <p class="mt-6 text-gray-500">会話が見つかりませんでした。</p>
  <?php else: ?>
    <h1 class="text-xl font-bold mt-3 mb-1">会話の内容</h1>
    <p class="text-sm text-gray-500 mb-6">
      開始: <?= htmlspecialchars((string) $conv['started_at']) ?>
      ・メッセージ <?= (int) $conv['message_count'] ?> 件
      ・<?= $resolvedLabel ?>
    </p>

    <div style="display:flex;flex-direction:column;gap:14px;">
      <?php foreach ($messages as $m): ?>
        <?php
          $role = (string) ($m['role'] ?? 'assistant');
          $meta = $roleMeta[$role] ?? $roleMeta['assistant'];
          $isUser = ($role === 'user');
          $cits = $m['citations'] ?? null;
          if (is_string($cits)) { $cits = json_decode($cits, true); }
          $content = trim((string) ($m['content'] ?? ''));
          $content = preg_replace("/[ \t]+\n/", "\n", $content);   // 行末の空白を除去
          $content = preg_replace("/\n{3,}/", "\n\n", $content);    // 3行以上の空きを1行に圧縮
        ?>
        <div style="display:flex;justify-content:<?= $isUser ? 'flex-end' : 'flex-start' ?>;">
          <div style="max-width:82%;">
            <div style="font-size:11px;color:#9ca3af;margin-bottom:2px;text-align:<?= $isUser ? 'right' : 'left' ?>;">
              <?= htmlspecialchars($meta['label']) ?> ・ <?= htmlspecialchars((string) ($m['created_at'] ?? '')) ?>
            </div>
            <div style="border-radius:12px;padding:8px 12px;white-space:pre-wrap;word-break:break-word;font-size:14px;line-height:1.55;<?= $meta['bubble'] ?>"><?= htmlspecialchars($content) ?></div>
            <?php if (!$isUser && is_array($cits) && $cits !== []): ?>
              <div style="font-size:11px;color:#6b7280;margin-top:3px;">
                出典 <?= count($cits) ?> 件<?php if (isset($m['confidence_score']) && $m['confidence_score'] !== null && $m['confidence_score'] !== ''): ?> ・確信度 <?= htmlspecialchars((string) $m['confidence_score']) ?><?php endif; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
      <?php if ($messages === []): ?>
        <p class="text-gray-400 text-sm">メッセージがありません。</p>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</main>
</body>
</html>
