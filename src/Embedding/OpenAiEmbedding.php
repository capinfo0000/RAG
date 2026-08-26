<?php
declare(strict_types=1);

namespace App\Embedding;

use App\Config;
use App\Llm\LlmException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

/**
 * OpenAI Embeddings
 *
 * Endpoint: {base}/embeddings
 *
 * Model:
 *   text-embedding-3-small → 1536次元
 *   text-embedding-3-large → 3072次元
 *   text-embedding-ada-002 → 1536次元
 *
 * baseUrl を差し替えれば Ollama / LM Studio など OpenAI互換サーバでも動く。
 * OllamaEmbedding は本クラスを継承する。
 */
class OpenAiEmbedding implements EmbeddingProviderInterface
{
    public const DEFAULT_BASE_URL = 'https://api.openai.com/v1';
    public const PROVIDER_NAME = 'openai';

    private const DIMENSION_TABLE = [
        'text-embedding-3-small' => 1536,
        'text-embedding-3-large' => 3072,
        'text-embedding-ada-002' => 1536,
    ];

    protected readonly Client $http;

    public function __construct(
        protected readonly string $apiKey,
        protected readonly string $model = 'text-embedding-3-small',
        protected readonly string $baseUrl = self::DEFAULT_BASE_URL,
        protected readonly ?int $dimensionOverride = null,
        ?Client $http = null,
    ) {
        if ($apiKey === '' && static::providerName() === 'openai') {
            throw new LlmException('OpenAI API key is empty', static::providerName());
        }
        $this->http = $http ?? new Client([
            'timeout' => 60,
            'connect_timeout' => 10,
        ]);
    }

    public static function fromConfig(?string $apiKey = null, ?string $model = null): self
    {
        return new self(
            apiKey: $apiKey ?? (string) Config::get('OPENAI_API_KEY', ''),
            model: $model ?? (string) Config::get('OPENAI_EMBEDDING_MODEL', 'text-embedding-3-small'),
        );
    }

    public function embed(array $texts, string $taskType = 'document'): array
    {
        if ($texts === []) {
            return [];
        }

        $body = [
            'model' => $this->model,
            'input' => array_values($texts),
        ];

        try {
            $res = $this->http->request('POST', $this->endpoint(), [
                'json' => $body,
                'headers' => $this->buildHeaders(),
            ]);
        } catch (RequestException $e) {
            throw $this->wrapException($e, 'embeddings');
        }

        try {
            $data = json_decode((string) $res->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new LlmException(
                static::providerName() . ' embedding response is not valid JSON: ' . $e->getMessage(),
                static::providerName(),
                $res->getStatusCode(),
                (string) $res->getBody(),
                $e,
            );
        }
        if (isset($data['error'])) {
            throw new LlmException(
                static::providerName() . ' embedding error: ' . ($data['error']['message'] ?? 'unknown'),
                static::providerName(),
                null,
                json_encode($data['error']) ?: null,
            );
        }

        $vectors = [];
        foreach ($data['data'] ?? [] as $item) {
            $vectors[] = array_map('floatval', $item['embedding'] ?? []);
        }
        if (count($vectors) !== count($texts)) {
            throw new LlmException(
                sprintf(
                    '%s returned %d embeddings for %d inputs',
                    static::providerName(),
                    count($vectors),
                    count($texts),
                ),
                static::providerName(),
                $res->getStatusCode(),
                (string) $res->getBody(),
            );
        }
        return $vectors;
    }

    public function embedOne(string $text, string $taskType = 'query'): array
    {
        $r = $this->embed([$text], $taskType);
        return $r[0] ?? [];
    }

    public function getDimension(): int
    {
        if ($this->dimensionOverride !== null) {
            return $this->dimensionOverride;
        }
        return self::DIMENSION_TABLE[$this->model] ?? 1536;
    }

    public function getProviderName(): string
    {
        return static::providerName();
    }

    public function getModelName(): string
    {
        return $this->model;
    }

    protected static function providerName(): string
    {
        return self::PROVIDER_NAME;
    }

    protected function endpoint(): string
    {
        return rtrim($this->baseUrl, '/') . '/embeddings';
    }

    protected function buildHeaders(): array
    {
        $h = ['Content-Type' => 'application/json'];
        if ($this->apiKey !== '') {
            $h['Authorization'] = 'Bearer ' . $this->apiKey;
        }
        return $h;
    }

    private function wrapException(RequestException $e, string $op): LlmException
    {
        $resp = $e->getResponse();
        $bodyExcerpt = null;
        if ($resp !== null) {
            try {
                $bodyExcerpt = (string) $resp->getBody();
            } catch (\Throwable) {
                $bodyExcerpt = null;
            }
        }
        error_log('[' . static::providerName() . '-embedding] ' . $op . ' raw: ' . LlmException::sanitize($e->getMessage()));
        return new LlmException(
            sprintf('%s %s failed: %s', static::providerName(), $op, LlmException::sanitize($e->getMessage())),
            static::providerName(),
            $resp?->getStatusCode(),
            $bodyExcerpt !== null ? LlmException::sanitize($bodyExcerpt) : null,
            $e,
        );
    }
}
