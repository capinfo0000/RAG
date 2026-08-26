<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
admin_require_login();

use App\Llm\LlmFactory;
use App\Llm\LlmMessage;
use App\Knowledge\Indexer;
use App\Models\Document;
use App\Models\UsageLog;

@set_time_limit(180);

const COMPOSE_UPLOAD_DIR = __DIR__ . '/../../storage/uploads';

// 会話履歴・現在のドラフトはセッションに保持（多ターンで推敲できる）
if (!isset($_SESSION['compose_msgs']) || !is_array($_SESSION['compose_msgs'])) {
    $_SESSION['compose_msgs'] = [];   // [['role'=>'user'|'assistant','content'=>...], ...]
}

$SYSTEM_PROMPT = <<<SYS
あなたは、社内チャットボットに登録する「資料」を作成するアシスタントです。
ユーザーと対話しながら、矛盾・不明点のない正確な資料を完成させます。

進め方（重要）:
1. ユーザーの入力に「矛盾・曖昧・情報不足」がある間は、資料を確定させないでください。
   推測で埋めず、要点を絞って質問し（一度に1〜3問）、解消してください。この段階では
   質問だけを返し、資料の全文やマーカーは出さない。「（要確認）」等の未確定箇所が
   1つでも残る間は、次項の完成扱いにしてはいけません。
2. ★長文や複数トピックを渡された／複数の内容が混ざるときは、いきなり1ファイルにまとめない。
   まず「既存資料の上書きが適切か・新規作成か」「保守的に複数の資料へ分けた方がよいか」を自分で判断し、
   分割案（各資料の想定タイトルと、新規か・どの既存資料の上書きか）を【文章で提示して合意を取る】。
   この確認段階では [[READY]] やマーカーは出さない。
3. 完成扱い（[[READY]] を出す）にしてよいのは、意図した資料が【すべて完成】し、
   分割・反映先についてユーザーの合意が取れたときだけ。1件でも未合意・要確認が残る間は [[READY]] を出さない。
   完成時は、対象となる資料を【すべてまとめて】1回のメッセージで提示する（1件なら1件でよい）。
   ※「この内容で登録しますか？」等の確認文は書かない（画面の保存ボタンで確認するため）。
4. ユーザーが修正を求めたら反映し、（完成していれば）再度まとめて全文＋最終行 [[READY]] を出す。

完成メッセージのマーカー書式（★単数・複数で共通・必ず守る）:
- 保存する資料ごとに、その資料の【直前の行】に、上書きなら「[[TARGET:既存資料のタイトル]]」、
  新規なら「[[TARGET:NEW]]」を単独1行で置き、続けて「# タイトル」から資料の全文を書く。
- 保存する資料をすべて並べ、【一番最後の行にだけ】「[[READY]]」を単独で出力する。
- 冒頭に「何をどう分けたか」の短い要約文を1〜3行付けてよい（その後に最初の [[TARGET:...]] を置く）。

書式:
- ★中学生が読んでも分かる平易さを最優先にする。専門用語・社内用語は避けるか、使う場合は一言で噛み砕いて説明する。一文は短く、結論を先に書き、手順は番号付き、箇条書きや具体例を活用して、誰でも迷わず理解できるようにする。
- 各資料の本文は先頭に「# タイトル」を付け、事実ベースで簡潔・構造化（見出し・箇条書き）。
   登録される本文は「マーカーを除いた各資料の全文」なので、確認文や相槌を混ぜないこと。
- 前置き・相槌は不要。質問のときは質問のみ。[[READY]] は完成時の最終行以外では絶対に使わない。
SYS;

/**
 * セッションの会話履歴からAI回答を1件生成し $_SESSION['compose_msgs'] に追記する。
 * 既存資料（タイトル一覧＋カテゴリ別・全文）と作業ルールをシステムプロンプトに載せる。
 * 成功時は null、失敗時はユーザー向けエラーメッセージを返す。
 */
$compose_generate = function () use ($SYSTEM_PROMPT): ?string {
    try {
        $llm = LlmFactory::create();
        // 既存資料のタイトル一覧＋本文を渡し、AIが「既存の改訂 or 新規作成」を自分で判断できるようにする
        $sysPrompt = $SYSTEM_PROMPT;
        try {
            // 既存資料は「タイトル一覧」＋「本文（カテゴリ別・全文）」を渡す。総量の上限は設けない。
            $titles = [];
            $byCat = [];
            foreach (Document::listRecent(500) as $d) {
                $t = trim((string) ($d['title'] ?? ''));
                if ($t === '') { continue; }
                $titles[] = $t;
                $cat = trim((string) ($d['category'] ?? ''));
                if ($cat === '') { $cat = '未分類'; }
                $body = trim((string) ($d['full_text'] ?? ''));
                $byCat[$cat][] = "#### {$t}\n" . ($body !== '' ? $body : '(本文なし)');
            }
            if ($titles !== []) {
                $sysPrompt .= "\n\n【既存の資料タイトル一覧】\n- " . implode("\n- ", $titles);
            }
            if ($byCat !== []) {
                $sysPrompt .= "\n\n【既存資料の内容（カテゴリ別・作業前に必ず全部読むこと）】";
                foreach ($byCat as $cat => $blocks) {
                    $sysPrompt .= "\n\n■ カテゴリ: " . $cat . "\n" . implode("\n\n", $blocks);
                }
            }
            $sysPrompt .= "\n\n【作業ルール】"
                . "\n1. まず上記の既存資料の内容をすべて確認する。既存資料に書いてある事実は質問しない（知っていることは聞かない）。"
                . "\n2. 質問してよいのは、資料に書くべき『製品固有の事実』（具体的な数値・手順・料金・社内ポリシー等）が既存資料にもデータにも無く、推測すると誤りになる場合だけ。逆に、一般知識で正しく書ける説明（例：ハイブリッド検索の一般的な仕組み）は質問せず、自分で分かりやすく書く。"
                . "\n3. 重複資料は作らない。同じ／関連テーマは既存資料の改訂（上書き）にする。関連の無い新規テーマのときだけ新規作成。"
                . "\n4. ★ユーザーの発言が既存資料と食い違うときは、勝手に上書きしない。まず食い違う箇所を具体的に示し、"
                . "『既存資料が正しく、ユーザーの認識が誤っている可能性もある』ことを前提に、どちらが正しいかを必ず確認する。"
                . "ユーザーが『既存資料を修正する』と明確に確認したときだけ既存資料の改訂に進む。確認が取れるまで [[READY]] や上書きはしない。"
                . "\n5. ★どの既存資料を改訂するか／新規作成か、また保守的に複数へ分割すべきかは、必ずあなたが自分で判断する。"
                . "長文・複数トピックを渡されたら1ファイルに詰め込まず、上書き/新規/分割の方針を自分で決めて【文章で提示し合意を取る】。"
                . "ただし『どの既存資料を上書きすべきか判別できない（候補が複数ある等）』ときは、勝手に選ばず、どれを直すかを確認してから確定する。"
                . "『改訂か新規か』の段取りだけを質問するのは禁止（自分で決める）。矛盾の確認=ルール4／対象が曖昧なときの確認だけは別で必須。"
                . "\n6. 完成版（全資料が完成し反映先も合意済み）のときだけ、保存する資料ごとに直前行へ [[TARGET:既存資料のタイトル]]（上書き）または [[TARGET:NEW]]（新規）を1行置き、続けて「# タイトル」から全文を書く。複数資料はすべて並べ、一番最後の行にだけ [[READY]] を置く。冒頭に短い要約文を付けてよい。";
        } catch (\Throwable) { /* 取れなくても続行 */ }
        $msgs = [];
        foreach ($_SESSION['compose_msgs'] as $m) {
            $msgs[] = $m['role'] === 'assistant'
                ? LlmMessage::assistant($m['content'])
                : LlmMessage::user($m['content']);
        }
        $resp = $llm->generate($msgs, ['system' => $sysPrompt, 'temperature' => 0.3, 'max_tokens' => 4096]);
        // AIで作成の生成も API 消費。実測トークンを統一台帳へ（best-effort）。
        UsageLog::record(UsageLog::KIND_COMPOSE, $resp->totalTokens(), $resp->model ?? null, false);
        $draft = trim($resp->content);
        $_SESSION['compose_msgs'][] = ['role' => 'assistant', 'content' => $draft];
        return null;
    } catch (\Throwable $e) {
        $pub = ($e instanceof \App\Llm\LlmException) ? $e->publicMessage() : 'AI生成に失敗しました。時間をおいて再度お試しください。';
        error_log('[compose.generate] ' . \App\Llm\LlmException::sanitize($e->getMessage()));
        return $pub;
    }
};

$error = null;
$flash = null;
$action = (string) ($_POST['action'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_csrf_check();

    if ($action === 'reset') {
        $_SESSION['compose_msgs'] = [];
        $flash = '会話をリセットしました。';
    } elseif ($action === 'chat') {
        if (!empty($_POST['fresh'])) {
            $_SESSION['compose_msgs'] = []; // 改善提案からの流し込み等：会話を白紙で開始
        }
        $instruction = trim((string) ($_POST['instruction'] ?? ''));
        if ($instruction === '') {
            $error = '指示を入力してください。';
        } elseif (mb_strlen($instruction) > 4000) {
            $error = '指示が長すぎます（最大4000文字）。';
        } else {
            $_SESSION['compose_msgs'][] = ['role' => 'user', 'content' => $instruction];
            $error = $compose_generate();
            if ($error !== null) {
                array_pop($_SESSION['compose_msgs']); // 失敗した user 発話は戻す
            }
        }
    } elseif ($action === 'seed') {
        // 改善提案画面などからの流し込み：まず質問だけ登録して画面遷移し、
        // 遷移後（次の描画で自動発火する action=generate）に回答を生成する。
        if (!empty($_POST['fresh'])) {
            $_SESSION['compose_msgs'] = [];
        }
        $instruction = trim((string) ($_POST['instruction'] ?? ''));
        if ($instruction === '') {
            $error = '指示を入力してください。';
        } elseif (mb_strlen($instruction) > 4000) {
            $error = '指示が長すぎます（最大4000文字）。';
        } else {
            $_SESSION['compose_msgs'][] = ['role' => 'user', 'content' => $instruction];
            $_SESSION['compose_autogen'] = true; // 次の描画で自動的に生成を実行
        }
    } elseif ($action === 'generate') {
        // seed 後の描画から自動送信される。直近のユーザー発話が未回答のときだけ生成する。
        unset($_SESSION['compose_autogen']);
        $last = $_SESSION['compose_msgs'] === [] ? null : end($_SESSION['compose_msgs']);
        if (is_array($last) && ($last['role'] ?? '') === 'user') {
            $error = $compose_generate();
            // 失敗時もユーザー発話は残す（画面から再試行できるように）
        }
    } elseif ($action === 'register') {
        // 保存する資料を配列で受け取る（複数対応）。docs[i] = ['target'=>既存ID or 0, 'body'=>本文]
        $incoming = [];
        if (isset($_POST['docs']) && is_array($_POST['docs'])) {
            foreach ($_POST['docs'] as $d) {
                if (!is_array($d)) { continue; }
                $incoming[] = ['target' => (int) ($d['target'] ?? 0), 'body' => (string) ($d['body'] ?? '')];
            }
        } elseif (isset($_POST['draft'])) {
            // 後方互換：旧・単数フォーム
            $incoming[] = ['target' => (int) ($_POST['target_doc'] ?? 0), 'body' => (string) $_POST['draft']];
        }

        // 1件を保存（新規 or 上書き）。成功=['ok'=>true,'title'=>..,'mode'=>'new'|'update']、失敗=['ok'=>false,'title'=>..,'error'=>..]
        $saveOne = function (int $target, string $rawBody): array {
            $body = trim((string) preg_replace('/\[\[(READY|TARGET:[^\]]*)\]\]/u', '', $rawBody));
            // 先頭の非空行を見出しとしてタイトル化（Markdownの # や * を除去）
            $title = '';
            foreach (preg_split('/\r\n|\r|\n/', $body) as $line) {
                $line = trim($line);
                if ($line !== '') { $title = trim((string) preg_replace('/^[#*\-\s　]+/u', '', $line)); break; }
            }
            $title = $title !== '' ? mb_substr($title, 0, 80) : ('AI作成資料 ' . date('Y-m-d H:i'));
            if ($body === '') { return ['ok' => false, 'title' => $title, 'error' => '本文が空です']; }
            if (mb_strlen($body) > 100000) { return ['ok' => false, 'title' => $title, 'error' => '本文が長すぎます']; }
            try {
                if ($target > 0) {
                    // 既存資料を丸ごと上書き改訂（重複を作らない）
                    $doc = Document::find($target);
                    if ($doc === null) { throw new \RuntimeException('対象の資料が見つかりません'); }
                    Indexer::fromConfig()->updateContent($target, $body);
                    return ['ok' => true, 'title' => (string) $doc['title'], 'mode' => 'update'];
                }
                // 新規：AI生成テキストを .md として保存し、既存の取り込み処理を再利用
                if (!is_dir(COMPOSE_UPLOAD_DIR)) { @mkdir(COMPOSE_UPLOAD_DIR, 0775, true); }
                $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $title);
                $safe = $safe !== '' ? mb_substr($safe, 0, 40) : 'compose';
                $fname = 'composed-' . date('YmdHis') . '-' . mt_rand(1000, 9999) . '-' . $safe . '.md';
                $path = COMPOSE_UPLOAD_DIR . DIRECTORY_SEPARATOR . $fname;
                if (file_put_contents($path, $body) === false) { throw new \RuntimeException('ファイル保存に失敗しました'); }
                Indexer::fromConfig()->ingestFile($path, $title, null, null, $body);
                return ['ok' => true, 'title' => $title, 'mode' => 'new'];
            } catch (\Throwable $e) {
                error_log('[compose.register] ' . \App\Llm\LlmException::sanitize($e->getMessage()));
                return ['ok' => false, 'title' => $title, 'error' => $e->getMessage()];
            }
        };

        if ($incoming === []) {
            $error = '登録する内容がありません。';
        } else {
            $newCnt = 0; $updCnt = 0; $failed = [];
            foreach ($incoming as $d) {
                $r = $saveOne($d['target'], $d['body']);
                if ($r['ok']) {
                    (($r['mode'] ?? '') === 'update') ? $updCnt++ : $newCnt++;
                } else {
                    $failed[] = $r['title'] . '（' . $r['error'] . '）';
                }
            }
            $saved = $newCnt + $updCnt;
            if ($saved > 0) {
                $parts = [];
                if ($newCnt > 0) { $parts[] = "新規{$newCnt}件"; }
                if ($updCnt > 0) { $parts[] = "上書き{$updCnt}件"; }
                $flash = sprintf('✅ %d件を保存しました（%s）。チャットの回答に反映されます。', $saved, implode('／', $parts));
            }
            if ($failed !== []) {
                $error = '一部保存できませんでした: ' . implode(' / ', $failed) . '。お手数ですが、失敗分は作り直してください。';
            }
            // 途中で消えないよう、全件の保存処理が終わってからまとめて会話を初期化する。
            // ただし1件も保存できなかった場合は、やり直せるよう会話を残す。
            if ($saved > 0) {
                $_SESSION['compose_msgs'] = [];
            }
        }
    }
}

$msgs = $_SESSION['compose_msgs'];

// seed 直後：最後がユーザー発話で自動生成フラグが立っているなら「作成中」を出し、描画後に自動生成する
$lastMsg = $msgs === [] ? null : end($msgs);
$pending = !empty($_SESSION['compose_autogen']) && is_array($lastMsg) && ($lastMsg['role'] ?? '') === 'user';

// 登録先セレクト用の既存資料一覧
$existingDocs = [];
try {
    foreach (Document::listRecent(200) as $d) {
        $existingDocs[] = ['id' => (int) $d['id'], 'title' => (string) ($d['title'] ?? '')];
    }
} catch (\Throwable) { /* 取れなくても新規登録は可能 */ }
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>AIで資料作成 - 管理画面</title>
<link rel="stylesheet" href="../assets/vendor/tailwind.min.css">
<style>
  @keyframes composeDot { 0%,80%,100% { opacity:.2 } 40% { opacity:1 } }
  .compose-dot { display:inline-block;width:6px;height:6px;margin-left:3px;border-radius:50%;background:#6b7280;animation:composeDot 1.2s infinite ease-in-out }
  .compose-dot:nth-child(2) { animation-delay:.2s }
  .compose-dot:nth-child(3) { animation-delay:.4s }
</style>
</head>
<body class="bg-gray-50">
<?php include __DIR__ . '/_nav.php'; ?>

<main class="max-w-4xl mx-auto px-6 py-8">
  <div class="flex items-center justify-between mb-3">
    <h1 class="text-2xl font-bold">📚 資料</h1>
    <?php if ($msgs !== []): ?>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= htmlspecialchars(admin_csrf_token()) ?>">
      <input type="hidden" name="action" value="reset">
      <button class="text-xs text-gray-500 hover:text-red-600">会話をリセット</button>
    </form>
    <?php endif; ?>
  </div>

  <?php $active = 'compose'; include __DIR__ . '/_resource_tabs.php'; ?>

  <?php if ($flash): ?><div class="mb-4 rounded border border-green-200 bg-green-50 text-green-800 text-sm px-4 py-2"><?= htmlspecialchars($flash, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
  <?php if ($error): ?><div class="mb-4 rounded border border-red-200 bg-red-50 text-red-700 text-sm px-4 py-2"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

  <p class="text-sm text-gray-500 mb-4">AIが不明点・矛盾点を質問して整理し、まとまったら資料の全文（必要なら複数に分けて）を提示します。各資料の反映先（新規／どの既存を上書きか）を確認して「✓ 保存」を押すと、チャットの回答に使われます。</p>

  <!-- チャット（普通のチャット風） -->
  <div style="display:flex;flex-direction:column;height:460px;border:1px solid #e5e7eb;border-radius:14px;background:#fff;overflow:hidden;margin-bottom:16px">
    <div id="composeMessages" style="flex:1;overflow-y:auto;padding:18px;background:#f9fafb">
      <?php if ($msgs === []): ?>
        <div style="height:100%;display:flex;align-items:center;justify-content:center;text-align:center;color:#9ca3af;font-size:14px">
          <div>
            <div style="font-size:32px;margin-bottom:8px">✍️</div>
            AIに指示して資料を作りましょう。<br>
            例：「配送・返品ポリシーのFAQを作って」「営業時間と定休日をまとめて」
          </div>
        </div>
      <?php else: ?>
        <?php foreach ($msgs as $m): ?>
          <?php if ($m['role'] === 'user'): ?>
            <div style="display:flex;justify-content:flex-end;margin-bottom:14px">
              <div style="max-width:80%;background:#2563eb;color:#fff;padding:9px 13px;border-radius:14px 14px 3px 14px;font-size:14px;white-space:pre-wrap;line-height:1.6"><?= htmlspecialchars($m['content'], ENT_QUOTES, 'UTF-8') ?></div>
            </div>
          <?php else: ?>
            <?php
              // AIが「矛盾・不明点なし」で完成と判断したときだけ末尾に [[READY]] を出す。
              // 完成メッセージは資料ごとに直前行 [[TARGET:タイトル|NEW]] を置き、最後の行だけ [[READY]]。
              // ここで資料単位にパースし、反映先（新規/どの既存を上書き）を「文字で」表示する（プルダウン廃止）。
              $content = (string) $m['content'];
              $ready = (mb_strpos($content, '[[READY]]') !== false);
              $intro = '';
              $docs = [];
              if ($ready) {
                  if (preg_match_all('/\[\[TARGET:\s*(.*?)\s*\]\]/u', $content, $mm, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
                      // 最初のマーカー前＝要約文（マーカー除去・バイトオフセットなので substr を使う）
                      $intro = trim((string) preg_replace('/\[\[(READY|TARGET:[^\]]*)\]\]/u', '', substr($content, 0, $mm[0][0][1])));
                      $n = count($mm);
                      for ($i = 0; $i < $n; $i++) {
                          $start = $mm[$i][0][1] + strlen($mm[$i][0][0]);
                          $end = ($i + 1 < $n) ? $mm[$i + 1][0][1] : strlen($content);
                          $seg = substr($content, $start, $end - $start);
                          $body = trim((string) preg_replace('/\[\[(READY|TARGET:[^\]]*)\]\]/u', '', $seg));
                          if ($body === '') { continue; }
                          $docs[] = ['target_str' => trim((string) $mm[$i][1][0]), 'body' => $body];
                      }
                  }
                  // フォールバック：旧形式（本文の後ろにTARGET1個 等）や区切り不発時は、全文を1件扱い
                  if ($docs === []) {
                      $body = trim((string) preg_replace('/\[\[(READY|TARGET:[^\]]*)\]\]/u', '', $content));
                      $tg = '';
                      if (preg_match('/\[\[TARGET:\s*(.*?)\s*\]\]/u', $content, $one)) { $tg = trim($one[1]); }
                      if ($body !== '') { $docs[] = ['target_str' => $tg, 'body' => $body]; }
                      $intro = '';
                  }
                  // 各資料：反映先IDの解決（完全一致→部分一致）＋タイトル抽出＋表示ラベル
                  foreach ($docs as &$dref) {
                      $recId = 0; $ovrTitle = '';
                      $ts = $dref['target_str'];
                      if ($ts !== '' && strtoupper($ts) !== 'NEW') {
                          foreach ($existingDocs as $ed) { if ($ed['title'] === $ts) { $recId = $ed['id']; $ovrTitle = $ed['title']; break; } }
                          if ($recId === 0) { foreach ($existingDocs as $ed) { if ($ed['title'] !== '' && mb_strpos($ts, $ed['title']) !== false) { $recId = $ed['id']; $ovrTitle = $ed['title']; break; } } }
                      }
                      $ttl = '';
                      foreach (preg_split('/\r\n|\r|\n/', $dref['body']) as $ln) { $ln = trim($ln); if ($ln !== '') { $ttl = trim((string) preg_replace('/^[#*\-\s　]+/u', '', $ln)); break; } }
                      $dref['target'] = $recId;
                      $dref['title'] = $ttl !== '' ? mb_substr($ttl, 0, 80) : '無題';
                      $dref['label'] = $recId > 0 ? ('✏️ 既存「' . $ovrTitle . '」を上書き') : '🆕 新規作成';
                      $dref['conf'] = $recId > 0 ? ('上書き: ' . $ovrTitle) : ('新規: ' . $dref['title']);
                  }
                  unset($dref);
              }
              $shown = $ready ? $intro : trim((string) preg_replace('/\[\[(READY|TARGET:[^\]]*)\]\]/u', '', $content));
              if ($ready && $shown === '') { $shown = ($docs === []) ? '完成した資料が見つかりませんでした。もう一度お試しください。' : '以下の内容で保存できます。ご確認ください。'; }
            ?>
            <div style="display:flex;gap:8px;margin-bottom:<?= $ready ? '4px' : '14px' ?>">
              <div style="flex:none;width:30px;height:30px;border-radius:50%;background:#e0e7ff;color:#4338ca;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700">AI</div>
              <div style="max-width:80%;background:#fff;border:1px solid #e5e7eb;color:#1f2937;padding:9px 13px;border-radius:3px 14px 14px 14px;font-size:14px;white-space:pre-wrap;line-height:1.6"><?= htmlspecialchars($shown, ENT_QUOTES, 'UTF-8') ?></div>
            </div>
            <?php if ($ready && $docs !== []): ?>
            <?php
              $confLines = [];
              foreach ($docs as $d) { $confLines[] = '・' . $d['conf']; }
              $confirmMsg = "次の内容で保存します。\n" . implode("\n", $confLines) . "\n\nよろしいですか？（変更したい場合はキャンセルしてメッセージで指示できます）";
            ?>
            <div style="margin:0 0 8px 38px">
              <?php foreach ($docs as $idx => $d): ?>
                <div style="margin-bottom:8px;border:1px solid #e5e7eb;border-radius:10px;background:#fff;overflow:hidden">
                  <div style="padding:8px 12px;background:#f3f4f6;font-size:13px;display:flex;gap:8px;align-items:center;justify-content:space-between;flex-wrap:wrap">
                    <span style="font-weight:600;color:#111827">資料<?= $idx + 1 ?>：<?= htmlspecialchars(mb_strimwidth($d['title'], 0, 44, '…'), ENT_QUOTES, 'UTF-8') ?></span>
                    <span style="font-size:12px;font-weight:600;color:<?= $d['target'] > 0 ? '#b45309' : '#166534' ?>"><?= htmlspecialchars($d['label'], ENT_QUOTES, 'UTF-8') ?></span>
                  </div>
                  <div style="max-height:220px;overflow:auto;padding:10px 12px;font-size:13px;white-space:pre-wrap;line-height:1.6;color:#1f2937"><?= htmlspecialchars($d['body'], ENT_QUOTES, 'UTF-8') ?></div>
                </div>
              <?php endforeach; ?>
            </div>
            <div style="margin:0 0 16px 38px">
              <form method="post" onsubmit="if(!confirm(<?= htmlspecialchars(json_encode($confirmMsg, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>))return false; var b=this.querySelector('button[type=submit]');setTimeout(function(){b.disabled=true;b.textContent='保存中…';},10);">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars(admin_csrf_token()) ?>">
                <input type="hidden" name="action" value="register">
                <?php foreach ($docs as $idx => $d): ?>
                  <input type="hidden" name="docs[<?= $idx ?>][target]" value="<?= (int) $d['target'] ?>">
                  <textarea name="docs[<?= $idx ?>][body]" style="display:none"><?= htmlspecialchars($d['body'], ENT_QUOTES, 'UTF-8') ?></textarea>
                <?php endforeach; ?>
                <button type="submit" style="background:#16a34a;color:#fff;border:none;border-radius:8px;padding:6px 14px;font-size:13px;font-weight:600;cursor:pointer">✓ この<?= count($docs) ?>件を保存</button>
                <p style="font-size:11px;color:#6b7280;margin-top:4px">反映先はAIが判断しました（重複は作りません）。変えたい場合はキャンセルして、メッセージで指示してください。</p>
              </form>
            </div>
            <?php endif; ?>
          <?php endif; ?>
        <?php endforeach; ?>
        <?php if ($pending): ?>
          <div style="display:flex;gap:8px;margin-bottom:14px">
            <div style="flex:none;width:30px;height:30px;border-radius:50%;background:#e0e7ff;color:#4338ca;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700">AI</div>
            <div style="max-width:80%;background:#fff;border:1px solid #e5e7eb;color:#6b7280;padding:9px 13px;border-radius:3px 14px 14px 14px;font-size:14px;line-height:1.6">AIが作成中… しばらくお待ちください<span class="compose-dot"></span><span class="compose-dot"></span><span class="compose-dot"></span></div>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>
    <?php if ($pending): ?>
      <form method="post" id="composeAutoGen" style="display:none">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars(admin_csrf_token()) ?>">
        <input type="hidden" name="action" value="generate">
      </form>
    <?php endif; ?>
    <form method="post" id="composeChatForm" style="border-top:1px solid #e5e7eb;padding:10px;display:flex;gap:8px;background:#fff"
          onsubmit="return composeOnSubmit(this);">
      <input type="hidden" name="_csrf" value="<?= htmlspecialchars(admin_csrf_token()) ?>">
      <input type="hidden" name="action" value="chat">
      <input type="text" name="instruction" required autofocus autocomplete="off"
             placeholder="メッセージを入力（例：〇〇のFAQを作って / もっと簡潔に）"
             style="flex:1;border:1px solid #d1d5db;border-radius:10px;padding:10px 14px;font-size:14px;outline:none">
      <button type="submit" style="background:#2563eb;color:#fff;border:none;border-radius:10px;padding:0 18px;font-size:14px;font-weight:600;cursor:pointer;white-space:nowrap">送信</button>
    </form>
  </div>
  <script>
    // 送信のたびに最新メッセージまでスクロール
    (function(){ var m=document.getElementById('composeMessages'); if(m){ m.scrollTop=m.scrollHeight; } })();

    // seed 後：画面遷移が終わってから回答生成を自動発火する（先に質問＋作成中を見せる）
    (function(){
      var f = document.getElementById('composeAutoGen');
      if (!f) return;
      var inp = document.querySelector('#composeChatForm input[name=instruction]');
      if (inp) inp.disabled = true;
      var b = document.querySelector('#composeChatForm button[type=submit]');
      if (b) { b.disabled = true; b.textContent = '生成中…'; }
      // 描画を確実に見せてから送信
      setTimeout(function(){ f.submit(); }, 50);
    })();

    // 送信時：質問を即座に表示し、「AIが作成中…」を出して固まったように見えないようにする
    var composeSending = false;
    function composeOnSubmit(form){
      if (composeSending) return false;            // 二重送信ガード
      var input = form.instruction;
      var text = (input && input.value ? input.value : '').trim();
      if (!text) return false;
      composeSending = true;

      var box = document.getElementById('composeMessages');
      if (box) {
        // 空状態のプレースホルダを消す
        var ph = box.querySelector('[data-compose-empty]');
        if (ph) ph.remove();

        // 送信した質問（青バブル・右寄せ）
        var u = document.createElement('div');
        u.style.cssText = 'display:flex;justify-content:flex-end;margin-bottom:14px';
        var ub = document.createElement('div');
        ub.style.cssText = 'max-width:80%;background:#2563eb;color:#fff;padding:9px 13px;border-radius:14px 14px 3px 14px;font-size:14px;white-space:pre-wrap;line-height:1.6';
        ub.textContent = text;
        u.appendChild(ub);
        box.appendChild(u);

        // AI作成中バブル（白バブル・左寄せ）
        var a = document.createElement('div');
        a.style.cssText = 'display:flex;gap:8px;margin-bottom:14px';
        a.innerHTML = '<div style="flex:none;width:30px;height:30px;border-radius:50%;background:#e0e7ff;color:#4338ca;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700">AI</div>'
          + '<div style="max-width:80%;background:#fff;border:1px solid #e5e7eb;color:#6b7280;padding:9px 13px;border-radius:3px 14px 14px 14px;font-size:14px;line-height:1.6">AIが作成中… しばらくお待ちください<span class="compose-dot"></span><span class="compose-dot"></span><span class="compose-dot"></span></div>';
        box.appendChild(a);

        box.scrollTop = box.scrollHeight;
      }

      if (input) { input.value = ''; input.disabled = true; }
      var b = form.querySelector('button[type=submit]');
      if (b) { b.disabled = true; b.textContent = '生成中…'; }
      return true; // 通常のPOSTを続行（サーバ応答後にページ再描画で本物の回答に置き換わる）
    }
  </script>

  <p class="text-xs text-gray-400">AIが全資料の全文を提示したら、その下の「✓ 保存」で一括登録できます（タイトルは自動、反映先はAIが判断）。まだ質問・確認中は保存ボタンは出ません。</p>
</main>
</body>
</html>
