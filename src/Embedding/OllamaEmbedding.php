<?php
declare(strict_types=1);

namespace App\Embedding;

use App\Config;
use GuzzleHttp\Client;

/**
 * Ollama Embedding（OpenAI互換 /v1/embeddings エンドポイントを使用）
 *
 * Model:
 *   nomic-embed-text → 768次元
 *   mxbai-embed-large → 1024次元
 *
 * Ollama 0.1.41+ から OpenAI互換APIをサポート。古い場合は /api/embeddings を使う必要あり。
 */
final class OllamaEmbedding extends OpenAiEmbedding
{
    public const PROVIDER_NAME = 'ollama';
    public const DEFAULT_BASE_URL_LOCAL = 'http://localhost:11434/v1';

    private const DIMENSION_TABLE_LOCAL = [
        'nomic-embed-text' => 768,
        'mxbai-embed-large' => 1024,
        'all-minilm' => 384,
    ];

    public function __construct(
        string $apiKey = 'ollama',
        string $model = 'nomic-embed-text',
        string $baseUrl = self::DEFAULT_BASE_URL_LOCAL,
        ?int $dimensionOverride = null,
        ?Client $http = null,
    ) {
        parent::__construct(
            apiKey: $apiKey,
            model: $model,
            baseUrl: $baseUrl,
            dimensionOverride: $dimensionOverride,
            http: $http,
        );
    }

    public static function fromConfig(?string $apiKey = null, ?string $model = null): self
    {
        return new self(
            apiKey: $apiKey ?? (string) Config::get('OLLAMA_API_KEY', 'ollama'),
            model: $model ?? (string) Config::get('OLLAMA_EMBEDDING_MODEL', 'nomic-embed-text'),
            baseUrl: (string) Config::get('OLLAMA_BASE_URL', self::DEFAULT_BASE_URL_LOCAL),
        );
    }

    public function getDimension(): int
    {
        if ($this->dimensionOverride !== null) {
            return $this->dimensionOverride;
        }
        return self::DIMENSION_TABLE_LOCAL[$this->model] ?? 768;
    }

    protected static function providerName(): string
    {
        return self::PROVIDER_NAME;
    }
}
