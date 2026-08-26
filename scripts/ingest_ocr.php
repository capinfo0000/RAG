<?php
declare(strict_types=1);

/**
 * OCR結果（knowledge_samples/ocr/*.md）を既存の取り込みパイプラインで投入する。
 * ocr_pdf_gemini.php の実行後に走らせる。
 *
 * 実行: cd demo && php scripts/ingest_ocr.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Config;
use App\Knowledge\Indexer;
use App\Models\Database;

Config::load(__DIR__ . '/..');

try {
    Database::pdo()->query('SELECT 1');
} catch (\Throwable $e) {
    fwrite(STDERR, "❌ DB接続失敗: " . $e->getMessage() . "\n");
    exit(1);
}

$ocrDir = __DIR__ . '/../knowledge_samples/ocr/';

// title => category（全て社内規則）
$docs = [
    '出張旅費規程'       => '社内規則',
    '入社祝い金制度'     => '社内規則',
    '保育手当制度'       => '社内規則',
    '報奨金制度'         => '社内規則',
    '育児・介護休業規定' => '社内規則',
];

$indexer = Indexer::fromConfig();
$totalChunks = 0;
$totalDocs = 0;
foreach ($docs as $title => $category) {
    $path = $ocrDir . $title . '.md';
    echo "📥 {$title}\n";
    if (!is_file($path)) {
        echo "   ⏭  SKIP: OCR結果なし ({$path})\n\n";
        continue;
    }
    try {
        $r = $indexer->ingestFile($path, $title, $category);
        echo "   ✅ document_id={$r['document_id']} chunks={$r['chunk_count']}\n\n";
        $totalChunks += $r['chunk_count'];
        $totalDocs++;
    } catch (\Throwable $e) {
        echo "   ❌ FAILED: " . $e->getMessage() . "\n\n";
    }
}

echo "=========================================\n";
echo " ✅ OCR文書の取り込み完了: {$totalDocs} 件 / {$totalChunks} チャンク\n";
echo "=========================================\n";
