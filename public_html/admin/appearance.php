<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
admin_require_login();

use App\Models\Settings;

$saved = false;
$error = null;

// 現在値の読み込み
$productName = (string) (Settings::get('product_name') ?? '');
$welcome     = (string) (Settings::get('welcome_message') ?? '');
$topic       = (string) (Settings::get('topic_description') ?? '');
$qrRaw       = (string) (Settings::get('opening_quick_replies') ?? '[]');
$qrList      = [];
$decoded = json_decode($qrRaw, true);
if (is_array($decoded)) {
    foreach ($decoded as $q) {
        if (is_string($q) && trim($q) !== '') { $qrList[] = trim($q); }
    }
}
$qrText = implode("\n", $qrList);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_csrf_check();
    $productName = trim((string) ($_POST['product_name'] ?? ''));
    $welcome     = trim((string) ($_POST['welcome_message'] ?? ''));
    $topic       = trim((string) ($_POST['topic_description'] ?? ''));
    // 候補質問は 1問=1入力欄（配列）で受け取る
    $qrInput     = $_POST['quick_replies'] ?? [];
    if (!is_array($qrInput)) { $qrInput = []; }

    // 入力の軽いバリデーション（長すぎる値を弾く）
    if (mb_strlen($productName) > 100) {
        $error = 'ボット名は100文字以内で入力してください。';
    } elseif (mb_strlen($welcome) > 1000 || mb_strlen($topic) > 2000) {
        $error = 'あいさつ文/回答範囲が長すぎます。';
    } else {
        // 各欄 → 空欄除去・各120文字・最大8件
        $qrArr = [];
        foreach ($qrInput as $l) {
            $l = trim((string) $l);
            if ($l !== '') { $qrArr[] = mb_substr($l, 0, 120); }
            if (count($qrArr) >= 4) { break; }
        }
        Settings::set('product_name', $productName);
        Settings::set('welcome_message', $welcome);
        Settings::set('topic_description', $topic);
        Settings::set('opening_quick_replies', json_encode(array_values($qrArr), JSON_UNESCAPED_UNICODE));
        $saved = true;
        $qrList = $qrArr; // 再描画用
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>表示設定 - 管理画面</title>
<link rel="stylesheet" href="../assets/vendor/tailwind.min.css">
</head>
<body class="bg-gray-50">
<?php include __DIR__ . '/_nav.php'; ?>

<main class="max-w-3xl mx-auto px-6 py-8">
  <h1 class="text-2xl font-bold mb-1">🎨 表示設定</h1>
  <p class="text-sm text-gray-500 mb-6">チャット画面の見た目・文言を設定します。下の①〜④が、実際のチャットのどこに表示されるかは右のイメージのとおりです。</p>

  <?php
    $pvName = $productName !== '' ? $productName : 'ボット名（未設定）';
    $pvWelcome = $welcome !== '' ? $welcome : 'あいさつ文（未設定）';
    $pvQr = $qrList;
  ?>
  <div class="mb-6">
    <p class="text-sm font-medium text-gray-700 mb-2">📱 チャット画面での表示イメージ</p>
    <div style="max-width:360px;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;background:#fff">
      <div style="background:#2563eb;color:#fff;padding:10px 14px;font-size:14px;font-weight:600;display:flex;align-items:center;gap:8px">
        <span style="background:#fff;color:#2563eb;border-radius:50%;width:18px;height:18px;display:inline-flex;align-items:center;justify-content:center;font-size:11px;font-weight:700">1</span>
        <?= htmlspecialchars($pvName, ENT_QUOTES, 'UTF-8') ?>
      </div>
      <div style="padding:14px;background:#f9fafb">
        <div style="display:flex;gap:8px;margin-bottom:12px">
          <div style="flex:none;width:30px;height:30px;border-radius:50%;background:#e0e7ff;color:#4338ca;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700">AI</div>
          <div style="max-width:80%;background:#fff;border:1px solid #e5e7eb;color:#1f2937;padding:8px 12px;border-radius:3px 12px 12px 12px;font-size:13px;line-height:1.6">
            <span style="background:#d1fae5;color:#065f46;border-radius:50%;width:18px;height:18px;display:inline-flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;margin-right:4px">2</span>
            <?= htmlspecialchars($pvWelcome, ENT_QUOTES, 'UTF-8') ?>
          </div>
        </div>
        <div style="margin-left:38px;display:flex;flex-wrap:wrap;gap:6px;align-items:center">
          <span style="background:#fef3c7;color:#92400e;border-radius:50%;width:18px;height:18px;display:inline-flex;align-items:center;justify-content:center;font-size:11px;font-weight:700">3</span>
          <?php if ($pvQr === []): ?>
            <span style="color:#9ca3af;font-size:12px">候補質問（未設定）</span>
          <?php else: foreach ($pvQr as $q): ?>
            <span style="border:1px solid #93c5fd;color:#1d4ed8;background:#eff6ff;border-radius:16px;padding:4px 10px;font-size:12px"><?= htmlspecialchars(mb_strimwidth($q, 0, 24, '…'), ENT_QUOTES, 'UTF-8') ?></span>
          <?php endforeach; endif; ?>
        </div>
      </div>
    </div>
    <p class="text-xs text-gray-400 mt-2">
      <span style="background:#ede9fe;color:#5b21b6;border-radius:50%;width:16px;height:16px;display:inline-flex;align-items:center;justify-content:center;font-size:10px;font-weight:700">4</span>
      回答できる範囲：画面には表示されません（AIが範囲外の質問を丁寧に断るための内部設定）。
    </p>
  </div>

  <?php if ($saved): ?>
    <div class="mb-4 rounded border border-green-200 bg-green-50 text-green-800 text-sm px-4 py-2">保存しました。チャット画面に反映されます。</div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="mb-4 rounded border border-red-200 bg-red-50 text-red-700 text-sm px-4 py-2"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
  <?php endif; ?>

  <form method="post" class="bg-white rounded-lg shadow p-6 space-y-6">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars(admin_csrf_token()) ?>">

    <div>
      <label class="block text-sm font-medium text-gray-700 mb-1"><span style="background:#2563eb;color:#fff;border-radius:50%;width:18px;height:18px;display:inline-flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;margin-right:6px">1</span>ボット名</label>
      <input type="text" name="product_name" maxlength="100" value="<?= htmlspecialchars($productName, ENT_QUOTES, 'UTF-8') ?>"
             placeholder="例：◯◯サポートAI"
             class="w-full border border-gray-300 rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-400">
      <p class="text-xs text-gray-400 mt-1">チャット画面いちばん上（ヘッダ）に表示される名前です。</p>
    </div>

    <div>
      <label class="block text-sm font-medium text-gray-700 mb-1"><span style="background:#10b981;color:#fff;border-radius:50%;width:18px;height:18px;display:inline-flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;margin-right:6px">2</span>あいさつ文（最初のメッセージ）</label>
      <textarea name="welcome_message" rows="2" maxlength="1000"
                placeholder="例：こんにちは！ご質問を入力してください。"
                class="w-full border border-gray-300 rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-400"><?= htmlspecialchars($welcome, ENT_QUOTES, 'UTF-8') ?></textarea>
      <p class="text-xs text-gray-400 mt-1">チャットを開いたとき、AIが最初に出す吹き出しメッセージです。</p>
    </div>

    <div>
      <label class="block text-sm font-medium text-gray-700 mb-1"><span style="background:#f59e0b;color:#fff;border-radius:50%;width:18px;height:18px;display:inline-flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;margin-right:6px">3</span>候補質問（クリックできる質問ボタン）</label>
      <p class="text-xs text-gray-400 mb-2">あいさつの下に並ぶ、押すだけで質問できるボタンです。<strong>4つの枠</strong>にそれぞれ1件ずつ入力します（空欄は表示されません）。</p>
      <?php for ($i = 0; $i < 4; $i++): ?>
        <div class="flex items-center gap-2 mb-2">
          <span class="text-xs text-gray-400 w-12">質問<?= $i + 1 ?></span>
          <input type="text" name="quick_replies[]" maxlength="120"
                 value="<?= htmlspecialchars($qrList[$i] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                 placeholder="例：<?= ['この製品の使い方を教えて', '料金について', '導入までの流れ', 'サポート・保証について'][$i] ?>"
                 class="flex-1 border border-gray-300 rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-400">
        </div>
      <?php endfor; ?>
    </div>

    <div>
      <label class="block text-sm font-medium text-gray-700 mb-1"><span style="background:#8b5cf6;color:#fff;border-radius:50%;width:18px;height:18px;display:inline-flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;margin-right:6px">4</span>回答できる範囲（AIへの内部指示）</label>
      <textarea name="topic_description" rows="3" maxlength="2000"
                placeholder="例：このチャットボットは当社の製品・サービスに関する質問に回答します。"
                class="w-full border border-gray-300 rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-400"><?= htmlspecialchars($topic, ENT_QUOTES, 'UTF-8') ?></textarea>
      <p class="text-xs text-gray-400 mt-1"><strong>画面には表示されません。</strong>AIが「どこまで答えてよいか」を判断し、範囲外の質問を丁寧に断るための内部設定です。</p>
    </div>

    <div class="flex items-center gap-3">
      <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-medium px-6 py-2 rounded transition">保存</button>
    </div>
  </form>
</main>
</body>
</html>
