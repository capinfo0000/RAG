<?php
declare(strict_types=1);

/**
 * ローカルLLM（LM Studio / Ollama）動作確認スクリプト。
 *
 * LlmFactory::create() を引数なしで呼び、DB(settings) の
 * llm_provider / llm_model / llm_base_url を実際に解決して疎通する。
 * 実行: cd demo && C:\Users\yonekura\xampp\php\php.exe scripts/test_local_llm.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Config;
use App\Embedding\EmbeddingFactory;
use App\Llm\LlmException;
use App\Llm\LlmFactory;
use App\Llm\LlmMessage;
use App\Models\Settings;

Config::load(__DIR__ . '/..');

echo "==========================================\n";
echo " ローカルLLM 動作確認 (settings 経由)\n";
echo "==========================================\n";
echo "provider     : " . Settings::get('llm_provider') . "\n";
echo "model        : " . Settings::get('llm_model') . "\n";
echo "base_url     : " . Settings::get('llm_base_url') . "\n";
echo "max_tokens   : " . Settings::get('llm_max_tokens') . "\n";
echo "embedding    : " . Settings::get('embedding_provider') . "\n\n";

try {
    $provider = LlmFactory::create();
    echo "resolved class: " . get_class($provider) . "\n\n";

    $maxTokens = (int) Settings::get('llm_max_tokens', '8192');

    echo "▼ generate() テスト\n";
    echo "Q: あなたは何ができるAIですか？1文で簡潔に答えて。\n";
    $start = microtime(true);
    $resp = $provider->generate(
        messages: [LlmMessage::user('あなたは何ができるAIですか？1文で簡潔に答えて。')],
        options: ['temperature' => 0.3, 'max_tokens' => $maxTokens],
    );
    $elapsed = (int) ((microtime(true) - $start) * 1000);
    echo "A: " . $resp->content . "\n";
    echo sprintf(
        "  tokens: in=%s out=%s | finish=%s | %dms\n\n",
        $resp->inputTokens ?? '-',
        $resp->outputTokens ?? '-',
        $resp->finishReason ?? '-',
        $elapsed,
    );

    echo "▼ stream() テスト\n";
    echo "Q: RAGとは何か2行で説明して。\n";
    echo "A: ";
    $start = microtime(true);
    $gen = $provider->stream(
        messages: [LlmMessage::user('RAGとは何か2行で説明して。')],
        options: ['temperature' => 0.3, 'max_tokens' => $maxTokens],
    );
    foreach ($gen as $delta) {
        echo $delta;
        flush();
    }
    $final = $gen->getReturn();
    $elapsed = (int) ((microtime(true) - $start) * 1000);
    echo "\n";
    echo sprintf(
        "  tokens: in=%s out=%s | finish=%s | %dms\n\n",
        $final->inputTokens ?? '-',
        $final->outputTokens ?? '-',
        $final->finishReason ?? '-',
        $elapsed,
    );

    $embed = EmbeddingFactory::create();
    echo "▼ embedding: " . ($embed === null ? "none (BM25のみ・縮退モード) ✅" : get_class($embed)) . "\n";

    echo "\n==========================================\n";
    echo " ✅ すべてのテスト成功\n";
    echo "==========================================\n";
} catch (LlmException $e) {
    fwrite(STDERR, "❌ LlmException: " . $e->getMessage() . "\n");
    fwrite(STDERR, "  provider=" . $e->provider . " status=" . ($e->httpStatus ?? '-') . "\n");
    if ($e->responseBody !== null) {
        fwrite(STDERR, "  body: " . mb_substr($e->responseBody, 0, 500) . "\n");
    }
    exit(1);
} catch (\Throwable $e) {
    fwrite(STDERR, "❌ " . get_class($e) . ": " . $e->getMessage() . "\n");
    fwrite(STDERR, $e->getTraceAsString() . "\n");
    exit(1);
}
