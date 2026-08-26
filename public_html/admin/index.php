<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
admin_require_login();

use App\Models\Conversation;
use App\Models\Document;
use App\Models\Chunk;
use App\Models\Feedback;
use App\Models\Message;
use App\Models\Settings;
use App\Models\UsageLog;
use App\Config;

$convStats = Conversation::stats(7);
$fbStats = Feedback::stats(7);
$docCount = Document::count();
$chunkCount = Chunk::count();
$recentConvs = Conversation::listRecent(10);
$surveyResponses = Feedback::recentWithComments(30);

// この管理画面は顧客専用。トークン/コスト・稼働状態・LLM設定などベンダー内部情報は表示しない
// （使用量/課金/停止はベンダーの統括コンソールで管理）。
// ただし「今月のご利用可能量」は“残量％のみ”表示する（トークン数・上限値・原価は一切出さない＝逆算防止）。
$remainPct = null; // null = 無制限（表示しない）
$monthlyLimit = (int) Config::get('MONTHLY_TOKEN_LIMIT', 0);
if ($monthlyLimit > 0) {
    try {
        // 全API消費（チャット回答・埋め込み・AIで作成・改善提案）を計上した統一台帳の当月合算。
        $used = UsageLog::monthlyTotal();
    } catch (\Throwable) {
        $used = 0;
    }
    $usedPct = (int) min(100, max(0, round($used / $monthlyLimit * 100)));
    $remainPct = 100 - $usedPct;
}

// rating → ラベル/色のマップ（アンケート一覧表示用）
$ratingMeta = [
    'up' => ['label' => '👍 役立った', 'class' => 'bg-blue-100 text-blue-700'],
    'down' => ['label' => '👎 役立たず', 'class' => 'bg-amber-100 text-amber-700'],
    'resolved' => ['label' => '✓ 解決', 'class' => 'bg-green-100 text-green-700'],
    'unresolved' => ['label' => '✗ 未解決', 'class' => 'bg-red-100 text-red-700'],
];
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<title>ダッシュボード - 管理画面</title>
<link rel="stylesheet" href="../assets/vendor/tailwind.min.css">
</head>
<body class="bg-gray-50">
<?php include __DIR__ . '/_nav.php'; ?>

<main class="max-w-6xl mx-auto px-6 py-8">
  <h1 class="text-2xl font-bold mb-6">ダッシュボード</h1>

  <?php
  $cards = [
    ['title' => '資料', 'value' => $docCount, 'color' => '#1e40af'],
    ['title' => '会話 (7日)', 'value' => $convStats['total'], 'color' => '#166534'],
    ['title' => '解決率 (7日)', 'value' => $convStats['total'] > 0 ? round($convStats['resolved'] / $convStats['total'] * 100) . '%' : '-', 'color' => '#6b21a8'],
  ];
  ?>
  <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;">
    <?php foreach ($cards as $c): ?>
      <div class="bg-white rounded-lg shadow" style="padding:16px 20px;">
        <p style="font-size:12px;color:#6b7280;"><?= htmlspecialchars($c['title']) ?></p>
        <p style="font-size:30px;font-weight:700;color:<?= $c['color'] ?>;margin-top:4px;"><?= htmlspecialchars((string) $c['value']) ?></p>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="bg-white rounded-lg shadow" style="margin-top:16px;display:flex;align-items:center;justify-content:space-between;padding:14px 20px;flex-wrap:wrap;gap:8px;">
    <span style="font-size:14px;color:#6b7280;">フィードバック (7日)</span>
    <span style="font-size:14px;color:#374151;display:flex;gap:18px;align-items:center;">
      <span>👍 <b style="font-size:18px;"><?= $fbStats['up'] ?></b></span>
      <span>👎 <b style="font-size:18px;"><?= $fbStats['down'] ?></b></span>
      <span>✓解決 <b style="font-size:18px;color:#166534;"><?= $fbStats['resolved'] ?></b></span>
      <span>未解決 <b style="font-size:18px;color:#b91c1c;"><?= $fbStats['unresolved'] ?></b></span>
    </span>
  </div>

  <?php if ($remainPct !== null): ?>
  <?php
    // バーは「使用率(%)」。使うほど埋まる。数値はトークン数を出さず％のみ（逆算防止）。
    $barColor = $usedPct >= 80 ? 'bg-red-500' : ($usedPct >= 60 ? 'bg-amber-500' : 'bg-blue-500');
    $txtColor = $usedPct >= 80 ? 'text-red-600' : ($usedPct >= 60 ? 'text-amber-600' : 'text-gray-700');
  ?>
  <section class="bg-white rounded-lg shadow p-5 mt-6">
    <div class="flex items-center justify-between mb-2">
      <h2 class="font-bold">今月のご利用状況</h2>
      <span class="text-lg font-bold <?= $txtColor ?>">残り <?= $remainPct ?>%</span>
    </div>
    <div class="w-full bg-gray-200 rounded h-2">
      <div class="h-2 rounded <?= $barColor ?>" style="width: <?= max(0, min(100, $usedPct)) ?>%"></div>
    </div>
    <p class="text-xs text-gray-400 mt-2">
      毎月1日にリセットされます（残り <?= $remainPct ?>%）。
      <?php if ($usedPct >= 100): ?>今月の上限に達しました。翌月にリセットされます。<?php elseif ($usedPct >= 80): ?>上限が近づいています。<?php elseif ($usedPct >= 60): ?>ご利用が増えています。<?php endif; ?>
    </p>
  </section>
  <?php endif; ?>

  <div class="mt-8">
    <section class="bg-white rounded-lg shadow p-5">
      <h2 class="font-bold mb-3">直近の会話</h2>
      <ul class="text-sm divide-y divide-gray-100">
        <?php foreach ($recentConvs as $c): ?>
          <?php $q = trim((string) ($c['first_question'] ?? '')); ?>
          <li>
            <a href="conversation.php?id=<?= (int) $c['id'] ?>" title="クリックで会話の内容を表示" style="display:grid;grid-template-columns:minmax(0,2fr) minmax(0,1fr) 90px 44px;align-items:center;gap:8px;padding:8px 4px;text-decoration:none;color:inherit;border-radius:6px;" onmouseover="this.style.background='#f9fafb'" onmouseout="this.style.background='transparent'">
              <span style="font-size:12px;color:#374151;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php if ($q !== ''): ?><?= htmlspecialchars(mb_strimwidth($q, 0, 40, '…')) ?><?php else: ?><span style="color:#9ca3af;">(質問なし)</span><?php endif; ?></span>
              <span style="font-size:12px;color:#374151;"><?= htmlspecialchars($c['started_at']) ?></span>
              <span style="font-size:12px;text-align:right;"><?= $c['message_count'] ?> 件</span>
              <span style="text-align:right;"><?= $c['resolved'] === '1' ? '✅' : ($c['resolved'] === '0' ? '❌' : '─') ?></span>
            </a>
          </li>
        <?php endforeach; ?>
        <?php if ($recentConvs === []): ?>
          <li class="py-4 text-center text-gray-400 text-sm">まだ会話はありません</li>
        <?php endif; ?>
      </ul>
    </section>
  </div>

  <!-- ===================== アンケート回答（改善のヒント） ===================== -->
  <section class="bg-white rounded-lg shadow p-5 mt-6">
    <h2 class="font-bold mb-1">アンケート回答（直近30件）</h2>
    <p class="text-xs text-gray-500 mb-4">解決・未解決時にユーザーが入力した理由と自由記述です。今後の資料改善にご活用ください。</p>
    <?php if ($surveyResponses === []): ?>
      <p class="py-6 text-center text-gray-400 text-sm">まだアンケート回答はありません</p>
    <?php else: ?>
      <ul class="divide-y divide-gray-100">
        <?php foreach ($surveyResponses as $fb): ?>
          <?php $meta = $ratingMeta[$fb['rating']] ?? ['label' => $fb['rating'], 'class' => 'bg-gray-100 text-gray-700']; ?>
          <li class="py-3">
            <div class="flex items-center gap-2 mb-1">
              <span class="text-xs font-medium px-2 py-0.5 rounded <?= htmlspecialchars($meta['class']) ?>"><?= htmlspecialchars($meta['label']) ?></span>
              <span class="text-xs text-gray-400"><?= htmlspecialchars((string) $fb['created_at']) ?></span>
            </div>
            <p class="text-sm text-gray-800 whitespace-pre-line"><?= htmlspecialchars((string) $fb['comment']) ?></p>
            <p class="text-xs text-gray-400 mt-1 truncate">対象回答: <?= htmlspecialchars(mb_substr((string) $fb['message_content'], 0, 80)) ?>…</p>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
</main>
</body>
</html>
