<?php
declare(strict_types=1);

/**
 * Gemini 動作確認スクリプト
 *
 * 実行: cd demo && php scripts/test_gemini.php
 * 前提: composer install 実行済み、demo/.env に GEMINI_API_KEY 設定済み
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Config;
use App\Llm\GeminiProvider;
use App\Llm\LlmException;
use App\Llm\LlmMessage;

Config::load(__DIR__ . '/..');

$apiKey = (string) Config::get('GEMINI_API_KEY', '');
if ($apiKey === '') {
    fwrite(STDERR, "ERROR: GEMINI_API_KEY が .env に設定されていません\n");
    exit(1);
}

echo "==========================================\n";
echo " Gemini Provider 動作確認\n";
echo "==========================================\n";
echo "Model: " . Config::get('GEMINI_MODEL', 'gemini-2.5-flash') . "\n\n";

$provider = GeminiProvider::fromConfig();

try {
    // -------- 1. 非ストリーミング --------
    echo "▼ generate() テスト\n";
    echo "Q: あなたは何ができるAIですか？1文で簡潔に答えて。\n";
    $start = microtime(true);
    $resp = $provider->generate(
        messages: [LlmMessage::user('あなたは何ができるAIですか？1文で簡潔に答えて。')],
        options: ['temperature' => 0.3, 'max_tokens' => 200],
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

    // -------- 2. ストリーミング --------
    echo "▼ stream() テスト\n";
    echo "Q: RAGとは何か3行で説明して。\n";
    echo "A: ";
    $start = microtime(true);
    $gen = $provider->stream(
        messages: [LlmMessage::user('RAGとは何か3行で説明して。')],
        options: ['temperature' => 0.3, 'max_tokens' => 300],
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

    // -------- 3. システムプロンプト --------
    echo "▼ system instruction + 多ターン テスト\n";
    $messages = [
        LlmMessage::user('こんにちは、製品の名前は？'),
        LlmMessage::assistant('「RAG Chatbot Demo」です。'),
        LlmMessage::user('じゃあその料金は？'),
    ];
    $resp = $provider->generate(
        messages: $messages,
        options: [
            'system' => 'あなたは「RAG Chatbot Demo」という製品のサポート担当です。料金は月額10万円です。',
            'temperature' => 0.0,
            'max_tokens' => 200,
        ],
    );
    echo "A: " . $resp->content . "\n";

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
