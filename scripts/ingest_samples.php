<?php
declare(strict_types=1);

/**
 * 提案資料 4ファイルをナレッジベースに投入する。
 *
 * 実行: cd demo && php scripts/ingest_samples.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Config;
use App\Knowledge\Indexer;
use App\Models\Database;

Config::load(__DIR__ . '/..');

// DB 接続確認
try {
    Database::pdo()->query('SELECT 1');
} catch (\Throwable $e) {
    fwrite(STDERR, "❌ DB接続失敗: " . $e->getMessage() . "\n");
    exit(1);
}

$projectRoot = realpath(__DIR__ . '/../..');
if ($projectRoot === false) {
    fwrite(STDERR, "プロジェクトルートが見つかりません\n");
    exit(1);
}

$samples = [
    ['title' => '製品市場調査（既存AIチャットボット13製品の比較と差別化分析）',
     'path' => $projectRoot . DIRECTORY_SEPARATOR . '01_市場調査.md',
     'category' => 'market'],
    ['title' => '製品デモ仕様書（機能・デモシナリオ・実装計画）',
     'path' => $projectRoot . DIRECTORY_SEPARATOR . '02_デモ仕様書.md',
     'category' => 'spec'],
    ['title' => '製品技術スタック・アーキテクチャ（マルチLLM対応・PHPフルスタック）',
     'path' => $projectRoot . DIRECTORY_SEPARATOR . '03_技術スタック_アーキテクチャ.md',
     'category' => 'tech'],
    ['title' => '製品提案書骨子（差別化ポイント・価格）',
     'path' => $projectRoot . DIRECTORY_SEPARATOR . '04_提案書骨子.md',
     'category' => 'proposal'],
];

$indexer = Indexer::fromConfig();
$totalChunks = 0;
$totalDocs = 0;
foreach ($samples as $s) {
    if (!is_file($s['path'])) {
        echo "⏭  SKIP: {$s['title']} (file not found: {$s['path']})\n";
        continue;
    }
    $start = microtime(true);
    echo "📥 {$s['title']}\n";
    echo "   path: {$s['path']}\n";
    try {
        $r = $indexer->ingestFile($s['path'], $s['title'], $s['category']);
        $elapsed = (int) ((microtime(true) - $start) * 1000);
        echo "   ✅ document_id={$r['document_id']} chunks={$r['chunk_count']} ({$elapsed}ms)\n\n";
        $totalChunks += $r['chunk_count'];
        $totalDocs++;
    } catch (\Throwable $e) {
        echo "   ❌ FAILED: " . $e->getMessage() . "\n\n";
    }
}

echo "=========================================\n";
echo " ✅ ナレッジ投入完了\n";
echo "  documents: {$totalDocs} 件\n";
echo "  chunks: {$totalChunks} 件\n";
echo "=========================================\n";
