<?php
declare(strict_types=1);

/**
 * Obsidian vault 同期 CLI。
 *
 * vault(Markdownフォルダ) を回答源として documents/chunks に増分同期する。
 * cron で定期実行、または手動実行する。
 *
 * 使い方:
 *   php scripts/sync_obsidian.php [vaultパス]
 *     vaultパス省略時は .env の OBSIDIAN_VAULT_PATH を使用。
 *
 * 例:
 *   php scripts/sync_obsidian.php knowledge_samples/ocr
 *   php scripts/sync_obsidian.php "C:\Users\me\MyVault"
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Config;
use App\Knowledge\ObsidianVaultSource;

Config::load(__DIR__ . '/..');

$vaultArg = $argv[1] ?? null;

echo "==========================================\n";
echo " Obsidian vault 同期\n";
echo "==========================================\n";

try {
    $source = ObsidianVaultSource::fromConfig($vaultArg);
    $t0 = microtime(true);
    $r = $source->sync();
    $ms = (microtime(true) - $t0) * 1000.0;

    foreach ($r['detail'] as $d) {
        $mark = match ($d['action']) {
            'added' => '➕',
            'updated' => '♻',
            'deleted' => '🗑',
            'error' => '⚠',
            default => '・',
        };
        printf("  %s %s (%s)\n", $mark, $d['ref'], $d['action']);
    }

    echo "------------------------------------------\n";
    printf(" 追加:%d  更新:%d  削除:%d  変更なし:%d  (%.0fms)\n",
        $r['added'], $r['updated'], $r['deleted'], $r['unchanged'], $ms);

    if ($r['errors'] !== []) {
        echo "\n⚠ エラー:\n";
        foreach ($r['errors'] as $e) {
            echo "  - {$e}\n";
        }
    }
    echo "==========================================\n";
    echo ($r['errors'] === [] ? " ✅ 同期完了\n" : " ⚠ 一部エラーありで完了\n");
    echo "==========================================\n";
    exit($r['errors'] === [] ? 0 : 1);
} catch (\Throwable $e) {
    fwrite(STDERR, "同期に失敗しました: " . $e->getMessage() . "\n");
    exit(1);
}
