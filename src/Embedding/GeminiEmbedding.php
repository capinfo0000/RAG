<?php
declare(strict_types=1);

namespace App\Embedding;

use App\Config;
use App\Llm\LlmException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

/**
 * Google Gemini Embedding（無料枠あり）
 *
 * Model: gemini-embedding-001 → 3072次元（現行Gemini API。旧 text-embedding-004 は v1beta で404）
 *
 * Endpoint:
 *   - 単体:   /v1beta/models/{model}:embedContent
 *   - バッチ: /v1beta/models/{model}:batchEmbedContents
 *
 * taskType マッピング:
 *   'document' → RETRIEVAL_DOCUMENT
 *   'query'    → RETRIEVAL_QUERY
 */
final class GeminiEmbedding implements EmbeddingProviderInterface
{
    private const BASE_URL = 'https://generativelanguage.googleapis.com/v1beta';
    private const PROVIDER_NAME = 'gemini';
    private const DEFAULT_DIMENSION = 3072;

    private readonly Client $http;
    private readonly \App\Llm\GeminiKeyPool $keyPool;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model = 'gemini-embedding-001',
        private readonly int $dimension = self::DEFAULT_DIMENSION,
        ?Client $http = null,
    ) {
        // 生成側と同じキープール（GEMINI_API_KEY / _2 / _3 … or GEMINI_API_KEYS）。
        // 埋め込みはモデル＝次元が同じなのでキー切替でベクトル検索は壊れない。
        $this->keyPool = \App\Llm\GeminiKeyPool::fromConfig($apiKey);
        if ($this->keyPool->isEmpty()) {
            throw new LlmException('Gemini API key is empty', self::PROVIDER_NAME);
        }
        $this->http = $http ?? new Client([
            'timeout' => 60,
            'connect_timeout' => 10,
        ]);
    }

    public static function fromConfig(?string $apiKey = null, ?string $model = null): self
    {
        return new self(
            apiKey: $apiKey ?? (string) Config::get('GEMINI_API_KEY', ''),
            model: $model ?? (string) Config::get('GEMINI_EMBEDDING_MODEL', 'gemini-embedding-001'),
        );
    }

    public function embed(array $texts, string $taskType = 'document'): array
    {
        if ($texts === []) {
            return [];
        }
        $geminiTask = $this->mapTaskType($taskType);
        $modelPath = 'models/' . $this->model;

        // 単一の場合は :embedContent でも可だが、APIを統一する意味でも :batchEmbedContents を常用。
        $requests = array_map(
            fn(string $t) => [
                'model' => $modelPath,
                'content' => ['parts' => [['text' => $t]]],
                'task_type' => $geminiTask,
            ],
            array_values($texts),
        );

        // APIキーはURL(?key=)ではなく x-goog-api-key ヘッダで送る（漏洩経路の根絶）。
        $url = sprintf(
            '%s/%s:batchEmbedContents',
            self::BASE_URL,
            $modelPath,
        );

        $res = null;
        $last = null;
        // available() は前回成功キーの次から 1→2→3→1… とラウンドロビンで回す（クールダウン中は飛ばす）。
        foreach ($this->keyPool->available() as $idx => $key) {
            try {
                $res = $this->http->request('POST', $url, [
                    'json' => ['requests' => $requests],
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'x-goog-api-key' => $key,
                    ],
                ]);
                $last = null;
                $this->keyPool->advanceAfter($idx); // 成功 → 次回は次のキーから
                break; // 成功
            } catch (RequestException $e) {
                $status = $e->getResponse()?->getStatusCode();
                $body = null;
                try {
                    $body = (string) $e->getResponse()?->getBody();
                } catch (\Throwable) {
                    $body = null;
                }
                if (!\App\Llm\GeminiKeyPool::shouldRotate($status, $body)) {
                    // 400(不正)/404(モデル無し)等はどのキーでも同じ結果 → 回さず即失敗（真因を隠さない）
                    throw $this->wrapException($e, 'batchEmbedContents');
                }
                $cool = ($status === 429)
                    ? \App\Llm\GeminiKeyPool::cooldownFor($body)
                    : \App\Llm\GeminiKeyPool::TRANSIENT_COOLDOWN;
                $this->keyPool->markCooldown($key, $cool);
                $last = $e;
                continue; // 次のキーへ
            }
        }
        if ($res === null) {
            if ($last !== null) {
                throw $this->wrapException($last, 'batchEmbedContents'); // 全キー失敗
            }
            throw new LlmException(
                'All Gemini API keys are exhausted (rate-limited or failing). Tried '
                    . $this->keyPool->total() . ' key(s).',
                self::PROVIDER_NAME,
                429,
                'quota exceeded: all keys cooling down',
            );
        }

        // ここに来たら $res は成功レスポンス
        try {
            $data = json_decode((string) $res->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new LlmException(
                'Gemini embedding response is not valid JSON: ' . $e->getMessage(),
                self::PROVIDER_NAME,
                $res->getStatusCode(),
                (string) $res->getBody(),
                $e,
            );
        }

        if (isset($data['error'])) {
            throw new LlmException(
                'Gemini embedding error: ' . ($data['error']['message'] ?? 'unknown'),
                self::PROVIDER_NAME,
                (int) ($data['error']['code'] ?? 0),
                json_encode($data['error']) ?: null,
            );
        }

        $vectors = [];
        foreach ($data['embeddings'] ?? [] as $emb) {
            $vectors[] = array_map('floatval', $emb['values'] ?? []);
        }
        if (count($vectors) !== count($texts)) {
            throw new LlmException(
                sprintf('Gemini returned %d embeddings for %d inputs', count($vectors), count($texts)),
                self::PROVIDER_NAME,
                $res->getStatusCode(),
                (string) $res->getBody(),
            );
        }
        return $vectors;
    }

    public function embedOne(string $text, string $taskType = 'query'): array
    {
        $result = $this->embed([$text], $taskType);
        return $result[0] ?? [];
    }

    public function getDimension(): int
    {
        return $this->dimension;
    }

    public function getProviderName(): string
    {
        return self::PROVIDER_NAME;
    }

    public function getModelName(): string
    {
        return $this->model;
    }

    private function mapTaskType(string $taskType): string
    {
        return match (strtolower($taskType)) {
            'document', 'retrieval_document' => 'RETRIEVAL_DOCUMENT',
            'query', 'retrieval_query' => 'RETRIEVAL_QUERY',
            'similarity', 'semantic_similarity' => 'SEMANTIC_SIMILARITY',
            'classification' => 'CLASSIFICATION',
            'clustering' => 'CLUSTERING',
            'qa', 'question_answering' => 'QUESTION_ANSWERING',
            default => 'RETRIEVAL_DOCUMENT',
        };
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
        error_log('[gemini-embedding] ' . $op . ' raw: ' . LlmException::sanitize($e->getMessage()));
        return new LlmException(
            sprintf('Gemini embedding %s failed: %s', $op, LlmException::sanitize($e->getMessage())),
            self::PROVIDER_NAME,
            $resp?->getStatusCode(),
            $bodyExcerpt !== null ? LlmException::sanitize($bodyExcerpt) : null,
            $e,
        );
    }
}
