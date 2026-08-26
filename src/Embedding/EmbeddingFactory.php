<?php
declare(strict_types=1);

namespace App\Embedding;

use App\Config;
use App\Models\Settings;

/**
 * env / settings から EmbeddingProvider を組み立てるファクトリ。
 *
 * 解決順序: 引数 > DB(settings) > env > default
 * 'none' を選んだ場合は null を返し、呼び出し側は BM25（MySQL FULLTEXT）だけで
 * 検索する縮退モードで動作する。
 */
final class EmbeddingFactory
{
    private const SUPPORTED = ['gemini', 'openai', 'voyage', 'ollama', 'none'];

    public static function create(?string $providerName = null): ?EmbeddingProviderInterface
    {
        // DB > env > default の順で provider 決定
        $name = $providerName;
        if ($name === null || $name === '') {
            try {
                $name = Settings::effective('embedding_provider', 'EMBEDDING_PROVIDER', 'gemini');
            } catch (\Throwable) {
                $name = (string) Config::get('EMBEDDING_PROVIDER', 'gemini');
            }
        }
        $name = strtolower(trim((string) $name));

        // API キーは LLM と Embedding 別管理。embedding_api_key_encrypted が空なら LLM 用キーを流用。
        $apiKey = self::resolveApiKey($name);
        $model = self::resolveModel($name);

        return match ($name) {
            'none', '' => null,
            'gemini' => new GeminiEmbedding($apiKey, $model ?: 'gemini-embedding-001'),
            'openai' => new OpenAiEmbedding($apiKey, $model ?: 'text-embedding-3-small'),
            'voyage' => new VoyageEmbedding($apiKey, $model ?: 'voyage-3.5-lite'),
            'ollama', 'local' => new OllamaEmbedding(
                $apiKey !== '' ? $apiKey : 'ollama',
                $model ?: 'nomic-embed-text',
                self::resolveOllamaBaseUrl(),
            ),
            default => throw new \InvalidArgumentException(
                "Unknown embedding provider '{$name}'. Supported: " . implode(', ', self::SUPPORTED)
            ),
        };
    }

    private static function resolveApiKey(string $name): string
    {
        $envKey = match ($name) {
            'gemini' => 'GEMINI_API_KEY',
            'openai' => 'OPENAI_API_KEY',
            'voyage' => 'VOYAGE_API_KEY',
            'ollama', 'local' => 'OLLAMA_API_KEY',
            default => '',
        };
        try {
            $k = (string) (Settings::effectiveApiKey('embedding_api_key_encrypted', $envKey) ?? '');
            if ($k !== '') {
                return $k;
            }
            // Embedding 用が未設定なら LLM 用キーを流用（同プロバイダーなら共通）
            return (string) (Settings::effectiveApiKey('llm_api_key_encrypted', $envKey) ?? '');
        } catch (\Throwable) {
            return (string) Config::get($envKey, '');
        }
    }

    private static function resolveModel(string $name): string
    {
        try {
            $m = Settings::get('embedding_model');
            if (is_string($m) && $m !== '') {
                return $m;
            }
        } catch (\Throwable) {
            // ignore
        }
        $envModel = match ($name) {
            'gemini' => 'GEMINI_EMBEDDING_MODEL',
            'openai' => 'OPENAI_EMBEDDING_MODEL',
            'voyage' => 'VOYAGE_MODEL',
            'ollama', 'local' => 'OLLAMA_EMBEDDING_MODEL',
            default => '',
        };
        return (string) Config::get($envModel, '');
    }

    public static function build(
        string $providerName,
        string $apiKey,
        ?string $model = null,
        ?string $baseUrl = null,
    ): ?EmbeddingProviderInterface {
        $name = strtolower(trim($providerName));
        return match ($name) {
            'none', '' => null,
            'gemini' => new GeminiEmbedding($apiKey, $model ?? 'gemini-embedding-001'),
            'openai' => new OpenAiEmbedding(
                apiKey: $apiKey,
                model: $model ?? 'text-embedding-3-small',
                baseUrl: $baseUrl ?? OpenAiEmbedding::DEFAULT_BASE_URL,
            ),
            'voyage' => new VoyageEmbedding($apiKey, $model ?? 'voyage-3.5-lite'),
            'ollama', 'local' => new OllamaEmbedding(
                apiKey: $apiKey !== '' ? $apiKey : 'ollama',
                model: $model ?? 'nomic-embed-text',
                baseUrl: $baseUrl ?? OllamaEmbedding::DEFAULT_BASE_URL_LOCAL,
            ),
            default => throw new \InvalidArgumentException(
                "Unknown embedding provider '{$name}'. Supported: " . implode(', ', self::SUPPORTED)
            ),
        };
    }

    /**
     * Ollama 系の Base URL を解決する。
     * 優先順位: settings.llm_base_url（LM Studio など LLM と Embedding 同居前提）> env OLLAMA_BASE_URL > default
     * LLM と Embedding を別サーバに置きたい場合は将来 embedding_base_url を別 key で持つ。
     */
    private static function resolveOllamaBaseUrl(): string
    {
        try {
            $u = (string) (Settings::get('llm_base_url', '') ?? '');
            if (trim($u) !== '') {
                return trim($u);
            }
        } catch (\Throwable) {
            // ignore
        }
        return (string) Config::get('OLLAMA_BASE_URL', OllamaEmbedding::DEFAULT_BASE_URL_LOCAL);
    }

    /** @return string[] */
    public static function supported(): array
    {
        return self::SUPPORTED;
    }
}
