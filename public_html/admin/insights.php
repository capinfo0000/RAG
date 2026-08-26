<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
admin_require_login();

use App\Knowledge\InsightAnalyzer;
use App\Llm\LlmException;
use App\Llm\LlmFactory;
use App\Models\Insight;
use App\Models\UsageLog;

const INSIGHT_PERIOD_DAYS = 30;

$message = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_csrf_check();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'run') {
        @set_time_limit(180);
        try {
            $llm = LlmFactory::create();
            $analyzer = InsightAnalyzer::fromConfig($llm);
            $dataset = $analyzer->collectDataset(INSIGHT_PERIOD_DAYS);

            if (($dataset['questions'] ?? []) === []) {
                $error = '分析対象の質問がありません（直近' . INSIGHT_PERIOD_DAYS . '日）。チャットの利用ログが溜まってからお試しください。';
            } else {
                $runId = Insight::createRun(INSIGHT_PERIOD_DAYS, $llm->getProviderName(), $llm->getModelName());
                try {
                    $result = $analyzer->analyze($dataset);
                    // 改善提案の分析も API 消費。実測トークンを統一台帳へ（best-effort）。
                    $u = $result['usage'] ?? null;
                    if (is_array($u)) {
                        UsageLog::record(
                            UsageLog::KIND_INSIGHT,
                            (int) (($u['input'] ?? 0) + ($u['output'] ?? 0)),
                            $llm->getModelName(),
                            false
                        );
                    }
                    Insight::saveSuggestions($runId, $result['topics']);
                    Insight::completeRun(
                        $runId,
                        count($dataset['questions']),
                        count($dataset['feedback']),
                        count($result['topics']),
                        $result['usage']
                    );
                    $message = '分析が完了しました（改善トピック ' . count($result['topics']) . ' 件）。';
                } catch (\Throwable $e) {
                    Insight::failRun($runId, $e->getMessage());
                    error_log('[insights.php] analyze: ' . $e::class . ': ' . $e->getMessage());
                    $error = '分析中にエラーが発生しました: '
                        . ($e instanceof LlmException ? $e->publicMessage() : 'システムエラーが発生しました。');
                }
            }
        } catch (\Throwable $e) {
            error_log('[insights.php] setup: ' . $e::class . ': ' . $e->getMessage());
            $error = '分析を開始できませんでした。LLM設定（管理画面の設定）をご確認ください。';
        }
    } elseif ($action === 'update_status') {
        $sid = (int) ($_POST['suggestion_id'] ?? 0);
        $newStatus = (string) ($_POST['new_status'] ?? '');
        if ($sid > 0 && Insight::updateSuggestionStatus($sid, $newStatus)) {
            $message = "改善案 #{$sid} のステータスを更新しました。";
        } else {
            $error = 'ステータス更新に失敗しました。';
        }
    }
}

// 表示データ
$latestRun = Insight::latestCompletedRun();
$suggestions = $latestRun !== null ? Insight::listSuggestionsByRun((int) $latestRun['id']) : [];
$runHistory = Insight::listRuns(10);

$priorityMeta = [
    'high' => ['label' => '高', 'class' => 'bg-red-100 text-red-700'],
    'medium' => ['label' => '中', 'class' => 'bg-amber-100 text-amber-700'],
    'low' => ['label' => '低', 'class' => 'bg-gray-100 text-gray-600'],
];
$statusMeta = [
    'open' => ['label' => '未対応', 'class' => 'bg-blue-100 text-blue-700'],
    'addressed' => ['label' => '対応済み', 'class' => 'bg-green-100 text-green-700'],
    'dismissed' => ['label' => '見送り', 'class' => 'bg-gray-100 text-gray-500'],
];
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<title>改善提案 - 管理画面</title>
<link rel="stylesheet" href="../assets/vendor/tailwind.min.css">
</head>
<body class="bg-gray-50">
<?php include __DIR__ . '/_nav.php'; ?>

<main class="max-w-5xl mx-auto px-6 py-8">
  <h1 class="text-2xl font-bold mb-2">💡 改善提案（傾向分析）</h1>
  <p class="text-sm text-gray-600 mb-6">
    直近<?= INSIGHT_PERIOD_DAYS ?>日のユーザー質問とフィードバックをAIが分析し、トピック別に「どの資料を追加・改善すべきか」を提案します。
    件数・未解決数を根拠に提示します（推測ではなくログの集計に基づきます）。
  </p>

  <?php if ($message): ?>
    <div class="mb-6 p-4 bg-green-50 border border-green-200 text-green-800 rounded"><?= htmlspecialchars($message) ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="mb-6 p-4 bg-red-50 border border-red-200 text-red-800 rounded"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <!-- 実行ボタン -->
  <div class="bg-white rounded-lg shadow p-5 mb-6 flex items-center justify-between">
    <div>
      <p class="font-bold">分析を実行</p>
      <p class="text-xs text-gray-500 mt-1">クリックすると最新ログを分析します（数秒〜数十秒かかります）。</p>
    </div>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= htmlspecialchars(admin_csrf_token()) ?>">
      <input type="hidden" name="action" value="run">
      <button class="bg-blue-600 hover:bg-blue-700 text-white font-medium px-5 py-2 rounded"
              onclick="this.disabled=true;this.textContent='分析中…';this.form.submit();">
        🔍 今すぐ分析する
      </button>
    </form>
  </div>

  <!-- 最新分析結果 -->
  <?php if ($latestRun === null): ?>
    <div class="bg-white rounded-lg shadow p-12 text-center text-gray-500">
      <p class="text-lg mb-2">📊 まだ分析結果はありません</p>
      <p class="text-sm">上の「今すぐ分析する」を押すと、最初のレポートが生成されます。</p>
    </div>
  <?php else: ?>
    <section class="bg-white rounded-lg shadow p-5 mb-6">
      <div class="flex items-center justify-between mb-3">
        <h2 class="font-bold">最新の分析結果</h2>
        <span class="text-xs text-gray-400">
          実行: <?= htmlspecialchars((string) $latestRun['created_at']) ?>
          ／ 対象質問 <?= (int) $latestRun['question_count'] ?>件
          ／ フィードバック <?= (int) $latestRun['feedback_count'] ?>件
        </span>
      </div>

      <?php if ($suggestions === []): ?>
        <p class="py-6 text-center text-gray-400 text-sm">改善トピックは検出されませんでした。</p>
      <?php else: ?>
        <ul class="space-y-3">
          <?php foreach ($suggestions as $s): ?>
            <?php
              $pm = $priorityMeta[$s['priority']] ?? $priorityMeta['medium'];
              $sm = $statusMeta[$s['status']] ?? $statusMeta['open'];
            ?>
            <li class="border border-gray-200 rounded-lg p-4 <?= $s['status'] === 'dismissed' ? 'opacity-60' : '' ?>">
              <div class="flex items-center gap-2 mb-2 flex-wrap">
                <span class="text-xs font-bold px-2 py-0.5 rounded <?= htmlspecialchars($pm['class']) ?>">優先度: <?= htmlspecialchars($pm['label']) ?></span>
                <span class="text-xs font-medium px-2 py-0.5 rounded <?= htmlspecialchars($sm['class']) ?>"><?= htmlspecialchars($sm['label']) ?></span>
                <span class="font-bold text-gray-800"><?= htmlspecialchars($s['topic_label']) ?></span>
                <span class="text-xs text-gray-500">
                  質問 <?= (int) $s['question_count'] ?>件 / 未解決 <?= (int) $s['unresolved_count'] ?>件
                </span>
              </div>

              <p class="text-sm text-gray-800 mb-2">
                <span class="font-medium">改善案:</span> <?= htmlspecialchars($s['suggested_action']) ?>
              </p>

              <?php if (trim((string) $s['representative_question']) !== ''): ?>
                <p class="text-xs text-gray-500 mb-2">代表質問: <?= htmlspecialchars($s['representative_question']) ?></p>
              <?php endif; ?>

              <?php if (($s['sample_message_ids'] ?? []) !== []): ?>
                <p class="text-xs text-gray-400 mb-2">
                  根拠メッセージID: <?= htmlspecialchars(implode(', ', array_map('strval', $s['sample_message_ids']))) ?>
                </p>
              <?php endif; ?>

              <div class="flex gap-2 flex-wrap">
                <?php
                  $rq = trim((string) ($s['representative_question'] ?? ''));
                  $seed = "以下の「改善提案」を資料に反映してください。この内容に的確に答えられる資料（Markdown）を作成します。既存資料と重複・関連するテーマなら、その資料を拡張する前提でまとめ（見出しでどの資料の補足／改訂かを明記）、関連の無い新規テーマなら独立した新規資料として作成してください。不明点があれば質問してください。\n\n"
                      . "テーマ: " . (string) $s['topic_label'] . "\n"
                      . "改善案: " . (string) $s['suggested_action'] . "\n"
                      . ($rq !== '' ? ("代表的な質問: " . $rq . "\n") : '');
                ?>
                <form method="post" action="compose.php" style="display:inline">
                  <input type="hidden" name="_csrf" value="<?= htmlspecialchars(admin_csrf_token()) ?>">
                  <input type="hidden" name="action" value="seed">
                  <input type="hidden" name="fresh" value="1">
                  <input type="hidden" name="instruction" value="<?= htmlspecialchars($seed, ENT_QUOTES, 'UTF-8') ?>">
                  <button class="text-xs px-3 py-1 rounded" style="background:#4f46e5;color:#fff;font-weight:600;">✍️ AIで作成に送る</button>
                </form>
                <?php if ($s['status'] !== 'addressed'): ?>
                  <form method="post" style="display:inline">
                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars(admin_csrf_token()) ?>">
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="suggestion_id" value="<?= (int) $s['id'] ?>">
                    <input type="hidden" name="new_status" value="addressed">
                    <button class="text-xs bg-green-600 hover:bg-green-700 text-white px-3 py-1 rounded">✓ 対応済みにする</button>
                  </form>
                <?php endif; ?>
                <?php if ($s['status'] !== 'dismissed'): ?>
                  <form method="post" style="display:inline">
                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars(admin_csrf_token()) ?>">
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="suggestion_id" value="<?= (int) $s['id'] ?>">
                    <input type="hidden" name="new_status" value="dismissed">
                    <button class="text-xs bg-gray-200 hover:bg-gray-300 text-gray-700 px-3 py-1 rounded">見送り</button>
                  </form>
                <?php endif; ?>
                <?php if ($s['status'] !== 'open'): ?>
                  <form method="post" style="display:inline">
                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars(admin_csrf_token()) ?>">
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="suggestion_id" value="<?= (int) $s['id'] ?>">
                    <input type="hidden" name="new_status" value="open">
                    <button class="text-xs bg-gray-100 hover:bg-gray-200 text-gray-600 px-3 py-1 rounded">未対応に戻す</button>
                  </form>
                <?php endif; ?>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>
  <?php endif; ?>

  <!-- 実行履歴 -->
  <?php if ($runHistory !== []): ?>
    <section class="bg-white rounded-lg shadow p-5">
      <h2 class="font-bold mb-3">実行履歴</h2>
      <ul class="text-sm divide-y divide-gray-100">
        <?php foreach ($runHistory as $r): ?>
          <li class="py-2 flex items-center justify-between gap-3">
            <span class="text-xs text-gray-500"><?= htmlspecialchars((string) $r['created_at']) ?></span>
            <span class="text-xs">
              <?php if ($r['status'] === 'completed'): ?>
                <span class="text-green-600">完了</span> ／ トピック <?= (int) $r['topic_count'] ?>件
              <?php elseif ($r['status'] === 'failed'): ?>
                <span class="text-red-600">失敗</span>
              <?php else: ?>
                <span class="text-gray-400">実行中</span>
              <?php endif; ?>
            </span>
            <span class="text-xs text-gray-400">質問<?= (int) $r['question_count'] ?> / FB<?= (int) $r['feedback_count'] ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>
  <?php endif; ?>
</main>
</body>
</html>
