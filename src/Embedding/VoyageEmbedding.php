<?php
declare(strict_types=1);

namespace App\Embedding;

use App\Config;
use App\Llm\LlmException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

/**
 * Voyage AI Embeddings（Anthropic公式推奨）
 *
 * Endpoint: https://api.voyageai.com/v1/embeddings
 *
 * Model:
 *   voyage-3.5-lite     → 1024次元（推奨、低コスト）
 *   voyage-3.5          → 1024次元
 *   voyage-3-large      → 1024次元
 *   voyage-code-3       → 1024次元
 *
 * input_type:
 *   'document' / 'query'  ※taskTypeをそのまま渡す
 */
final class VoyageEmbedding implements EmbeddingProviderInterface
{
    private const BASE_URL = 'https://api.voyageai.com/v1';
    private const PROVIDER_NAME = 'voyage';

    private const DIMENSION_TABLE = [
        'voyage-3.5-lite' => 1024,
        'voyage-3.5' => 1024,
        'voyage-3-large' => 1024,
        'voyage-code-3' => 1024,
        'voyage-large-2' => 1536,
    ];

    private readonly Client $http;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model = 'voyage-3.5-lite',
        ?Client $http = null,
    ) {
        if ($apiKey === '') {
            throw new LlmException('Voyage API key is empty', self::PROVIDER_NAME);
        }
        $this->http = $http ?? new Client([
            'timeout' => 60,
            'connect_timeout' => 10,
        ]);
    }

    public static function fromConfig(?string $apiKey = null, ?string $model = null): self
    {
        return new self(
            apiKey: $apiKey ?? (string) Config::get('VOYAGE_API_KEY', ''),
            model: $model ?? (string) Config::get('VOYAGE_MODEL', 'voyage-3.5-lite'),
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
            'input_type' => in_array(strtolower($taskType), ['query', 'retrieval_query'], true)
                ? 'query'
                : 'document',
        ];

        try {
            $res = $this->http->request('POST', self::BASE_URL . '/embeddings', [
                'json' => $body,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $this->apiKey,
                ],
            ]);
        } catch (RequestException $e) {
            throw $this->wrapException($e, 'embeddings');
        }

        try {
            $data = json_decode((string) $res->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new LlmException(
                'Voyage embedding response is not valid JSON: ' . $e->getMessage(),
                self::PROVIDER_NAME,
                $res->getStatusCode(),
                (string) $res->getBody(),
                $e,
            );
        }
        if (isset($data['error'])) {
            throw new LlmException(
                'Voyage embedding error: ' . ($data['error']['message'] ?? 'unknown'),
                self::PROVIDER_NAME,
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
                sprintf('Voyage returned %d embeddings for %d inputs', count($vectors), count($texts)),
                self::PROVIDER_NAME,
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
        return self::DIMENSION_TABLE[$this->model] ?? 1024;
    }

    public function getProviderName(): string
    {
        return self::PROVIDER_NAME;
    }

    public function getModelName(): string
    {
        return $this->model;
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
        error_log('[voyage-embedding] ' . $op . ' raw: ' . LlmException::sanitize($e->getMessage()));
        return new LlmException(
            sprintf('Voyage %s failed: %s', $op, LlmException::sanitize($e->getMessage())),
            self::PROVIDER_NAME,
            $resp?->getStatusCode(),
            $bodyExcerpt !== null ? LlmException::sanitize($bodyExcerpt) : null,
            $e,
        );
    }
}
