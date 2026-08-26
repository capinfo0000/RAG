<?php
declare(strict_types=1);
/**
 * Web経由の vault 同期トリガ（lsphp = PDO利用可）。
 * CLIのPHPにPDOが無い環境向けの実行口。トークン保護。
 * 使い方: https://<host>/sync-run.php?token=<TOKEN>
 * cron:   curl -s "https://<host>/sync-run.php?token=<TOKEN>"
 */
require_once __DIR__ . '/bootstrap.php';

use App\Knowledge\ObsidianVaultSource;

$TOKEN = 'iFtetRFjuTBoQPvIG3QgWaRNs7xEjCDv';
if (!hash_equals($TOKEN, (string)($_GET['token'] ?? ''))) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "forbidden\n";
    exit;
}
header('Content-Type: text/plain; charset=utf-8');
try {
    // vault はアプリ直下 app/vault（このファイルは app/public/ にあるので ../vault）。
    // OBSIDIAN_VAULT_PATH の相対値・CWD差異に依存しないよう絶対パスを明示。
    $r = ObsidianVaultSource::fromConfig(__DIR__ . '/../vault')->sync();
    printf("OK added=%d updated=%d deleted=%d unchanged=%d\n",
        $r['added'], $r['updated'], $r['deleted'], $r['unchanged']);
    if (!empty($r['errors'])) { echo "errors:\n"; foreach ($r['errors'] as $e) { echo "  - {$e}\n"; } }
} catch (\Throwable $e) {
    http_response_code(500);
    echo "FAILED: " . $e->getMessage() . "\n";
}
