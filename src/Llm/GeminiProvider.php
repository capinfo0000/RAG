<?php
declare(strict_types=1);

namespace App\Llm;

use App\Config;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;

/**
 * Google Gemini Provider
 *
 * Endpoint: https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent
 * Streaming: :streamGenerateContent?alt=sse
 *
 * Role mapping:
 *   LlmMessage::ROLE_USER      -> 'user'
 *   LlmMessage::ROLE_ASSISTANT -> 'model'
 *   LlmMessage::ROLE_SYSTEM    -> systemInstruction（contentsとは別フィールド）
 *
 * Citation: API には公式な引用構造が無いので Generator 側で [N] パース。
 */
final class GeminiProvider implements LlmProviderInterface
{
    private const BASE_URL = 'https://generativelanguage.googleapis.com/v1beta';
    private const PROVIDER_NAME = 'gemini';

    private readonly Client $http;
    private readonly GeminiKeyPool $keyPool;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model = 'gemini-flash-latest',
        ?Client $http = null,
    ) {
        // 複数キー（GEMINI_API_KEY / _2 / _3 … or GEMINI_API_KEYS）をプール化。
        // 429 のたびに次のキーへローテーションする。単一キーでも従来どおり動く。
        $this->keyPool = GeminiKeyPool::fromConfig($apiKey);
        if ($this->keyPool->isEmpty()) {
            throw new LlmException('Gemini API key is empty', self::PROVIDER_NAME);
        }
        // 重要: stream: true 時に Guzzle がデフォルトで StreamHandler に切り替えるが、
        // Windows 環境では SSL ハンドシェイクが「Connection refused」になるケースがある。
        // CurlHandler を明示指定して cURL のみで動作させる（ストリーミングは疑似的に
        // チャンクとして受信→分割yieldで継続性は保たれる）。
        $this->http = $http ?? new Client([
            'timeout' => 120,
            'connect_timeout' => 10,
            'handler' => HandlerStack::create(new CurlHandler()),
        ]);
    }

    public static function fromConfig(?string $apiKey = null, ?string $model = null): self
    {
        return new self(
            apiKey: $apiKey ?? (string) Config::get('GEMINI_API_KEY', ''),
            model: $model ?? (string) Config::get('GEMINI_MODEL', 'gemini-flash-latest'),
        );
    }

    public function generate(array $messages, array $options = []): LlmResponse
    {
        $body = $this->buildBody($messages, $options);
        // APIキーはURL(?key=)ではなく x-goog-api-key ヘッダで送る。
        // URLに載せないことで、例外メッセージ・プロキシ/アクセスログ経由の漏洩を構造的に防ぐ。
        $url = sprintf(
            '%s/models/%s:generateContent',
            self::BASE_URL,
            rawurlencode($this->model),
        );

        $res = $this->requestWithKeyFailover(
            $url,
            ['json' => $body, 'headers' => ['Content-Type' => 'application/json']],
            'generateContent',
        );

        try {
            $data = json_decode((string) $res->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new LlmException(
                'Gemini response is not valid JSON: ' . $e->getMessage(),
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
        $body = $this->buildBody($messages, $options);
        // APIキーはURL(?key=)ではなく x-goog-api-key ヘッダで送る（漏洩経路の根絶）。
        $url = sprintf(
            '%s/models/%s:streamGenerateContent?alt=sse',
            self::BASE_URL,
            rawurlencode($this->model),
        );

        $res = $this->requestWithKeyFailover(
            $url,
            [
                'json' => $body,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'text/event-stream',
                ],
                'stream' => true,
            ],
            'streamGenerateContent',
        );

        $stream = $res->getBody();
        $buffer = '';
        $fullText = '';
        /** @var array|null $lastChunk */
        $lastChunk = null;
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
            $lastChunk = $chunk;

            // ストリーミング中もエラーチェック
            if (isset($chunk['error'])) {
                $msg = $chunk['error']['message'] ?? 'Unknown Gemini stream error';
                throw new LlmException(
                    'Gemini stream error: ' . $msg,
                    self::PROVIDER_NAME,
                    (int) ($chunk['error']['code'] ?? 0),
                    json_encode($chunk['error']) ?: null,
                );
            }

            $parts = $chunk['candidates'][0]['content']['parts'] ?? [];
            foreach ($parts as $p) {
                if (isset($p['text']) && $p['text'] !== '') {
                    $fullText .= $p['text'];
                    yield $p['text'];
                }
            }

            if (isset($chunk['candidates'][0]['finishReason'])) {
                $finishReason = $chunk['candidates'][0]['finishReason'];
            }
            if (isset($chunk['usageMetadata'])) {
                $inputTokens = $chunk['usageMetadata']['promptTokenCount'] ?? $inputTokens;
                $outputTokens = $chunk['usageMetadata']['candidatesTokenCount'] ?? $outputTokens;
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
            raw: $lastChunk ?? [],
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
    private function buildBody(array $messages, array $options): array
    {
        $systemParts = [];
        $contents = [];

        if (isset($options['system']) && $options['system'] !== '') {
            $systemParts[] = ['text' => (string) $options['system']];
        }

        foreach ($messages as $i => $msg) {
            if (!$msg instanceof LlmMessage) {
                throw new \InvalidArgumentException(
                    "messages[{$i}] must be an instance of LlmMessage"
                );
            }
            if ($msg->role === LlmMessage::ROLE_SYSTEM) {
                $systemParts[] = ['text' => $msg->content];
                continue;
            }
            $geminiRole = match ($msg->role) {
                LlmMessage::ROLE_USER => 'user',
                LlmMessage::ROLE_ASSISTANT => 'model',
                default => throw new \LogicException("Unreachable role: {$msg->role}"),
            };
            $contents[] = [
                'role' => $geminiRole,
                'parts' => [['text' => $msg->content]],
            ];
        }

        if ($contents === []) {
            throw new \InvalidArgumentException(
                'At least one non-system message (user/assistant) is required.'
            );
        }

        $body = ['contents' => $contents];

        if ($systemParts !== []) {
            $body['systemInstruction'] = ['parts' => $systemParts];
        }

        $gen = [];
        if (isset($options['temperature'])) {
            $gen['temperature'] = (float) $options['temperature'];
        }
        if (isset($options['max_tokens'])) {
            $gen['maxOutputTokens'] = (int) $options['max_tokens'];
        }
        if (isset($options['stop']) && is_array($options['stop']) && $options['stop'] !== []) {
            $gen['stopSequences'] = array_values($options['stop']);
        }
        if (!empty($options['response_json'])) {
            $gen['responseMimeType'] = 'application/json';
        }
        // 思考型モデル(gemini-flash-latest等)の思考トークン上限。
        // 未指定だと思考が maxOutputTokens を食い潰し本文が途中で切れる(MAX_TOKENS)ことがある。
        if (isset($options['thinking_budget'])) {
            $gen['thinkingConfig'] = ['thinkingBudget' => (int) $options['thinking_budget']];
        }
        if ($gen !== []) {
            $body['generationConfig'] = $gen;
        }

        // 安全フィルタの調整（業務系チャットでは過度なブロックを緩和）
        // 必要なら options['safety_off'] で完全Offにできるが、デモではデフォルトON。
        if (!empty($options['safety_off'])) {
            $body['safetySettings'] = array_map(
                fn(string $cat) => ['category' => $cat, 'threshold' => 'BLOCK_NONE'],
                [
                    'HARM_CATEGORY_HARASSMENT',
                    'HARM_CATEGORY_HATE_SPEECH',
                    'HARM_CATEGORY_SEXUALLY_EXPLICIT',
                    'HARM_CATEGORY_DANGEROUS_CONTENT',
                ]
            );
        }

        return $body;
    }

    private function parseFinalResponse(array $data): LlmResponse
    {
        if (isset($data['error'])) {
            throw new LlmException(
                'Gemini API returned error: ' . ($data['error']['message'] ?? 'unknown'),
                self::PROVIDER_NAME,
                (int) ($data['error']['code'] ?? 0),
                json_encode($data['error']) ?: null,
            );
        }

        $text = '';
        $parts = $data['candidates'][0]['content']['parts'] ?? [];
        foreach ($parts as $p) {
            if (isset($p['text'])) {
                $text .= $p['text'];
            }
        }

        return new LlmResponse(
            content: $text,
            citations: [],
            inputTokens: $data['usageMetadata']['promptTokenCount'] ?? null,
            outputTokens: $data['usageMetadata']['candidatesTokenCount'] ?? null,
            finishReason: $data['candidates'][0]['finishReason'] ?? null,
            model: $this->model,
            provider: self::PROVIDER_NAME,
            raw: $data,
        );
    }

    /**
     * SSEストリームから行単位で読む。改行コードは LF / CRLF どちらも許容。
     *
     * @return \Generator<int, string>
     */
    private function readSseLines(StreamInterface $stream): \Generator
    {
        $buffer = '';
        while (!$stream->eof()) {
            $chunk = $stream->read(8192);
            if ($chunk === '') {
                // 接続が切れた可能性、ループを抜けて残バッファを最後にflush
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

    /**
     * キープールから「使えるキー」を順に試し、429 が来たらそのキーをクールダウンに入れて
     * 次のキーへ回す。成功した Response を返す。全キー枯渇時は 429 の LlmException を投げる。
     *
     * @param array<string, mixed> $opts Guzzle オプション（headers に x-goog-api-key を注入する）
     */
    private function requestWithKeyFailover(string $url, array $opts, string $op): \Psr\Http\Message\ResponseInterface
    {
        // 失敗の性質で対処を分ける:
        //  - キー固有(429/401/403/無効キー400) → そのキーを休ませて【次のキーへ】(failover)
        //  - サーバ側の一時障害(5xx=モデル過負荷 / 接続不可) → キー切替では直らないので
        //    【軽く1回だけ再試行】する。応答速度を優先し、待ちが伸びる多段リトライは行わない
        //    （再試行しても直らなければ即エラーを返す。恒常的な過負荷は上位で別モデル/有料枠へ切替）。
        $maxAttempts = 2; // = 初回 + 軽い1回リトライ
        $lastRotatable = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $sawTransientServer = false;
            // available() は前回成功キーの次から 1→2→3→1… とラウンドロビン（クールダウン中は飛ばす）。
            foreach ($this->keyPool->available() as $idx => $key) {
                $opts['headers']['x-goog-api-key'] = $key;
                try {
                    $res = $this->http->request('POST', $url, $opts);
                    $this->keyPool->advanceAfter($idx); // 成功 → 次回は次のキーから
                    return $res;
                } catch (RequestException $e) {
                    $status = $e->getResponse()?->getStatusCode();
                    $body = null;
                    try {
                        $body = (string) $e->getResponse()?->getBody();
                    } catch (\Throwable) {
                        $body = null;
                    }
                    if (!GeminiKeyPool::shouldRotate($status, $body)) {
                        // 400(不正)/404(モデル無し)等はどのキーでも同じ → 即失敗（真因を隠さない）
                        throw $this->wrapException($e, $op);
                    }
                    $lastRotatable = $e;

                    // 5xx / 接続不可 = サーバ側一時障害（モデル過負荷等）。キーの問題ではないので
                    // クールダウンさせず、他キーも試さず中断 → バックオフして全体を再試行する。
                    if ($status === null || ($status >= 500 && $status <= 599)) {
                        $sawTransientServer = true;
                        break;
                    }

                    // ここはキー固有の失敗（429/401/403/無効キー400）→ そのキーを休ませて次のキーへ
                    $cool = ($status === 429)
                        ? GeminiKeyPool::cooldownFor($body)
                        : GeminiKeyPool::TRANSIENT_COOLDOWN;
                    $this->keyPool->markCooldown($key, $cool);
                    continue; // 次のキーへ
                }
            }

            // 5xx等の一時障害だったら、軽く1回だけ待って再試行（キー切替では直らないため）。
            if ($sawTransientServer && $attempt < $maxAttempts) {
                usleep(600000); // 0.6s（体感を損なわない範囲の1回だけ）
                continue;
            }
            break; // キー固有で全キー枯渇 or リトライ上限
        }

        if ($lastRotatable !== null) {
            throw $this->wrapException($lastRotatable, $op); // 直近の失敗を伝播
        }
        throw new LlmException(
            'All Gemini API keys are exhausted (rate-limited or failing). Tried '
                . $this->keyPool->total() . ' key(s).',
            self::PROVIDER_NAME,
            429,
            'quota exceeded: all keys cooling down',
        );
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
        // 詳細は error_log に、ユーザー向けはサニタイズ済み
        error_log('[gemini] ' . $op . ' raw: ' . LlmException::sanitize($e->getMessage()));
        return new LlmException(
            sprintf('Gemini %s failed: %s', $op, LlmException::sanitize($e->getMessage())),
            self::PROVIDER_NAME,
            $resp?->getStatusCode(),
            $bodyExcerpt !== null ? LlmException::sanitize($bodyExcerpt) : null,
            $e,
        );
    }
}
