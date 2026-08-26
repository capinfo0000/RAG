<?php
declare(strict_types=1);

/**
 * スキャン/文字化けPDFを Gemini 2.5 Flash（マルチモーダル）で日本語Markdownに文字起こしする。
 * 出力は knowledge_samples/ocr/<title>.md に保存（後段の ingest_ocr.php で取り込む）。
 *
 * 実行: cd demo && php scripts/ocr_pdf_gemini.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Config;

Config::load(__DIR__ . '/..');

$apiKey = (string) Config::get('GEMINI_API_KEY', '');
$model = (string) Config::get('GEMINI_MODEL', 'gemini-2.5-flash');
if ($apiKey === '') {
    fwrite(STDERR, "❌ GEMINI_API_KEY 未設定\n");
    exit(1);
}

$uploads = __DIR__ . '/../storage/uploads/';
$outDir = __DIR__ . '/../knowledge_samples/ocr/';
if (!is_dir($outDir) && !mkdir($outDir, 0775, true) && !is_dir($outDir)) {
    fwrite(STDERR, "❌ 出力ディレクトリ作成失敗: {$outDir}\n");
    exit(1);
}

// storage/ocr_targets.txt の5件
$targets = [
    ['title' => '出張旅費規程',         'file' => '09e1868c-b5ed-4d0d-aca8-13989b790cb8.pdf'],
    ['title' => '入社祝い金制度',       'file' => '993726e9-c4d4-4228-8fe3-3799d3bfdff6.pdf'],
    ['title' => '保育手当制度',         'file' => '8911ac50-a1f9-4333-a1cf-1cf8a818c281.pdf'],
    ['title' => '報奨金制度',           'file' => '6fc35e16-1417-4f36-b25e-72d518e62fc8.pdf'],
    ['title' => '育児・介護休業規定',   'file' => 'bb44f8d2-5dee-4bac-91a7-499a66cec520.pdf'],
];

$prompt = <<<PROMPT
このPDFは日本語の社内規程です。本文をそのまま正確に文字起こししてください。

ルール:
- 見出し・条番号（第N条）・項番号・箇条書き・表の構造をMarkdownで保持する
- 表は Markdown のテーブル記法で再現する
- 本文に無い説明・要約・前置き・後書きは一切付けない（文字起こし結果のみ出力）
- 読み取れない文字は [判読不能] と記す
- ページ番号やヘッダー/フッターの定型文は省いてよい
PROMPT;

// APIキーはURL(?key=)ではなく x-goog-api-key ヘッダで送る（URL/ログ経由の漏洩防止）。
$endpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";

$ok = 0;
foreach ($targets as $t) {
    $path = $uploads . $t['file'];
    $outPath = $outDir . $t['title'] . '.md';
    echo "📄 {$t['title']} ({$t['file']})\n";
    if (!is_file($path)) {
        echo "   ⏭  SKIP: ファイルなし\n\n";
        continue;
    }
    if (is_file($outPath) && mb_strlen((string) file_get_contents($outPath)) > 50) {
        echo "   ⏭  SKIP: 処理済み (" . basename($outPath) . ")\n\n";
        $ok++;
        continue;
    }

    $b64 = base64_encode((string) file_get_contents($path));
    $payload = [
        'contents' => [[
            'parts' => [
                ['inline_data' => ['mime_type' => 'application/pdf', 'data' => $b64]],
                ['text' => $prompt],
            ],
        ]],
        'generationConfig' => [
            'temperature' => 0.0,
            'maxOutputTokens' => 32768,
            'thinkingConfig' => ['thinkingBudget' => 0], // 純粋な文字起こしなので思考不要
        ],
    ];

    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $resp = false;
    $httpCode = 0;
    $curlErr = '';
    $maxAttempts = 5;
    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: ' . $apiKey],
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_TIMEOUT => 300,
        ]);
        $resp = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        // 一時的エラー（503/429/500）は指数バックオフでリトライ
        if (in_array($httpCode, [429, 500, 503], true) && $attempt < $maxAttempts) {
            $wait = 2 ** $attempt; // 2,4,8,16秒
            echo "   ⏳ HTTP {$httpCode} 高負荷。{$wait}秒後にリトライ ({$attempt}/{$maxAttempts})\n";
            sleep($wait);
            continue;
        }
        break;
    }

    if ($resp === false) {
        echo "   ❌ cURL error: {$curlErr}\n\n";
        continue;
    }
    if ($httpCode !== 200) {
        // APIキー漏洩防止のため endpoint は出さない
        echo "   ❌ HTTP {$httpCode}: " . mb_substr((string) $resp, 0, 400) . "\n\n";
        continue;
    }

    $data = json_decode((string) $resp, true);
    $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
    $finish = $data['candidates'][0]['finishReason'] ?? '?';
    if (trim($text) === '') {
        echo "   ❌ 抽出テキスト空 (finishReason={$finish})\n\n";
        continue;
    }

    $outPath = $outDir . $t['title'] . '.md';
    file_put_contents($outPath, "# {$t['title']}\n\n" . trim($text) . "\n");
    $chars = mb_strlen($text);
    $note = $finish === 'MAX_TOKENS' ? ' ⚠️ MAX_TOKENS到達(途中切れの可能性)' : '';
    echo "   ✅ {$chars}文字 → " . basename($outPath) . " (finish={$finish}){$note}\n\n";
    $ok++;
}

echo "=========================================\n";
echo " OCR完了: {$ok}/" . count($targets) . " 件\n";
echo " 出力先: {$outDir}\n";
echo "=========================================\n";
