<?php
declare(strict_types=1);

namespace App\Llm;

use App\Config;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use Psr\Http\Message\StreamInterface;

/**
 * OpenAI Provider（Chat Completions API）
 *
 * Endpoint: {base_url}/chat/completions
 *
 * baseUrl を差し替えれば OpenAI互換のエンドポイント（Ollama / LM Studio / vLLM 等）も
 * 利用可。OllamaProvider はこれを継承して baseUrl のデフォルトを差し替えるだけ。
 */
class OpenAiProvider implements LlmProviderInterface
{
    public const DEFAULT_BASE_URL = 'https://api.openai.com/v1';
    public const PROVIDER_NAME = 'openai';

    protected readonly Client $http;

    public function __construct(
        protected readonly string $apiKey,
        protected readonly string $model = 'gpt-4o-mini',
        protected readonly string $baseUrl = self::DEFAULT_BASE_URL,
        protected readonly int $defaultMaxTokens = 2048,
        ?Client $http = null,
        int $httpTimeout = 120,
    ) {
        // Ollama 等の互換エンドポイントは API キー任意。
        // OpenAI公式の場合のみ空チェックする（providerName で判定）。
        if ($apiKey === '' && static::providerName() === 'openai') {
            throw new LlmException('OpenAI API key is empty', static::providerName());
        }
        // stream: true 時に StreamHandler が選択されて SSL 失敗するのを回避
        $this->http = $http ?? new Client([
            'timeout' => max(1, $httpTimeout),
            'connect_timeout' => 10,
            'handler' => HandlerStack::create(new CurlHandler()),
        ]);
    }

    public static function fromConfig(?string $apiKey = null, ?string $model = null): self
    {
        return new self(
            apiKey: $apiKey ?? (string) Config::get('OPENAI_API_KEY', ''),
            model: $model ?? (string) Config::get('OPENAI_MODEL', 'gpt-4o-mini'),
            defaultMaxTokens: (int) Config::get('OPENAI_MAX_TOKENS', 2048),
        );
    }

    public function generate(array $messages, array $options = []): LlmResponse
    {
        $body = $this->buildBody($messages, $options, stream: false);

        try {
            $res = $this->http->request('POST', $this->endpoint(), [
                'json' => $body,
                'headers' => $this->buildHeaders(),
            ]);
        } catch (RequestException $e) {
            throw $this->wrapException($e, 'chat.completions');
        }

        try {
            $data = json_decode((string) $res->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new LlmException(
                static::providerName() . ' response is not valid JSON: ' . $e->getMessage(),
                static::providerName(),
                $res->getStatusCode(),
                (string) $res->getBody(),
                $e,
            );
        }

        return $this->parseFinalResponse($data);
    }

    public function stream(array $messages, array $options = []): \Generator
    {
        $body = $this->buildBody($messages, $options, stream: true);

        try {
            $res = $this->http->request('POST', $this->endpoint(), [
                'json' => $body,
                'headers' => $this->buildHeaders() + ['Accept' => 'text/event-stream'],
                'stream' => true,
            ]);
        } catch (RequestException $e) {
            throw $this->wrapException($e, 'chat.completions.stream');
        }

        $stream = $res->getBody();
        $fullText = '';
        $finishReason = null;
        $inputTokens = null;
        $outputTokens = null;

        foreach ($this->readSseLines($stream) as $line) {
            if ($line === '' || !str_starts_with($line, 'data:')) {
                continue;
            }
            $json = ltrim(substr($line, 5));
            if ($json === '' || $json === '[DONE]') {
                continue;
            }
            try {
                $chunk = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }
            if (!is_array($chunk)) {
                continue;
            }
            if (isset($chunk['error'])) {
                throw new LlmException(
                    static::providerName() . ' stream error: ' . ($chunk['error']['message'] ?? 'unknown'),
                    static::providerName(),
                    null,
                    json_encode($chunk['error']) ?: null,
                );
            }

            $delta = $chunk['choices'][0]['delta']['content'] ?? '';
            if (is_string($delta) && $delta !== '') {
                $fullText .= $delta;
                yield $delta;
            }
            if (isset($chunk['choices'][0]['finish_reason'])) {
                $finishReason = $chunk['choices'][0]['finish_reason'];
            }
            if (isset($chunk['usage'])) {
                $inputTokens = $chunk['usage']['prompt_tokens'] ?? $inputTokens;
                $outputTokens = $chunk['usage']['completion_tokens'] ?? $outputTokens;
            }
        }

        return new LlmResponse(
            content: $fullText,
            citations: [],
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            finishReason: $finishReason,
            model: $this->model,
            provider: static::providerName(),
        );
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

    /** response_format={type:json_object} を送ってよいか（OpenAI公式系は対応）。 */
    protected function supportsJsonObjectFormat(): bool
    {
        return true;
    }

    protected function endpoint(): string
    {
        return rtrim($this->baseUrl, '/') . '/chat/completions';
    }

    /**
     * @param LlmMessage[] $messages
     */
    protected function buildBody(array $messages, array $options, bool $stream): array
    {
        $msgList = [];

        if (isset($options['system']) && $options['system'] !== '') {
            $msgList[] = ['role' => 'system', 'content' => (string) $options['system']];
        }

        foreach ($messages as $i => $msg) {
            if (!$msg instanceof LlmMessage) {
                throw new \InvalidArgumentException(
                    "messages[{$i}] must be an instance of LlmMessage"
                );
            }
            $msgList[] = ['role' => $msg->role, 'content' => $msg->content];
        }

        if ($msgList === []) {
            throw new \InvalidArgumentException('No messages provided.');
        }

        $body = [
            'model' => $this->model,
            'messages' => $msgList,
        ];
        if (isset($options['temperature'])) {
            $body['temperature'] = (float) $options['temperature'];
        }
        $body['max_tokens'] = (int) ($options['max_tokens'] ?? $this->defaultMaxTokens);
        if (isset($options['stop']) && is_array($options['stop']) && $options['stop'] !== []) {
            $body['stop'] = array_values($options['stop']);
        }
        // response_format=json_object は OpenAI公式系のみ。
        // LM Studio 等は json_object 非対応（json_schema/text のみ）なので送らない。
        // 各呼び出し側はプロンプトでJSONを強制し、テキストから堅牢にJSON抽出している。
        if (!empty($options['response_json']) && $this->supportsJsonObjectFormat()) {
            $body['response_format'] = ['type' => 'json_object'];
        }
        if ($stream) {
            $body['stream'] = true;
            // 一部の OpenAI 互換サーバは stream_options 未対応なので注意
            if (str_starts_with($this->baseUrl, self::DEFAULT_BASE_URL)) {
                $body['stream_options'] = ['include_usage' => true];
            }
        }
        return $body;
    }

    protected function buildHeaders(): array
    {
        $h = ['Content-Type' => 'application/json'];
        if ($this->apiKey !== '') {
            $h['Authorization'] = 'Bearer ' . $this->apiKey;
        }
        return $h;
    }

    private function parseFinalResponse(array $data): LlmResponse
    {
        if (isset($data['error'])) {
            throw new LlmException(
                static::providerName() . ' API error: ' . ($data['error']['message'] ?? 'unknown'),
                static::providerName(),
                null,
                json_encode($data['error']) ?: null,
            );
        }

        $text = (string) ($data['choices'][0]['message']['content'] ?? '');
        return new LlmResponse(
            content: $text,
            citations: [],
            inputTokens: $data['usage']['prompt_tokens'] ?? null,
            outputTokens: $data['usage']['completion_tokens'] ?? null,
            finishReason: $data['choices'][0]['finish_reason'] ?? null,
            model: $this->model,
            provider: static::providerName(),
            raw: $data,
        );
    }

    /**
     * @return \Generator<int, string>
     */
    protected function readSseLines(StreamInterface $stream): \Generator
    {
        $buffer = '';
        while (!$stream->eof()) {
            $chunk = $stream->read(8192);
            if ($chunk === '') {
                break;
            }
            $buffer .= $chunk;
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = rtrim(substr($buffer, 0, $pos), "\r");
                $buffer = substr($buffer, $pos + 1);
                yield $line;
            }
        }
        if ($buffer !== '') {
            yield rtrim($buffer, "\r");
        }
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
        error_log('[' . static::providerName() . '] ' . $op . ' raw: ' . LlmException::sanitize($e->getMessage()));
        return new LlmException(
            sprintf('%s %s failed: %s', static::providerName(), $op, LlmException::sanitize($e->getMessage())),
            static::providerName(),
            $resp?->getStatusCode(),
            $bodyExcerpt !== null ? LlmException::sanitize($bodyExcerpt) : null,
            $e,
        );
    }
}
