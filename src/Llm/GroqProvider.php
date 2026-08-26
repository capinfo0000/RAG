<?php
declare(strict_types=1);

namespace App\Llm;

use App\Config;
use GuzzleHttp\Client;

/**
 * Groq Provider（OpenAI互換API、完全無料・高速）
 *
 * Endpoint: https://api.groq.com/openai/v1
 *
 * 主な無料モデル（2026年5月時点）:
 *   - llama-3.3-70b-versatile  : 推奨、高品質、日本語対応
 *   - llama-3.1-8b-instant     : 超高速・軽量
 *   - deepseek-r1-distill-llama-70b : 推論強化版
 *   - mixtral-8x7b-32768       : 長文対応 (32k context)
 *
 * 無料枠: 30 RPM / 6000 RPD / 30000 TPM / 7200 TPD (2026年5月時点)
 *   登録: https://console.groq.com/keys （クレカ不要）
 *
 * OpenAI Chat Completions API と同形なので OpenAiProvider を継承。
 */
final class GroqProvider extends OpenAiProvider
{
    public const PROVIDER_NAME = 'groq';
    public const DEFAULT_BASE_URL_GROQ = 'https://api.groq.com/openai/v1';

    public function __construct(
        string $apiKey,
        string $model = 'llama-3.3-70b-versatile',
        int $defaultMaxTokens = 2048,
        ?Client $http = null,
    ) {
        if ($apiKey === '') {
            throw new LlmException('Groq API key is empty', self::PROVIDER_NAME);
        }
        parent::__construct(
            apiKey: $apiKey,
            model: $model,
            baseUrl: self::DEFAULT_BASE_URL_GROQ,
            defaultMaxTokens: $defaultMaxTokens,
            http: $http,
        );
    }

    public static function fromConfig(?string $apiKey = null, ?string $model = null): self
    {
        return new self(
            apiKey: $apiKey ?? (string) Config::get('GROQ_API_KEY', ''),
            model: $model ?? (string) Config::get('GROQ_MODEL', 'llama-3.3-70b-versatile'),
            defaultMaxTokens: (int) Config::get('GROQ_MAX_TOKENS', 2048),
        );
    }

    protected static function providerName(): string
    {
        return self::PROVIDER_NAME;
    }
}
