<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Config;
use App\Models\Chunk;

Config::load(__DIR__ . '/..');

$query = $argv[1] ?? '他社製品との違いは？';
echo "Query: {$query}\n\n";

try {
    $results = Chunk::fulltextSearch($query, 5);
    echo "Hits: " . count($results) . "\n\n";
    foreach ($results as $i => $r) {
        echo sprintf("[%d] score=%s title=%s\n", $i + 1, $r['score'], $r['document_title']);
        echo "    " . mb_substr($r['text'], 0, 150) . "...\n\n";
    }
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
