<?php
declare(strict_types=1);

/**
 * 全文書RAG 動作確認（LLM呼び出しなしの高速検証）。
 *
 *  1) HybridSearch がチャンクに document_id/title を載せて返すか
 *  2) Generator::buildDocumentSources（private/reflection）が
 *     チャンク→文書単位ソースへ集約し、全文をバジェット内で詰めるか
 *  3) 全文が使われているか（500字チャンクではなく文書全文レベルの長さ）
 *  4) CitationParser が [N] を文書単位ソースへ対応づけられるか
 *
 * 実行: cd demo && C:\Users\yonekura\xampp\php\php.exe scripts/test_fulldoc_rag.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Config;
use App\Rag\CitationParser;
use App\Rag\Generator;
use App\Rag\HybridSearch;

Config::load(__DIR__ . '/..');

$query = $argv[1] ?? '育児休業はどれくらい取得できますか';
echo "==========================================\n";
echo " 全文書RAG 検証\n";
echo "==========================================\n";
echo "query: {$query}\n\n";

// 1) 検索（BM25 / 全文検索。LLM不要）
$search = HybridSearch::fromConfig();
$candidates = $search->search($query, 50);
echo "▼ 検索ヒット: " . count($candidates) . " チャンク\n";
foreach (array_slice($candidates, 0, 5) as $i => $c) {
    printf(
        "  #%d doc=%s「%s」 textlen=%d\n",
        $i + 1,
        $c['document_id'] ?? '-',
        $c['document_title'] ?? '-',
        mb_strlen((string) ($c['text'] ?? '')),
    );
}
echo "\n";

// 2) buildDocumentSources を reflection で実呼び出し
$gen = Generator::fromConfig();
$m = new ReflectionMethod($gen, 'buildDocumentSources');
$m->setAccessible(true);
/** @var array[] $sources */
$sources = $m->invoke($gen, $candidates, 3, 24000);

echo "▼ 文書単位ソース: " . count($sources) . " 件（[N]=文書）\n";
$totalChars = 0;
foreach ($sources as $i => $s) {
    $len = mb_strlen((string) $s['text']);
    $totalChars += $len;
    printf(
        "  [%d] doc=%d「%s」 cat=%s textlen=%d %s\n",
        $i + 1,
        $s['document_id'],
        $s['document_title'],
        $s['document_category'] ?? '-',
        $len,
        !empty($s['truncated']) ? '(切詰)' : '',
    );
}
echo "  → 文脈合計: {$totalChars} 文字\n\n";

// 3) 全文が使われているか（先頭ソースが500字チャンクより十分大きいこと）
if ($sources !== []) {
    $firstLen = mb_strlen((string) $sources[0]['text']);
    echo "▼ 全文投入チェック: 先頭ソース {$firstLen} 文字 … "
        . ($firstLen > 500 ? "OK（チャンク断片でなく文書全文レベル）✅" : "⚠ 短い（要確認）") . "\n\n";
}

// 4) CitationParser が [N] を文書単位ソースに対応づけられるか
$fakeAnswer = '育児休業については規定に記載があります[1]。関連して就業規則も参照してください[2]。';
$citations = CitationParser::parse($fakeAnswer, $sources);
echo "▼ Citation 対応づけ: " . count($citations) . " 件\n";
foreach ($citations as $cit) {
    $a = $cit->toArray();
    printf("  [%d] doc=%s「%s」\n", $a['index'] ?? 0, $a['document_id'] ?? '-', $a['document_title'] ?? '-');
}

echo "\n==========================================\n";
echo " ✅ 検証完了\n";
echo "==========================================\n";
