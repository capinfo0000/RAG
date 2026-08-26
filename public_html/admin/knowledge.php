<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
admin_require_login();

use App\Knowledge\DocumentClassifier;
use App\Knowledge\ExtractorFactory;
use App\Knowledge\Indexer;
use App\Models\Chunk;
use App\Models\Document;
use Ramsey\Uuid\Uuid;

/** アップロード保存先（public 外） */
const UPLOAD_DIR = __DIR__ . '/../../storage/uploads';
const MAX_BYTES = 20 * 1024 * 1024; // 20MB

if (!is_dir(UPLOAD_DIR)) {
    @mkdir(UPLOAD_DIR, 0755, true);
}

$message = null;
$messageType = 'info';
$allowedExt = ExtractorFactory::supportedExtensions();

/** 表示用ラベル（md と markdown は同じ「Markdown」に統一。受付自体は両方とも有効）。 */
$formatLabelMap = ['txt' => 'TXT', 'md' => 'Markdown', 'markdown' => 'Markdown', 'csv' => 'CSV', 'log' => 'LOG'];
$formatLabels = [];
foreach ($allowedExt as $e) {
    $label = $formatLabelMap[$e] ?? strtoupper($e);
    if (!in_array($label, $formatLabels, true)) {
        $formatLabels[] = $label;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_csrf_check();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'upload') {
        try {
            [$savedPath, $origName, $size, $ext, $title, $category] = handle_upload($_FILES['file'] ?? null, $allowedExt);

            // 本文を1回だけ抽出（AIの自動タイトル/カテゴリ判定と取込で共用）
            $text = ExtractorFactory::forFile($savedPath)->extract($savedPath);

            // タイトル/カテゴリが未入力なら、本文をAIが読んで自動生成（手入力があればそちらを優先）
            if ($title === '' || $category === '') {
                $suggest = DocumentClassifier::suggest(
                    $text,
                    Document::distinctCategories(),
                    pathinfo($origName, PATHINFO_FILENAME),
                );
                if ($title === '') {
                    $title = $suggest['title'];
                }
                if ($category === '' && !empty($suggest['category'])) {
                    $category = (string) $suggest['category'];
                }
            }

            $finalTitle = $title !== '' ? $title : $origName;
            $r = Indexer::fromConfig()->ingestFile(
                $savedPath,
                $finalTitle,
                $category !== '' ? $category : null,
                null,
                $text, // 抽出済みテキストを渡して二重抽出を避ける
            );
            $message = sprintf(
                '✅ アップロード完了：「%s」を取り込みました（カテゴリ: %s）。',
                $finalTitle,
                $category !== '' ? $category : '未設定',
            );
            $messageType = 'success';
        } catch (\Throwable $e) {
            $message = '❌ アップロード失敗: ' . $e->getMessage();
            $messageType = 'error';
        }
    } elseif ($action === 'reindex') {
        $id = (int) ($_POST['document_id'] ?? 0);
        try {
            $r = Indexer::fromConfig()->reindex($id);
            $message = '🔄 再取り込みが完了しました。';
            $messageType = 'success';
        } catch (\Throwable $e) {
            $message = '❌ 再インデックス失敗: ' . $e->getMessage();
            $messageType = 'error';
        }
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['document_id'] ?? 0);
        try {
            $doc = Document::find($id);
            Document::delete($id); // CASCADE で chunks/qa も削除

            // 物理ファイル削除: realpath で正規化して upload dir 配下のみ削除（path traversal 対策）
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
                    error_log('[knowledge.delete] refused unlink outside upload dir: ' . $doc['storage_path']);
                }
            }
            $message = "🗑 削除しました: document_id={$id}";
            $messageType = 'success';
        } catch (\Throwable $e) {
            error_log('[knowledge.delete] ' . $e->getMessage());
            $message = '❌ 削除失敗: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

/**
 * @return array{0:string,1:string,2:int,3:string,4:string,5:string}
 *   savedPath, origName, size, ext, title, category
 */
function handle_upload(?array $file, array $allowedExt): array
{
    if (!is_array($file) || !isset($file['error'])) {
        throw new \RuntimeException('ファイルが添付されていません');
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new \RuntimeException('アップロードエラー code=' . $file['error']);
    }
    if ($file['size'] <= 0 || $file['size'] > MAX_BYTES) {
        throw new \RuntimeException('ファイルサイズが不正（最大 ' . (MAX_BYTES / 1024 / 1024) . 'MB）');
    }
    $origName = (string) ($file['name'] ?? 'unknown');
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) {
        throw new \RuntimeException('対応していない拡張子: ' . $ext . '（許可: ' . implode(', ', $allowedExt) . '）');
    }
    // UUID で保存（パストラバーサル防止）
    $safeName = Uuid::uuid4()->toString() . '.' . $ext;
    $dest = UPLOAD_DIR . DIRECTORY_SEPARATOR . $safeName;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        throw new \RuntimeException('ファイル保存失敗');
    }

    $title = trim((string) ($_POST['title'] ?? ''));
    $category = trim((string) ($_POST['category'] ?? ''));
    return [$dest, $origName, (int) $file['size'], $ext, $title, $category];
}

if ($message === null && isset($_GET['edited'])) {
    $message = '✅ 資料を更新し、再取り込みしました。';
    $messageType = 'success';
}

$docs = Document::listRecent(100);
$totalChunks = Chunk::count();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<title>資料管理 - 管理画面</title>
<link rel="stylesheet" href="../assets/vendor/tailwind.min.css">
</head>
<body class="bg-gray-50">
<?php include __DIR__ . '/_nav.php'; ?>

<main class="max-w-6xl mx-auto px-6 py-8">
  <div class="flex items-center justify-between mb-3">
    <h1 class="text-2xl font-bold">📚 資料</h1>
    <p class="text-sm text-gray-500">
      資料 <?= count($docs) ?> 件
    </p>
  </div>

  <?php $active = 'upload'; include __DIR__ . '/_resource_tabs.php'; ?>

  <?php if ($message): ?>
    <div class="mb-6 p-4 rounded border <?= match ($messageType) {
        'success' => 'bg-green-50 border-green-200 text-green-800',
        'error' => 'bg-red-50 border-red-200 text-red-800',
        default => 'bg-blue-50 border-blue-200 text-blue-800',
    } ?>">
      <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
    </div>
  <?php endif; ?>

  <!-- ===================== アップロード ===================== -->
  <section class="bg-white rounded-lg shadow p-6 mb-8">
    <h2 class="text-lg font-bold mb-4">📥 新規ドキュメントをアップロード</h2>
    <form method="post" enctype="multipart/form-data" class="space-y-4">
      <input type="hidden" name="_csrf" value="<?= htmlspecialchars(admin_csrf_token()) ?>">
      <input type="hidden" name="action" value="upload">

      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">ファイル</label>
        <input type="file" name="file" required
               accept=".<?= implode(',.', $allowedExt) ?>"
               class="block w-full border border-gray-300 rounded px-3 py-2 file:mr-3 file:px-3 file:py-1 file:bg-blue-50 file:border-0 file:text-blue-700 file:cursor-pointer">
        <p class="text-xs text-gray-400 mt-1">
          対応形式：<?= implode(' / ', $formatLabels) ?>（1ファイル最大 <?= MAX_BYTES / 1024 / 1024 ?>MB）
        </p>
        <p class="text-xs text-gray-400 mt-1">
          文字情報として正確に読み取れる形式に対応しています。
        </p>
      </div>

      <p class="text-xs text-gray-500">💡 タイトルとカテゴリは、AIが本文を読んで自動でつけます。</p>

      <div>
        <button type="submit"
                class="bg-blue-600 hover:bg-blue-700 text-white font-medium px-5 py-2 rounded">
          アップロードして取り込む
        </button>
        <span class="ml-3 text-xs text-gray-500">アップロードすると自動で検索用に変換され、すぐに回答へ反映されます（数秒〜数十秒）。</span>
      </div>
    </form>
  </section>

  <!-- ===================== ドキュメント一覧 ===================== -->
  <section class="bg-white rounded-lg shadow overflow-hidden">
    <div class="flex items-baseline justify-between p-6 pb-3">
      <h2 class="text-lg font-bold">📋 資料一覧</h2>
      <span class="text-xs text-gray-500"><span style="color:#cbd5e1">▼▲</span> の付いた見出しをクリックで並び替え</span>
    </div>
    <div class="overflow-x-auto">
      <table id="docTable" class="w-full text-sm">
        <thead class="bg-gray-50 border-y border-gray-200">
          <tr>
            <th data-sortable data-col="0" data-type="num" class="text-left px-4 py-2 font-medium cursor-pointer select-none hover:bg-gray-100">ID<span class="sort-caret" style="margin-left:4px;color:#cbd5e1;">▼▲</span></th>
            <th data-sortable data-col="1" data-type="text" class="text-left px-4 py-2 font-medium cursor-pointer select-none hover:bg-gray-100">タイトル<span class="sort-caret" style="margin-left:4px;color:#cbd5e1;">▼▲</span></th>
            <th data-sortable data-col="2" data-type="text" class="text-left px-4 py-2 font-medium cursor-pointer select-none hover:bg-gray-100">タイプ<span class="sort-caret" style="margin-left:4px;color:#cbd5e1;">▼▲</span></th>
            <th data-sortable data-col="3" data-type="text" class="text-left px-4 py-2 font-medium cursor-pointer select-none hover:bg-gray-100">カテゴリ<span class="sort-caret" style="margin-left:4px;color:#cbd5e1;">▼▲</span></th>
            <th data-sortable data-col="4" data-type="text" class="text-left px-4 py-2 font-medium cursor-pointer select-none hover:bg-gray-100">状態<span class="sort-caret" style="margin-left:4px;color:#cbd5e1;">▼▲</span></th>
            <th data-sortable data-col="5" data-type="text" class="text-left px-4 py-2 font-medium cursor-pointer select-none hover:bg-gray-100">登録<span class="sort-caret" style="margin-left:4px;color:#cbd5e1;">▼▲</span></th>
            <th class="text-center px-4 py-2 font-medium">操作</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <?php foreach ($docs as $d): ?>
            <tr class="hover:bg-gray-50">
              <td class="px-4 py-2 text-gray-500" data-sort-value="<?= (int) $d['id'] ?>">#<?= $d['id'] ?></td>
              <td class="px-4 py-2 font-medium" title="<?= htmlspecialchars($d['original_filename']) ?>">
                <?= htmlspecialchars(mb_strimwidth($d['title'], 0, 50, '…')) ?>
              </td>
              <td class="px-4 py-2 text-gray-600 uppercase text-xs"><?= htmlspecialchars($d['file_type']) ?></td>
              <td class="px-4 py-2 text-gray-600"><?= htmlspecialchars($d['category'] ?? '-') ?></td>
              <td class="px-4 py-2" data-sort-value="<?= htmlspecialchars($d['status']) ?>">
                <?php $st = $d['status'];
                  $stLabel = ['indexed' => '取込済み', 'processing' => '取込中', 'failed' => '失敗', 'uploaded' => '受付'][$st] ?? $st; ?>
                <span class="text-xs px-2 py-0.5 rounded-full <?= match ($st) {
                    'indexed' => 'bg-green-100 text-green-800',
                    'processing' => 'bg-yellow-100 text-yellow-800',
                    'failed' => 'bg-red-100 text-red-800',
                    default => 'bg-gray-100 text-gray-700',
                } ?>"><?= htmlspecialchars($stLabel) ?></span>
                <?php if ($st === 'failed' && !empty($d['error_message'])): ?>
                  <p class="text-xs text-red-600 mt-1" title="<?= htmlspecialchars($d['error_message']) ?>">
                    <?= htmlspecialchars(mb_strimwidth($d['error_message'], 0, 40, '…')) ?>
                  </p>
                <?php endif; ?>
              </td>
              <td class="px-4 py-2 text-xs text-gray-500"><?= htmlspecialchars($d['created_at']) ?></td>
              <td class="px-4 py-2">
                <div class="flex gap-1 justify-center">
                  <a href="document_edit.php?id=<?= $d['id'] ?>"
                     class="text-xs bg-blue-50 hover:bg-blue-100 text-blue-700 px-2 py-1 rounded"
                     title="編集">✏️</a>
                  <form method="post" style="display:inline">
                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars(admin_csrf_token()) ?>">
                    <input type="hidden" name="action" value="reindex">
                    <input type="hidden" name="document_id" value="<?= $d['id'] ?>">
                    <button type="submit"
                            class="text-xs bg-gray-100 hover:bg-gray-200 text-gray-700 px-2 py-1 rounded"
                            title="再取り込み">🔄</button>
                  </form>
                  <form method="post" style="display:inline"
                        onsubmit="return confirm('この資料を削除しますか？関連する検索データも削除されます。');">
                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars(admin_csrf_token()) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="document_id" value="<?= $d['id'] ?>">
                    <button type="submit"
                            class="text-xs bg-red-50 hover:bg-red-100 text-red-700 px-2 py-1 rounded"
                            title="削除">🗑</button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if ($docs === []): ?>
            <tr>
              <td colspan="7" class="px-4 py-8 text-center text-gray-400">
                まだ資料がありません。上のフォームから追加してください。
              </td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>

  <p class="mt-6 text-xs text-gray-500 text-center">
    登録した資料は自動で検索用に変換され、チャットの回答の根拠として使われます。
  </p>
</main>

<script>
(function () {
  var table = document.getElementById('docTable');
  if (!table) return;
  var tbody = table.querySelector('tbody');
  var ths = table.querySelectorAll('th[data-sortable]');
  var curCol = -1, curDir = 1;

  function cellValue(tr, idx, type) {
    var td = tr.children[idx];
    if (!td) return type === 'num' ? 0 : '';
    var v = td.getAttribute('data-sort-value');
    if (v === null) v = td.textContent;
    v = (v || '').trim();
    return type === 'num' ? (parseFloat(v.replace(/[^0-9.\-]/g, '')) || 0) : v;
  }

  ths.forEach(function (th) {
    th.addEventListener('click', function () {
      var idx = parseInt(th.getAttribute('data-col'), 10);
      var type = th.getAttribute('data-type') || 'text';
      curDir = (curCol === idx) ? -curDir : 1;
      curCol = idx;
      var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr'));
      // 「資料がありません」の空行はソート対象外
      rows = rows.filter(function (r) { return r.children.length > 1; });
      rows.sort(function (a, b) {
        var x = cellValue(a, idx, type), y = cellValue(b, idx, type);
        var r = (type === 'num') ? (x - y) : String(x).localeCompare(String(y), 'ja');
        return r * curDir;
      });
      rows.forEach(function (r) { tbody.appendChild(r); });
      ths.forEach(function (t) {
        var c = t.querySelector('.sort-caret');
        if (c) { c.textContent = '▼▲'; c.style.color = '#cbd5e1'; }
      });
      var caret = th.querySelector('.sort-caret');
      if (caret) { caret.textContent = curDir > 0 ? '▲' : '▼'; caret.style.color = '#2563eb'; }
    });
  });
})();
</script>

</body>
</html>
