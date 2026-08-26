<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

// Brute force 対策: IP単位の試行回数制限（5回失敗 → 10分ロック）
// キーは接続元 REMOTE_ADDR を使用。X-Forwarded-For はクライアントが任意に付与でき、
// リクエスト毎に値を変えるとロックアウトを回避できてしまうため信頼しない。
$ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
$lockFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rag_adminlogin_' . sha1($ip) . '.json';
$now = time();
$record = is_file($lockFile)
    ? (json_decode((string) @file_get_contents($lockFile), true) ?? [])
    : [];
$failCount = (int) ($record['count'] ?? 0);
$lockUntil = (int) ($record['lock_until'] ?? 0);

$error = null;

if ($lockUntil > $now) {
    $error = 'ログイン試行が多すぎます。' . ceil(($lockUntil - $now) / 60) . '分後に再試行してください。';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = trim((string) ($_POST['username'] ?? ''));
    $pass = (string) ($_POST['password'] ?? '');
    if (admin_login($user, $pass)) {
        @unlink($lockFile);
        header('Location: index.php');
        exit;
    }
    $failCount++;
    $newRecord = ['count' => $failCount, 'lock_until' => 0];
    if ($failCount >= 5) {
        $newRecord['lock_until'] = $now + 600; // 10分ロック
        $newRecord['count'] = 0;
    }
    @file_put_contents($lockFile, json_encode($newRecord), LOCK_EX);
    // タイミング攻撃対策の遅延
    usleep(random_int(200000, 600000));
    $error = 'ユーザー名またはパスワードが違います。';
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<title>管理ログイン - RAG Chatbot Demo</title>
<link rel="stylesheet" href="../assets/vendor/tailwind.min.css">
</head>
<body class="bg-gray-100 min-h-screen flex items-center justify-center">
<div class="bg-white shadow-md rounded-lg p-8 w-96">
  <h1 class="text-xl font-bold mb-6">管理画面ログイン</h1>
  <?php if ($error): ?>
    <div class="mb-4 text-sm text-red-600 bg-red-50 border border-red-200 rounded p-2">
      <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
    </div>
  <?php endif; ?>
  <form method="post" class="space-y-4">
    <div>
      <label class="block text-sm font-medium text-gray-700 mb-1">ユーザー名</label>
      <input type="text" name="username" required autofocus
             autocomplete="username"
             class="w-full border border-gray-300 rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-400">
    </div>
    <div>
      <label class="block text-sm font-medium text-gray-700 mb-1">パスワード</label>
      <input type="password" name="password" required
             autocomplete="current-password"
             class="w-full border border-gray-300 rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-400">
    </div>
    <button type="submit"
            class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 rounded transition">
      ログイン
    </button>
  </form>
  <p class="text-xs text-gray-400 mt-4 text-center">
    管理者にお問い合わせください
  </p>
</div>
</body>
</html>
