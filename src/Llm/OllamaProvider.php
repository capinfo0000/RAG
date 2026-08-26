<?php
declare(strict_types=1);

namespace App\Llm;

use App\Config;
use GuzzleHttp\Client;

/**
 * Ollama / LM Studio / vLLM など OpenAI互換ローカルLLM Provider。
 *
 * OpenAI Chat Completions API と同形なので OpenAiProvider を継承し、
 * baseUrl と providerName をローカル向けに差し替える。
 */
final class OllamaProvider extends OpenAiProvider
{
    public const PROVIDER_NAME = 'ollama';
    public const DEFAULT_BASE_URL_LOCAL = 'http://localhost:11434/v1';

    public function __construct(
        string $apiKey = 'ollama',
        string $model = 'llama3.2:latest',
        string $baseUrl = self::DEFAULT_BASE_URL_LOCAL,
        int $defaultMaxTokens = 2048,
        ?Client $http = null,
        ?int $httpTimeout = null,
    ) {
        // ローカル思考モデル（qwen等）は大きな文脈で生成が長引くため既定を長めに。
        parent::__construct(
            apiKey: $apiKey,
            model: $model,
            baseUrl: $baseUrl,
            defaultMaxTokens: $defaultMaxTokens,
            http: $http,
            httpTimeout: $httpTimeout ?? (int) Config::get('LLM_HTTP_TIMEOUT', 600),
        );
    }

    /** LM Studio は response_format=json_object 非対応（json_schema/text のみ）。 */
    protected function supportsJsonObjectFormat(): bool
    {
        return false;
    }

    public static function fromConfig(?string $apiKey = null, ?string $model = null): self
    {
        return new self(
            apiKey: $apiKey ?? (string) Config::get('OLLAMA_API_KEY', 'ollama'),
            model: $model ?? (string) Config::get('OLLAMA_MODEL', 'llama3.2:latest'),
            baseUrl: (string) Config::get('OLLAMA_BASE_URL', self::DEFAULT_BASE_URL_LOCAL),
        );
    }

    protected static function providerName(): string
    {
        return self::PROVIDER_NAME;
    }
}
