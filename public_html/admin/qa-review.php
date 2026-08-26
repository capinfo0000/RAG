<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
admin_require_login();

use App\Models\Database;

$message = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['qa_id'] ?? 0);
    $admin = $_SESSION['admin_user'] ?? 'admin';
    if (in_array($action, ['approve', 'reject'], true) && $id > 0) {
        $newStatus = $action === 'approve' ? 'approved' : 'rejected';
        $stmt = Database::pdo()->prepare(
            'UPDATE qa_generated SET status = :s, reviewer = :r, reviewed_at = NOW() WHERE id = :id'
        );
        $stmt->execute([':s' => $newStatus, ':r' => $admin, ':id' => $id]);
        $message = "QA #{$id} を {$newStatus} にしました";
    }
}

// 一覧取得
$pendingStmt = Database::pdo()->prepare(
    'SELECT q.*, d.title AS document_title
     FROM qa_generated q
     INNER JOIN documents d ON d.id = q.document_id
     WHERE q.status = :st
     ORDER BY q.created_at DESC
     LIMIT 100'
);
$pendingStmt->execute([':st' => 'pending']);
$pending = $pendingStmt->fetchAll();

$summary = Database::pdo()->query(
    "SELECT status, COUNT(*) c FROM qa_generated GROUP BY status"
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<title>QA承認 - 管理画面</title>
<script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50">
<?php include __DIR__ . '/_nav.php'; ?>

<main class="max-w-5xl mx-auto px-6 py-8">
  <h1 class="text-2xl font-bold mb-2">📝 自動生成QA 承認</h1>
  <p class="text-sm text-gray-600 mb-6">
    アップロードしたドキュメントから LLM が想定QAを自動生成します。承認するとナレッジに追加され、未承認は表に出ません。
  </p>

  <?php if ($message): ?>
    <div class="mb-6 p-4 bg-green-50 border border-green-200 text-green-800 rounded">
      <?= htmlspecialchars($message) ?>
    </div>
  <?php endif; ?>

  <div class="bg-white rounded-lg shadow p-5 mb-6 flex gap-6">
    <?php foreach (['pending' => '審査待ち', 'approved' => '承認', 'rejected' => '却下'] as $st => $label):
        $cnt = 0;
        foreach ($summary as $s) {
            if ($s['status'] === $st) { $cnt = (int) $s['c']; break; }
        } ?>
      <div>
        <p class="text-xs text-gray-500"><?= $label ?></p>
        <p class="text-2xl font-bold"><?= $cnt ?></p>
      </div>
    <?php endforeach; ?>
  </div>

  <?php if ($pending === []): ?>
    <div class="bg-white rounded-lg shadow p-12 text-center text-gray-500">
      <p class="text-lg mb-2">📭 審査待ちのQAはありません</p>
      <p class="text-sm">
        ナレッジ取込時に QA を自動生成するには、CLIから次を実行してください：<br>
        <code class="text-xs bg-gray-100 px-2 py-1 rounded mt-2 inline-block">
          php -r "require 'vendor/autoload.php'; App\Config::load('.'); ... QaGenerator::fromConfig()->generate(...)"
        </code>
      </p>
    </div>
  <?php else: ?>
    <div class="space-y-3">
      <?php foreach ($pending as $q): ?>
        <div class="bg-white rounded-lg shadow p-5">
          <p class="text-xs text-gray-500 mb-2">
            from: <?= htmlspecialchars($q['document_title']) ?> | created: <?= htmlspecialchars($q['created_at']) ?>
          </p>
          <p class="font-bold mb-2">❓ <?= htmlspecialchars($q['question']) ?></p>
          <p class="text-sm text-gray-700 whitespace-pre-wrap mb-3 pl-4 border-l-2 border-gray-200">
            <?= htmlspecialchars($q['expected_answer']) ?>
          </p>
          <div class="flex gap-2">
            <form method="post" style="display:inline">
              <input type="hidden" name="_csrf" value="<?= htmlspecialchars(admin_csrf_token()) ?>">
              <input type="hidden" name="action" value="approve">
              <input type="hidden" name="qa_id" value="<?= $q['id'] ?>">
              <button class="text-sm bg-green-600 hover:bg-green-700 text-white px-4 py-1.5 rounded">
                ✓ 承認
              </button>
            </form>
            <form method="post" style="display:inline">
              <input type="hidden" name="_csrf" value="<?= htmlspecialchars(admin_csrf_token()) ?>">
              <input type="hidden" name="action" value="reject">
              <input type="hidden" name="qa_id" value="<?= $q['id'] ?>">
              <button class="text-sm bg-gray-200 hover:bg-gray-300 text-gray-700 px-4 py-1.5 rounded">
                却下
              </button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</main>
</body>
</html>
