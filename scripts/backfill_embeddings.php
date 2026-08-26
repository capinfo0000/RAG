<?php
declare(strict_types=1);

/**
 * embedding バックフィル（DB内ベクトル検索の有効化用）。
 *
 * embedding 列が空のチャンクについて、設定中の Embedding プロバイダで
 * ベクトルを生成し chunks 列へ保存する。チャンク本文は再生成しない。
 *
 * 使いどころ:
 *   - 既存ナレッジ（vector 未生成の旧データ）を後からベクトル化する
 *   - EMBEDDING_PROVIDER を none → gemini 等へ切り替えた直後
 *
 * 注意:
 *   - 埋め込みモデルを変更（＝次元が変わる）した場合は --all で全再生成すること。
 *     検索は「クエリと同じ次元のベクトル」しか比較しないため、旧次元は無視される。
 *
 * 実行例:
 *   cd demo
 *   php scripts/backfill_embeddings.php            # 未ベクトル化のチャンクだけ
 *   php scripts/backfill_embeddings.php --all       # 全チャンクを再ベクトル化
 *   php scripts/backfill_embeddings.php --batch=32   # バッチ件数を指定（既定16）
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Config;
use App\Embedding\EmbeddingFactory;
use App\Models\Database;
use App\Rag\DbVectorSearch;

Config::load(__DIR__ . '/..');

$argvOpts = $argv ?? [];
$all = in_array('--all', $argvOpts, true);
$batchSize = 16;
foreach ($argvOpts as $a) {
    if (preg_match('/^--batch=(\d+)$/', $a, $m)) {
        $batchSize = max(1, min(100, (int) $m[1]));
    }
}

$embedding = EmbeddingFactory::create();
if ($embedding === null) {
    fwrite(STDERR, "EMBEDDING_PROVIDER が none（または未設定）です。\n"
        . ".env で EMBEDDING_PROVIDER=gemini 等に設定してから再実行してください。\n");
    exit(1);
}
$model = $embedding->getModelName();
$vectorIndex = new DbVectorSearch($model);

echo "Embedding プロバイダ: {$embedding->getProviderName()} / モデル: {$model}\n";
echo $all ? "対象: 全チャンク（再ベクトル化）\n" : "対象: 未ベクトル化のチャンクのみ\n";

$pdo = Database::pdo();
$where = $all ? '' : 'WHERE embedding IS NULL';
$rows = $pdo->query("SELECT id, text FROM chunks {$where} ORDER BY id")->fetchAll();

$total = count($rows);
if ($total === 0) {
    echo "対象チャンクはありません。\n";
    exit(0);
}
echo "対象 {$total} チャンクを {$batchSize} 件ずつ処理します...\n";

$done = 0;
$failed = 0;
foreach (array_chunk($rows, $batchSize) as $batch) {
    $texts = array_map(fn($r) => (string) $r['text'], $batch);
    try {
        $vectors = $embedding->embed($texts, 'document');
    } catch (\Throwable $e) {
        $failed += count($batch);
        fwrite(STDERR, '  バッチ失敗: ' . $e->getMessage() . "\n");
        continue;
    }
    if (count($vectors) !== count($batch)) {
        $failed += count($batch);
        fwrite(STDERR, sprintf("  件数不一致（%d vs %d）でスキップ\n", count($vectors), count($batch)));
        continue;
    }
    $points = [];
    foreach ($batch as $i => $r) {
        $points[] = ['chunk_id' => (int) $r['id'], 'vector' => $vectors[$i], 'payload' => []];
    }
    $vectorIndex->upsert($points);
    $done += count($points);
    echo "  {$done}/{$total} 完了\n";
}

echo "\n完了: 成功 {$done} / 失敗 {$failed} / 合計 {$total}\n";
if ($failed > 0) {
    echo "※ 失敗分はベクトルなし（キーワード検索のみ）で残ります。APIキー・レート制限を確認して再実行してください。\n";
}
