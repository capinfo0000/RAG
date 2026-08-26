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
 * Anthropic Claude Provider
 *
 * Endpoint: https://api.anthropic.com/v1/messages
 * Headers: x-api-key, anthropic-version
 *
 * Anthropic Messages API は role に 'user' / 'assistant' のみ受け付け、
 * system は body の 'system' フィールドに分離する。
 *
 * Citations は body に type=document の content block を含めると API側で
 * 引用箇所を返してくれるが、現状は Generator 側で [N] パース方式に統一。
 */
final class AnthropicProvider implements LlmProviderInterface
{
    private const BASE_URL = 'https://api.anthropic.com/v1';
    private const API_VERSION = '2023-06-01';
    private const PROVIDER_NAME = 'anthropic';

    private readonly Client $http;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model = 'claude-sonnet-5',
        private readonly int $defaultMaxTokens = 2048,
        ?Client $http = null,
    ) {
        if ($apiKey === '') {
            throw new LlmException('Anthropic API key is empty', self::PROVIDER_NAME);
        }
        // stream: true 時に StreamHandler が選択されて SSL 失敗するのを回避
        $this->http = $http ?? new Client([
            'timeout' => 120,
            'connect_timeout' => 10,
            'handler' => HandlerStack::create(new CurlHandler()),
        ]);
    }

    public static function fromConfig(?string $apiKey = null, ?string $model = null): self
    {
        return new self(
            apiKey: $apiKey ?? (string) Config::get('ANTHROPIC_API_KEY', ''),
            model: $model ?? (string) Config::get('ANTHROPIC_MODEL', 'claude-sonnet-5'),
            defaultMaxTokens: (int) Config::get('ANTHROPIC_MAX_TOKENS', 2048),
        );
    }

    public function generate(array $messages, array $options = []): LlmResponse
    {
        $body = $this->buildBody($messages, $options, stream: false);

        try {
            $res = $this->http->request('POST', self::BASE_URL . '/messages', [
                'json' => $body,
                'headers' => $this->buildHeaders(),
            ]);
        } catch (RequestException $e) {
            throw $this->wrapException($e, 'messages');
        }

        try {
            $data = json_decode((string) $res->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new LlmException(
                'Anthropic response is not valid JSON: ' . $e->getMessage(),
                self::PROVIDER_NAME,
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
            $res = $this->http->request('POST', self::BASE_URL . '/messages', [
                'json' => $body,
                'headers' => $this->buildHeaders() + ['Accept' => 'text/event-stream'],
                'stream' => true,
            ]);
        } catch (RequestException $e) {
            throw $this->wrapException($e, 'messages-stream');
        }

        $stream = $res->getBody();
        $fullText = '';
        $finishReason = null;
        $inputTokens = null;
        $outputTokens = null;

        $event = '';
        foreach ($this->readSseLines($stream) as $line) {
            if ($line === '') {
                $event = '';
                continue;
            }
            if (str_starts_with($line, 'event:')) {
                $event = trim(substr($line, 6));
                continue;
            }
            if (!str_starts_with($line, 'data:')) {
                continue;
            }
            $json = ltrim(substr($line, 5));
            if ($json === '') {
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

            $type = $chunk['type'] ?? $event;

            if ($type === 'content_block_delta' && isset($chunk['delta']['text'])) {
                $delta = (string) $chunk['delta']['text'];
                if ($delta !== '') {
                    $fullText .= $delta;
                    yield $delta;
                }
                continue;
            }
            if ($type === 'message_delta') {
                $finishReason = $chunk['delta']['stop_reason'] ?? $finishReason;
                $outputTokens = $chunk['usage']['output_tokens'] ?? $outputTokens;
                continue;
            }
            if ($type === 'message_start' && isset($chunk['message']['usage'])) {
                $inputTokens = $chunk['message']['usage']['input_tokens'] ?? $inputTokens;
                continue;
            }
            if ($type === 'error') {
                throw new LlmException(
                    'Anthropic stream error: ' . ($chunk['error']['message'] ?? 'unknown'),
                    self::PROVIDER_NAME,
                    null,
                    json_encode($chunk['error']) ?: null,
                );
            }
        }

        return new LlmResponse(
            content: $fullText,
            citations: [],
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            finishReason: $finishReason,
            model: $this->model,
            provider: self::PROVIDER_NAME,
        );
    }

    public function getProviderName(): string
    {
        return self::PROVIDER_NAME;
    }

    public function getModelName(): string
    {
        return $this->model;
    }

    /**
     * @param LlmMessage[] $messages
     */
    private function buildBody(array $messages, array $options, bool $stream): array
    {
        $systemParts = [];
        $msgList = [];

        if (isset($options['system']) && $options['system'] !== '') {
            $systemParts[] = (string) $options['system'];
        }

        foreach ($messages as $i => $msg) {
            if (!$msg instanceof LlmMessage) {
                throw new \InvalidArgumentException(
                    "messages[{$i}] must be an instance of LlmMessage"
                );
            }
            if ($msg->role === LlmMessage::ROLE_SYSTEM) {
                $systemParts[] = $msg->content;
                continue;
            }
            $msgList[] = [
                'role' => $msg->role, // user / assistant のまま
                'content' => $msg->content,
            ];
        }

        if ($msgList === []) {
            throw new \InvalidArgumentException(
                'At least one non-system message is required.'
            );
        }

        $body = [
            'model' => $this->model,
            'messages' => $msgList,
            'max_tokens' => (int) ($options['max_tokens'] ?? $this->defaultMaxTokens),
        ];

        if ($systemParts !== []) {
            $body['system'] = implode("\n\n", $systemParts);
        }
        if (isset($options['temperature'])) {
            $body['temperature'] = (float) $options['temperature'];
        }
        if (isset($options['stop']) && is_array($options['stop']) && $options['stop'] !== []) {
            $body['stop_sequences'] = array_values($options['stop']);
        }
        if ($stream) {
            $body['stream'] = true;
        }
        return $body;
    }

    private function buildHeaders(): array
    {
        return [
            'x-api-key' => $this->apiKey,
            'anthropic-version' => self::API_VERSION,
            'Content-Type' => 'application/json',
        ];
    }

    private function parseFinalResponse(array $data): LlmResponse
    {
        if (($data['type'] ?? null) === 'error') {
            throw new LlmException(
                'Anthropic API error: ' . ($data['error']['message'] ?? 'unknown'),
                self::PROVIDER_NAME,
                null,
                json_encode($data['error']) ?: null,
            );
        }

        $text = '';
        foreach ($data['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= $block['text'] ?? '';
            }
        }
        return new LlmResponse(
            content: $text,
            citations: [],
            inputTokens: $data['usage']['input_tokens'] ?? null,
            outputTokens: $data['usage']['output_tokens'] ?? null,
            finishReason: $data['stop_reason'] ?? null,
            model: $this->model,
            provider: self::PROVIDER_NAME,
            raw: $data,
        );
    }

    /**
     * @return \Generator<int, string>
     */
    private function readSseLines(StreamInterface $stream): \Generator
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
        error_log('[anthropic] ' . $op . ' raw: ' . LlmException::sanitize($e->getMessage()));
        return new LlmException(
            sprintf('Anthropic %s failed: %s', $op, LlmException::sanitize($e->getMessage())),
            self::PROVIDER_NAME,
            $resp?->getStatusCode(),
            $bodyExcerpt !== null ? LlmException::sanitize($bodyExcerpt) : null,
            $e,
        );
    }
}
