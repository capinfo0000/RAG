<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use App\Config;
use App\Models\Database;
use App\Models\Settings;

// DB 接続が失敗しても画面は出す（DB未初期化時の親切エラー）
$initError = null;
$productName = (string) Config::get('APP_NAME', 'RAG Chatbot Demo');
$welcomeMsg = 'こんにちは！知りたいことを入力して送ってください。登録された資料をもとに、やさしくお答えします。';
$quickReplies = [
    'この製品の使い方を教えて',
    '管理画面でできること',
    '資料（FAQ・文書）の登録方法',
    'サポート・保証について',
];
try {
    Database::pdo();
    $productName = Settings::effective('product_name', 'APP_NAME', $productName) ?? $productName;
    $welcomeMsg = Settings::get('welcome_message', $welcomeMsg) ?? $welcomeMsg;
    $qr = Settings::get('opening_quick_replies');
    if ($qr) {
        $decoded = json_decode($qr, true);
        if (is_array($decoded) && $decoded !== []) {
            $quickReplies = array_map('strval', $decoded);
        }
    }
} catch (\Throwable $e) {
    $initError = 'データベースに接続できません: ' . $e->getMessage()
        . '（schema.sql を適用してください）';
}

// 埋め込みモード（embed.js からの iframe 読み込み）。管理リンク等のchromeを隠す。
$embed = isset($_GET['embed']) && $_GET['embed'] !== '0';

// 標準ページ（非埋め込み）はハッシュ付きURLでのみアクセス可（一般来訪者の流入防止）。
// お客様(エンドユーザー)は埋め込みウィジェット(?embed=1)だけを使う想定なので、埋め込みは対象外。
// 顧客(導入企業)はブックマークした ?k=<CHAT_ACCESS_KEY> で開く。未設定なら制限なし（開発用）。
$chatKey = (string) Config::get('CHAT_ACCESS_KEY', '');
if (!$embed && $chatKey !== '') {
    if (!hash_equals($chatKey, (string) ($_GET['k'] ?? ''))) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Not Found';
        exit;
    }
}

// API ベースパス。iframe(同一オリジン)なら相対で足りる。
$apiBase = (string) Config::get('CHAT_API_BASE', '../api');

// iframe 埋め込みを許可するオリジン。未設定なら制限しない（どのサイトの枠内でも表示可）。
// 例: CHAT_FRAME_ANCESTORS="https://example.com https://*.example.com"
$frameAncestors = trim((string) Config::get('CHAT_FRAME_ANCESTORS', ''));
if ($frameAncestors !== '') {
    header("Content-Security-Policy: frame-ancestors 'self' {$frameAncestors}");
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<title><?= htmlspecialchars($productName, ENT_QUOTES, 'UTF-8') ?></title>
<!-- ローカル同梱（外部CDN依存ゼロ / ビルド: tailwindcss@3.4.17, alpine@3.14.1, marked@12.0.2, dompurify@3.1.6） -->
<link rel="stylesheet" href="../assets/vendor/tailwind.min.css">
<script defer src="../assets/vendor/alpine.min.js"></script>
<script src="../assets/vendor/marked.min.js"></script>
<script src="../assets/vendor/purify.min.js"></script>
<link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="bg-gray-50 text-gray-900 antialiased">
<?php if ($initError !== null): ?>
<div class="max-w-2xl mx-auto mt-12 p-6 bg-red-50 border border-red-200 rounded-lg">
  <h2 class="text-lg font-bold text-red-800">セットアップが必要です</h2>
  <p class="mt-2 text-sm text-red-700"><?= htmlspecialchars($initError, ENT_QUOTES, 'UTF-8') ?></p>
  <pre class="mt-3 text-xs bg-white p-3 rounded border border-red-200 overflow-auto">cd demo
mysql -u root -e "CREATE DATABASE rag_chatbot CHARACTER SET utf8mb4;"
mysql -u root rag_chatbot &lt; sql/schema.sql</pre>
</div>
<?php else: ?>
<div x-data="chatApp(<?= htmlspecialchars(json_encode([
    'productName' => $productName,
    'welcomeMessage' => $welcomeMsg,
    'quickReplies' => $quickReplies,
    'apiBase' => $apiBase,
]), ENT_QUOTES, 'UTF-8') ?>)" x-init="init()" class="flex flex-col h-screen <?= $embed ? '' : 'max-w-3xl mx-auto shadow-md' ?> bg-white">

  <!-- Header -->
  <header class="px-4 py-2.5 border-b border-gray-200 flex items-center justify-between bg-white sticky top-0 z-10">
    <div class="flex items-center gap-3">
      <div class="w-9 h-9 rounded-full bg-gradient-to-br from-indigo-500 to-blue-500 flex items-center justify-center text-white text-sm font-bold">AI</div>
      <div>
        <h1 class="text-base font-bold leading-tight" x-text="productName"></h1>
        <p class="text-xs text-gray-500">登録された資料をもとにお答えします</p>
      </div>
    </div>
    <div class="flex items-center gap-2">
      <button x-show="messages.length > 0" @click="resetChat()"
              class="text-xs text-gray-500 hover:text-blue-600 border border-gray-200 rounded-full px-3 py-1"
              title="最初の画面に戻る">↩ 最初に戻る</button>
      <?php if (!$embed): ?><a href="../admin/" class="text-xs text-gray-500 hover:text-gray-800">管理</a><?php endif; ?>
    </div>
  </header>

  <!-- Messages -->
  <main class="flex-1 overflow-y-auto px-4 py-3 space-y-3" x-ref="scrollArea">
    <!-- ウェルカム -->
    <template x-if="messages.length === 0">
      <div class="space-y-2">
        <div class="message bot-message">
          <div class="avatar bot-avatar">AI</div>
          <div class="bubble">
            <p x-text="welcomeMessage"></p>
          </div>
        </div>
        <!-- はじめての方向け：使い方の案内（開閉式・実際の動きに沿った説明） -->
        <details class="rounded-xl border border-gray-200 bg-gray-50" style="margin:2px 0 4px 0">
          <summary style="cursor:pointer;list-style:none;padding:10px 14px;font-weight:600;color:#374151;font-size:14px">💡 はじめての方へ（使い方）</summary>
          <div style="padding:0 14px 12px 14px;font-size:13px;line-height:1.75;color:#374151">
            <p style="margin:6px 0"><b>これは何？</b><br>このAIは、当サイトに登録された資料をもとに、あなたの質問へお答えします。</p>
            <p style="margin:6px 0"><b>使い方</b><br>下のボタンを押すか、聞きたいことを入力して送ってください（Enterキーで送信）。</p>
            <p style="margin:6px 0"><b>答えのもと（出典）</b><br>回答の下に「出典」が出ます。開くと、答えのもとになった元の文を確認できます。</p>
            <p style="margin:6px 0"><b>できないこと</b><br>資料に書かれていないことはお答えできません（その場合はその旨をお伝えします）。AIは間違えることもあるので、大切なことは出典で確かめてください。</p>
          </div>
        </details>
        <div class="quick-replies">
          <template x-for="qr in quickReplies">
            <button class="quick-reply-btn" @click="send(qr)" x-text="qr"></button>
          </template>
        </div>
      </div>
    </template>

    <!-- 履歴 -->
    <template x-for="m in messages" :key="m.uid">
      <div class="message" :class="m.role === 'user' ? 'user-message' : 'bot-message'">
        <template x-if="m.role !== 'user'">
          <div class="avatar bot-avatar">AI</div>
        </template>
        <div class="bubble">
          <div class="markdown" x-html="renderMarkdown(m.content)"></div>
          <!-- Citations -->
          <template x-if="m.citations && m.citations.length">
            <div class="citations">
              <p class="cite-title">📎 回答のもとにした資料（タップで元の文を表示）</p>
              <template x-for="c in m.citations" :key="c.index">
                <details class="cite-item">
                  <summary>[<span x-text="c.index"></span>] <span x-text="c.document_title || '無題'"></span></summary>
                  <p class="cite-excerpt" x-text="c.cited_text"></p>
                </details>
              </template>
            </div>
          </template>
          <!-- AIの注意書き（実回答のみ） -->
          <template x-if="m.role === 'assistant' && m.message_id">
            <p class="ai-disclaimer">⚠️ AIは間違えることがあります。大切なことは、上の「出典」（元の資料）で確かめてください。</p>
          </template>
          <!-- Feedback / アンケート -->
          <template x-if="m.role === 'assistant' && m.message_id && !m.feedbackGiven">
            <div class="feedback-area">
              <!-- ボタン行（アンケート展開中は隠す） -->
              <div class="feedback-row" x-show="!m.surveyType">
                <button @click="openSurvey(m, 'resolved')" class="resolved-btn">✓ 解決した</button>
                <button @click="openSurvey(m, 'unresolved')" class="unresolved-btn">✗ 解決しなかった</button>
              </div>

              <!-- アンケートパネル -->
              <div class="survey-panel" x-show="m.surveyType" x-cloak>
                <p class="survey-title"
                   x-text="m.surveyType === 'resolved'
                     ? '解決できてよかったです。今後の改善のため、よければ教えてください（任意）'
                     : 'お役に立てず申し訳ありません。改善のため、近い理由を選んでください（任意）'"></p>

                <div class="survey-reasons">
                  <template x-for="r in (m.surveyType === 'resolved' ? resolvedReasons : unresolvedReasons)" :key="r">
                    <button type="button" class="survey-chip"
                            :class="m.surveyReason === r ? 'is-selected' : ''"
                            @click="m.surveyReason = (m.surveyReason === r ? '' : r)"
                            x-text="r"></button>
                  </template>
                </div>

                <textarea x-model="m.surveyComment" rows="2" class="survey-comment"
                          placeholder="自由記述（任意）。具体的な状況や不足していた情報など"></textarea>

                <div class="survey-actions">
                  <button type="button" class="survey-submit" @click="submitSurvey(m)">送信</button>
                  <button type="button" class="survey-cancel" @click="cancelSurvey(m)">キャンセル</button>
                </div>
              </div>
            </div>
          </template>
          <template x-if="m.role === 'assistant' && m.feedbackGiven">
            <p class="feedback-thanks">フィードバックありがとうございます！今後の改善に活用させていただきます。</p>
          </template>
        </div>
        <template x-if="m.role === 'user'">
          <div class="avatar user-avatar">You</div>
        </template>
      </div>
    </template>

    <!-- ロード中 -->
    <template x-if="isThinking">
      <div class="message bot-message">
        <div class="avatar bot-avatar">AI</div>
        <div class="bubble">
          <div class="typing-indicator"><span></span><span></span><span></span></div>
        </div>
      </div>
    </template>
  </main>

  <!-- Input -->
  <footer class="px-4 py-2.5 border-t border-gray-200 bg-white">
    <form @submit.prevent="onSubmit" class="flex items-end gap-2">
      <textarea
        x-model="input"
        @keydown.enter.prevent="onEnter($event)"
        rows="1"
        placeholder="質問を入力（Enterで送信、Shift+Enterで改行）"
        class="flex-1 resize-none border border-gray-300 rounded-2xl px-4 py-2 focus:outline-none focus:ring-2 focus:ring-blue-400 max-h-32"
        :disabled="isLoading"
        x-ref="input"
      ></textarea>
      <button
        type="submit"
        class="bg-blue-600 hover:bg-blue-700 disabled:bg-gray-300 text-white rounded-full w-10 h-10 flex items-center justify-center transition"
        :disabled="isLoading || !input.trim()"
        title="送信">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" viewBox="0 0 24 24" fill="currentColor"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>
      </button>
    </form>
    <p class="mt-2 text-xs text-gray-400 text-center">答えは、登録された資料の内容だけをもとにしています（勝手な推測はしません）。使った資料は[1][2]のように番号でお見せします。役に立ったかを ✓／✗ で教えていただけると助かります。</p>
  </footer>
</div>
<?php endif; ?>

<script src="../assets/js/chat.js"></script>
</body>
</html>
