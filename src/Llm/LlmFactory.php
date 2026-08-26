<?php
declare(strict_types=1);

namespace App\Llm;

use App\Config;
use App\Models\Settings;

/**
 * 環境変数 / DBのsettings から LlmProvider を組み立てるファクトリ。
 *
 * 解決順序（先勝ち）：
 *   1. 引数 $providerName で明示指定
 *   2. settings テーブル（DB） llm_provider / llm_api_key_encrypted / llm_model
 *   3. .env の LLM_PROVIDER / ANTHROPIC_API_KEY / ANTHROPIC_MODEL ...
 *   4. デフォルト 'gemini'
 *
 * 動的切替（管理画面でAPIキーを入れて即時反映）の中核。
 */
final class LlmFactory
{
    private const SUPPORTED = ['gemini', 'anthropic', 'openai', 'groq', 'ollama'];

    public static function create(?string $providerName = null): LlmProviderInterface
    {
        // DB > env > default の順で provider 決定
        $name = $providerName;
        if ($name === null || $name === '') {
            try {
                $name = Settings::effective('llm_provider', 'LLM_PROVIDER', 'gemini');
            } catch (\Throwable) {
                // DB未接続なら env だけで決定（テストやスクリプト時）
                $name = (string) Config::get('LLM_PROVIDER', 'gemini');
            }
        }
        $name = strtolower(trim((string) $name));

        // DB に保存された API キー / モデルを優先的に読む
        [$apiKey, $model] = self::resolveApiKeyAndModel($name);

        // Base URL（ローカルLLM 等で必須）。DB > env > default
        $baseUrl = '';
        try {
            $dbBase = Settings::get('llm_base_url');
            if (is_string($dbBase) && trim($dbBase) !== '') {
                $baseUrl = trim($dbBase);
            }
        } catch (\Throwable) {
            // DB未接続なら env のみ
        }
        if ($baseUrl === '') {
            $baseUrl = (string) Config::get('OLLAMA_BASE_URL', OllamaProvider::DEFAULT_BASE_URL_LOCAL);
        }

        return match ($name) {
            'gemini' => new GeminiProvider($apiKey, $model ?: 'gemini-flash-latest'),
            'anthropic', 'claude' => new AnthropicProvider($apiKey, $model ?: 'claude-sonnet-5'),
            'openai' => new OpenAiProvider($apiKey, $model ?: 'gpt-4o-mini'),
            'groq' => new GroqProvider($apiKey, $model ?: 'llama-3.3-70b-versatile'),
            'ollama', 'local', 'lmstudio', 'vllm' => new OllamaProvider(
                $apiKey !== '' ? $apiKey : 'ollama',
                $model ?: 'llama3.2:latest',
                $baseUrl,
            ),
            default => throw new \InvalidArgumentException(
                "Unknown LLM provider '{$name}'. Supported: " . implode(', ', self::SUPPORTED)
            ),
        };
    }

    /**
     * @return array{0:string, 1:string} apiKey, model（空文字なら fallback 側）
     */
    private static function resolveApiKeyAndModel(string $name): array
    {
        // DB のモデル名
        $model = '';
        try {
            $dbModel = Settings::get('llm_model');
            if (is_string($dbModel) && $dbModel !== '') {
                $model = $dbModel;
            }
        } catch (\Throwable) {
            // ignore
        }

        // env キー名のマッピング
        [$envKey, $envModel, $defaultModel] = match ($name) {
            'gemini' => ['GEMINI_API_KEY', 'GEMINI_MODEL', 'gemini-flash-latest'],
            'anthropic', 'claude' => ['ANTHROPIC_API_KEY', 'ANTHROPIC_MODEL', 'claude-sonnet-5'],
            'openai' => ['OPENAI_API_KEY', 'OPENAI_MODEL', 'gpt-4o-mini'],
            'groq' => ['GROQ_API_KEY', 'GROQ_MODEL', 'llama-3.3-70b-versatile'],
            'ollama', 'local', 'lmstudio', 'vllm' => ['OLLAMA_API_KEY', 'OLLAMA_MODEL', 'llama3.2:latest'],
            default => ['', '', ''],
        };

        // model: DB > env > default
        if ($model === '') {
            $envM = (string) Config::get($envModel, '');
            $model = $envM !== '' ? $envM : $defaultModel;
        }

        // API key: DB(暗号化解除) > env
        $apiKey = '';
        try {
            $apiKey = (string) (Settings::effectiveApiKey('llm_api_key_encrypted', $envKey) ?? '');
        } catch (\Throwable) {
            $apiKey = (string) Config::get($envKey, '');
        }

        return [$apiKey, $model];
    }

    /**
     * セカンダリ（高精度・低速側）LLM を組み立てる。複雑な質問のルーティング先。
     *
     * settings/env の llm2_provider / llm2_model / llm2_base_url / llm2_api_key_encrypted を読む。
     * llm2_provider が未設定なら null を返す（＝ルーティング無効、プライマリ単独運用）。
     */
    public static function createSecondary(): ?LlmProviderInterface
    {
        $name = '';
        try {
            $v = Settings::get('llm2_provider');
            $name = is_string($v) ? $v : '';
        } catch (\Throwable) {
            $name = (string) Config::get('LLM2_PROVIDER', '');
        }
        $name = strtolower(trim($name));
        if ($name === '') {
            return null;
        }

        $model = '';
        try {
            $v = Settings::get('llm2_model');
            if (is_string($v)) {
                $model = trim($v);
            }
        } catch (\Throwable) {
            // ignore
        }
        if ($model === '') {
            $model = (string) Config::get('LLM2_MODEL', '');
        }

        $baseUrl = '';
        try {
            $v = Settings::get('llm2_base_url');
            if (is_string($v)) {
                $baseUrl = trim($v);
            }
        } catch (\Throwable) {
            // ignore
        }
        if ($baseUrl === '') {
            $baseUrl = (string) Config::get('LLM2_BASE_URL', '');
        }

        $apiKey = '';
        try {
            $apiKey = (string) (Settings::effectiveApiKey('llm2_api_key_encrypted', 'LLM2_API_KEY') ?? '');
        } catch (\Throwable) {
            $apiKey = (string) Config::get('LLM2_API_KEY', '');
        }

        return self::build(
            $name,
            $apiKey,
            $model !== '' ? $model : null,
            $baseUrl !== '' ? $baseUrl : null,
        );
    }

    /**
     * 任意の設定値で組み立てる（管理画面で「テスト送信」する時に使う想定）。
     */
    public static function build(
        string $providerName,
        string $apiKey,
        ?string $model = null,
        ?string $baseUrl = null,
    ): LlmProviderInterface {
        $name = strtolower(trim($providerName));
        return match ($name) {
            'gemini' => new GeminiProvider($apiKey, $model ?? 'gemini-flash-latest'),
            'anthropic', 'claude' => new AnthropicProvider($apiKey, $model ?? 'claude-sonnet-5'),
            'openai' => new OpenAiProvider(
                apiKey: $apiKey,
                model: $model ?? 'gpt-4o-mini',
                baseUrl: $baseUrl ?? OpenAiProvider::DEFAULT_BASE_URL,
            ),
            'groq' => new GroqProvider($apiKey, $model ?? 'llama-3.3-70b-versatile'),
            'ollama', 'local', 'lmstudio', 'vllm' => new OllamaProvider(
                apiKey: $apiKey !== '' ? $apiKey : 'ollama',
                model: $model ?? 'llama3.2:latest',
                baseUrl: $baseUrl ?? OllamaProvider::DEFAULT_BASE_URL_LOCAL,
            ),
            default => throw new \InvalidArgumentException(
                "Unknown LLM provider '{$name}'. Supported: " . implode(', ', self::SUPPORTED)
            ),
        };
    }

    /** @return string[] */
    public static function supported(): array
    {
        return self::SUPPORTED;
    }
}
