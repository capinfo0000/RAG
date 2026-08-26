<?php
declare(strict_types=1);

/**
 * RAG 検索精度 評価ハーネス（Before/After 計測用）
 *
 * eval/questions.jsonl の各質問を本番と同じ HybridSearch::fromConfig()->search()
 * に流し、正解文書(expected_docs)が検索結果の何位に現れるかを計測する。
 *
 * 指標（文書単位）:
 *   - Recall@1 : 正解文書が1位に来た割合
 *   - Recall@3 : 正解文書が上位3件に入った割合
 *   - MRR      : 正解文書の順位の逆数の平均（順位に敏感な総合指標）
 *   - Top1Chunk: 最上位チャンクが正解文書由来だった割合
 *   - Latency  : 1問あたりの検索レイテンシ(ms)
 *
 * 使い方:
 *   php scripts/eval_rag.php [label] [topK]
 *     label : 出力CSVの識別子（既定 baseline） → eval/results_{label}.csv
 *     topK  : 検索候補数（既定 50、本番既定と同じ）
 *
 * 例:
 *   php scripts/eval_rag.php baseline      # BM25のみの現状
 *   php scripts/eval_rag.php vector        # ベクトル検索有効化後
 *   php scripts/eval_rag.php rerank        # リランク有効化後
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Config;
use App\Rag\HybridSearch;

Config::load(__DIR__ . '/..');

$label = $argv[1] ?? 'baseline';
$topK  = (int) ($argv[2] ?? 50);

$questionsPath = __DIR__ . '/../eval/questions.jsonl';
$outDir        = __DIR__ . '/../eval';
$outPath       = $outDir . '/results_' . $label . '.csv';

if (!is_file($questionsPath)) {
    fwrite(STDERR, "質問セットが見つかりません: {$questionsPath}\n");
    exit(1);
}
if (!is_dir($outDir)) {
    mkdir($outDir, 0775, true);
}

// --- 質問セット読み込み（JSONL） ---
$questions = [];
foreach (file($questionsPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '//')) {
        continue;
    }
    $row = json_decode($line, true);
    if (!is_array($row) || !isset($row['question'], $row['expected_docs'])) {
        fwrite(STDERR, "不正な行をスキップ: {$line}\n");
        continue;
    }
    $questions[] = $row;
}

if ($questions === []) {
    fwrite(STDERR, "有効な質問が0件です。\n");
    exit(1);
}

echo "==========================================\n";
echo " RAG 検索精度 評価: label={$label}  topK={$topK}\n";
echo " 質問数: " . count($questions) . " 件\n";
echo "==========================================\n\n";

$search = HybridSearch::fromConfig();

$rows = [];
$sumRR = 0.0;
$hit1 = 0;
$hit3 = 0;
$top1chunk = 0;
$sumLatency = 0.0;

/**
 * 検索結果チャンク列 → 出現順の一意文書リストに畳み込み、
 * 正解文書の順位(1始まり)を返す。見つからなければ 0。
 */
$goldDocRank = static function (array $chunks, array $expected): int {
    $seen = [];
    $rank = 0;
    foreach ($chunks as $c) {
        $title = (string) ($c['document_title'] ?? '');
        if ($title === '' || isset($seen[$title])) {
            continue;
        }
        $seen[$title] = true;
        $rank++;
        if (in_array($title, $expected, true)) {
            return $rank;
        }
    }
    return 0;
};

foreach ($questions as $q) {
    $expected = array_map('strval', (array) $q['expected_docs']);

    $t0 = microtime(true);
    try {
        $chunks = $search->search((string) $q['question'], $topK);
    } catch (\Throwable $e) {
        fwrite(STDERR, "[{$q['id']}] 検索エラー: " . $e->getMessage() . "\n");
        $chunks = [];
    }
    $latency = (microtime(true) - $t0) * 1000.0;
    $sumLatency += $latency;

    $rank = $goldDocRank($chunks, $expected);
    $rr = $rank > 0 ? 1.0 / $rank : 0.0;
    $sumRR += $rr;
    if ($rank === 1) {
        $hit1++;
    }
    if ($rank >= 1 && $rank <= 3) {
        $hit3++;
    }

    $topChunkTitle = (string) ($chunks[0]['document_title'] ?? '');
    $topChunkGold = in_array($topChunkTitle, $expected, true);
    if ($topChunkGold) {
        $top1chunk++;
    }

    $rows[] = [
        'id'          => (string) ($q['id'] ?? ''),
        'question'    => (string) $q['question'],
        'expected'    => implode('|', $expected),
        'top_doc'     => $topChunkTitle,
        'gold_rank'   => $rank,
        'hit@1'       => $rank === 1 ? 1 : 0,
        'hit@3'       => ($rank >= 1 && $rank <= 3) ? 1 : 0,
        'rr'          => round($rr, 4),
        'top1_chunk_gold' => $topChunkGold ? 1 : 0,
        'num_chunks'  => count($chunks),
        'latency_ms'  => round($latency, 1),
    ];

    printf(
        "[%s] rank=%s rr=%.3f top1chunk=%s %sms  %s\n",
        $rows[array_key_last($rows)]['id'],
        $rank > 0 ? (string) $rank : '✗',
        $rr,
        $topChunkGold ? '✓' : '✗',
        number_format($latency, 0),
        mb_substr((string) $q['question'], 0, 30),
    );
}

$n = count($rows);
$recall1 = $hit1 / $n;
$recall3 = $hit3 / $n;
$mrr = $sumRR / $n;
$top1rate = $top1chunk / $n;
$avgLatency = $sumLatency / $n;

// --- CSV 出力（BOM付きでExcel文字化け回避） ---
$fp = fopen($outPath, 'wb');
fwrite($fp, "\xEF\xBB\xBF");
fputcsv($fp, array_keys($rows[0]));
foreach ($rows as $r) {
    fputcsv($fp, $r);
}
// サマリ行
fputcsv($fp, []);
fputcsv($fp, ['SUMMARY', 'label=' . $label, 'topK=' . $topK, 'N=' . $n]);
fputcsv($fp, ['metric', 'value']);
fputcsv($fp, ['Recall@1', round($recall1, 4)]);
fputcsv($fp, ['Recall@3', round($recall3, 4)]);
fputcsv($fp, ['MRR', round($mrr, 4)]);
fputcsv($fp, ['Top1ChunkGold', round($top1rate, 4)]);
fputcsv($fp, ['AvgLatency_ms', round($avgLatency, 1)]);
fclose($fp);

echo "\n------------------------------------------\n";
echo " 集計結果 (label={$label}, N={$n})\n";
echo "------------------------------------------\n";
printf(" Recall@1        : %.1f%% (%d/%d)\n", $recall1 * 100, $hit1, $n);
printf(" Recall@3        : %.1f%% (%d/%d)\n", $recall3 * 100, $hit3, $n);
printf(" MRR             : %.4f\n", $mrr);
printf(" Top1ChunkGold   : %.1f%% (%d/%d)\n", $top1rate * 100, $top1chunk, $n);
printf(" 平均レイテンシ  : %.0f ms\n", $avgLatency);
echo "------------------------------------------\n";
echo " CSV出力: {$outPath}\n";
echo "==========================================\n";
