<?php
declare(strict_types=1);

/**
 * 2つのローカルLLMサーバーを同一条件でベンチマークし、最適な方を選ぶための比較スクリプト。
 * 速度 / 思考トークン量 / 回答品質（目視）を計測する。
 *
 * 実行: cd demo && C:\Users\yonekura\xampp\php\php.exe scripts/bench_local_llms.php
 */

$targets = [
    ['label' => 'A: LM Studio',  'base' => 'http://192.168.0.3:1234/v1',  'model' => 'qwen/qwen3.6-35b-a3b'],
    ['label' => 'B: Lemonade',   'base' => 'http://192.168.0.3:13305/v1', 'model' => 'Qwen3.6-27B-GGUF'],
];

// 社内規程RAGを模した現実的なプロンプト（短めの文脈＋質問）
$system = 'あなたは社内規程アシスタントです。提示されたナレッジだけを根拠に、簡潔に日本語で答えてください。';
$context = "【就業規則 第26条（年次有給休暇）】\n勤続6か月で10日、1年6か月で11日、2年6か月で12日、3年6か月で14日、4年6か月で16日、5年6か月で18日、6年6か月以上で20日を付与する。";
$question = '有給休暇は勤続3年半で何日もらえますか？';
$userMsg = "【ナレッジ】\n{$context}\n\n【質問】\n{$question}";

function ping(string $base): array
{
    $ch = curl_init(rtrim($base, '/') . '/models');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6]);
    $r = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, (string) $r];
}

echo "==========================================================\n";
echo " ローカルLLM ベンチマーク（社内規程RAG想定）\n";
echo " Q: {$question}（正解: 14日）\n";
echo "==========================================================\n\n";

foreach ($targets as $t) {
    echo "■ {$t['label']}  {$t['base']}  ({$t['model']})\n";
    [$pingCode] = ping($t['base']);
    if ($pingCode !== 200) {
        echo "   ⏭  到達不可（/models HTTP {$pingCode}）。サーバー未起動の可能性。\n\n";
        continue;
    }

    $payload = [
        'model' => $t['model'],
        'messages' => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $userMsg],
        ],
        'temperature' => 0.2,
        'max_tokens' => 2048,
        'stream' => false,
    ];

    $start = microtime(true);
    $ch = curl_init(rtrim($t['base'], '/') . '/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        CURLOPT_TIMEOUT => 600,
    ]);
    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $elapsed = microtime(true) - $start;

    if ($resp === false || $code !== 200) {
        echo "   ❌ HTTP {$code} {$err} " . mb_substr((string) $resp, 0, 200) . "\n\n";
        continue;
    }

    $data = json_decode((string) $resp, true);
    $msg = $data['choices'][0]['message'] ?? [];
    $content = (string) ($msg['content'] ?? '');
    $reasoning = (string) ($msg['reasoning_content'] ?? '');
    $usage = $data['usage'] ?? [];
    $completion = $usage['completion_tokens'] ?? null;
    $tps = ($completion && $elapsed > 0) ? round($completion / $elapsed, 1) : null;
    $hasNum = str_contains($content, '14') ? '✅含む' : '⚠️無し';

    printf("   時間: %.1f秒 | 出力tokens: %s | 速度: %s tok/s\n", $elapsed, $completion ?? '-', $tps ?? '-');
    printf("   思考(reasoning): %d文字 %s\n", mb_strlen($reasoning), $reasoning !== '' ? '← 思考モデル' : '');
    printf("   回答(%d文字, 正解14日 %s):\n   %s\n\n", mb_strlen($content), $hasNum, mb_substr(trim($content), 0, 220));
}

echo "==========================================================\n";
echo " 判定の目安: 時間が短く / reasoningが少なく / 回答に「14日」を含む方が最適\n";
echo "==========================================================\n";
