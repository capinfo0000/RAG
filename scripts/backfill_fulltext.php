<?php
declare(strict_types=1);

/**
 * full_text バックフィル（全文書RAG移行用）。
 *
 * full_text が空の既存ドキュメントについて、storage_path から本文を再抽出して埋める。
 * チャンクは再生成しない（既存インデックスを維持）。
 * 実行: cd demo && C:\Users\yonekura\xampp\php\php.exe scripts/backfill_fulltext.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Config;
use App\Knowledge\ExtractorFactory;
use App\Models\Database;
use App\Models\Document;

Config::load(__DIR__ . '/..');

$pdo = Database::pdo();
$rows = $pdo->query(
    "SELECT id, title, storage_path FROM documents
     WHERE full_text IS NULL OR full_text = ''
     ORDER BY id"
)->fetchAll();

if ($rows === []) {
    echo "対象なし（全ドキュメントで full_text が埋まっています）\n";
    exit(0);
}

echo "対象 " . count($rows) . " 件をバックフィルします。\n";
$ok = 0;
$ng = 0;
foreach ($rows as $row) {
    $id = (int) $row['id'];
    $title = (string) $row['title'];
    $path = (string) $row['storage_path'];
    try {
        if (!is_file($path)) {
            throw new \RuntimeException("file not found: {$path}");
        }
        $text = ExtractorFactory::forFile($path)->extract($path);
        if (trim($text) === '') {
            throw new \RuntimeException('extracted text is empty (画像PDF等)');
        }
        Document::updateFullText($id, $text);
        echo sprintf("  ✅ id=%d %s … %d 文字\n", $id, $title, mb_strlen($text));
        $ok++;
    } catch (\Throwable $e) {
        echo sprintf("  ❌ id=%d %s … %s\n", $id, $title, $e->getMessage());
        $ng++;
    }
}
echo sprintf("\n完了: 成功 %d / 失敗 %d\n", $ok, $ng);
