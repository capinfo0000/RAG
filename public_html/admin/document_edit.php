<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
admin_require_login();

use App\Knowledge\Indexer;
use App\Models\Document;

/** storage/uploads（書き込み可能領域）。ここ配下のファイルのみ本体を上書きする。 */
const EDIT_UPLOAD_DIR = __DIR__ . '/../../storage/uploads';

$flash = null;
$flashType = 'error';
$id = (int) ($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_csrf_check();
    $id = (int) ($_POST['document_id'] ?? 0);
    $title = trim((string) ($_POST['title'] ?? ''));
    $category = trim((string) ($_POST['category'] ?? ''));
    $body = (string) ($_POST['body'] ?? '');
    try {
        $doc = Document::find($id);
        if ($doc === null) {
            throw new \RuntimeException('資料が見つかりません。');
        }
        if ($title === '') {
            throw new \RuntimeException('タイトルを入力してください。');
        }
        if (trim($body) === '') {
            throw new \RuntimeException('本文を入力してください。');
        }
        // storage/uploads 内のファイルなら本体も上書き（🔄再取込との整合）
        $real = realpath((string) $doc['storage_path']);
        $base = realpath(EDIT_UPLOAD_DIR);
        if ($real !== false && $base !== false && strpos($real, $base) === 0) {
            if (file_put_contents($real, $body) === false) {
                throw new \RuntimeException('ファイルの保存に失敗しました。');
            }
        }
        Indexer::fromConfig()->updateContent($id, $title, $category !== '' ? $category : null, $body);
        header('Location: knowledge.php?edited=' . $id);
        exit;
    } catch (\Throwable $e) {
        $flash = '❌ 保存に失敗しました: ' . $e->getMessage();
        error_log('[document_edit] ' . $e::class . ': ' . $e->getMessage());
    }
}

$doc = Document::find($id);
$cats = Document::distinctCategories();
// storage/uploads 外（＝フォルダ同期などで管理）の資料かどうか
$isManagedElsewhere = false;
if ($doc !== null) {
    $real = realpath((string) $doc['storage_path']);
    $base = realpath(EDIT_UPLOAD_DIR);
    $isManagedElsewhere = !($real !== false && $base !== false && strpos($real, $base) === 0);
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>資料の編集 - 管理画面</title>
<link rel="stylesheet" href="../assets/vendor/tailwind.min.css">
</head>
<body class="bg-gray-50">
<?php include __DIR__ . '/_nav.php'; ?>

<main class="max-w-4xl mx-auto px-6 py-8">
  <div class="flex items-center justify-between mb-6">
    <h1 class="text-2xl font-bold">✏️ 資料の編集</h1>
    <a href="knowledge.php" class="text-sm text-gray-500 hover:text-gray-700">← 資料一覧へ戻る</a>
  </div>

  <?php if ($flash !== null): ?>
    <div class="mb-4 px-4 py-3 rounded bg-red-100 text-red-800 text-sm"><?= htmlspecialchars($flash) ?></div>
  <?php endif; ?>

  <?php if ($doc === null): ?>
    <div class="bg-white rounded-lg shadow p-6 text-gray-500">指定された資料が見つかりません。<a href="knowledge.php" class="text-blue-600">一覧へ戻る</a></div>
  <?php else: ?>
    <?php if ($isManagedElsewhere): ?>
      <div class="mb-4 px-4 py-3 rounded bg-amber-50 text-amber-800 text-sm">
        ⚠️ この資料はフォルダ同期などで管理されています。ここでの編集内容は、次回の同期で上書きされる場合があります。
      </div>
    <?php endif; ?>

    <form method="post" class="bg-white rounded-lg shadow p-6 space-y-4"
          onsubmit="return confirm('この資料を上書き保存し、再取り込みします。よろしいですか？');">
      <input type="hidden" name="_csrf" value="<?= htmlspecialchars(admin_csrf_token()) ?>">
      <input type="hidden" name="document_id" value="<?= (int) $doc['id'] ?>">

      <div class="grid grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">タイトル</label>
          <input type="text" name="title" required value="<?= htmlspecialchars((string) $doc['title']) ?>"
                 class="w-full border border-gray-300 rounded px-3 py-2">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">カテゴリ</label>
          <input type="text" name="category" list="cat-presets" value="<?= htmlspecialchars((string) ($doc['category'] ?? '')) ?>"
                 class="w-full border border-gray-300 rounded px-3 py-2">
          <datalist id="cat-presets">
            <?php foreach ($cats as $c): ?><option value="<?= htmlspecialchars($c) ?>"></option><?php endforeach; ?>
          </datalist>
        </div>
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">本文</label>
        <textarea name="body" rows="20"
                  class="w-full border border-gray-300 rounded px-3 py-2 font-mono text-sm"><?= htmlspecialchars((string) ($doc['full_text'] ?? '')) ?></textarea>
        <p class="text-xs text-gray-400 mt-1">保存すると、この本文で再チャンク・再ベクトル化され、以降の回答に反映されます。</p>
      </div>

      <div class="flex items-center gap-3">
        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2 rounded font-medium">
          保存して再取込
        </button>
        <a href="knowledge.php" class="text-sm text-gray-500 hover:text-gray-700">キャンセル</a>
      </div>
    </form>
  <?php endif; ?>
</main>
</body>
</html>
