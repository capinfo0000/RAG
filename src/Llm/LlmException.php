<?php
declare(strict_types=1);

namespace App\Llm;

class LlmException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $provider = '',
        public readonly ?int $httpStatus = null,
        public readonly ?string $responseBody = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function toLogArray(): array
    {
        return [
            'provider' => $this->provider,
            'http_status' => $this->httpStatus,
            'message' => $this->getMessage(),
            'body_excerpt' => $this->responseBody !== null
                ? mb_substr($this->responseBody, 0, 500)
                : null,
        ];
    }

    /**
     * 例外メッセージから機密情報（APIキー / Bearer / x-api-key）をマスク。
     * Guzzle の RequestException::getMessage() は失敗時にURL（?key=xxx 含む）を含むため、
     * SSE 等でクライアントに送る前に必ず通すこと。
     */
    public static function sanitize(string $msg): string
    {
        // ?key= / ?api_key= / ?access_token= 等のクエリ
        $msg = preg_replace('/([?&](?:key|api[_-]?key|access[_-]?token|token)=)[^&\s"\']+/i', '$1***REDACTED***', $msg) ?? $msg;
        // Authorization: Bearer xxx / x-api-key: xxx / x-goog-api-key: xxx
        $msg = preg_replace('/(Authorization:\s*Bearer\s+)\S+/i', '$1***REDACTED***', $msg) ?? $msg;
        $msg = preg_replace('/(x-(?:goog-)?api-key:\s*)\S+/i', '$1***REDACTED***', $msg) ?? $msg;
        // 既知のキー形式（Google/OpenAI/Anthropic/Groq/Voyage）
        $msg = preg_replace('/AIza[0-9A-Za-z_\-]{20,}/', '***REDACTED***', $msg) ?? $msg;
        // Google の新形式 API キー（例: "AQ." で始まる）
        $msg = preg_replace('/\bAQ\.[A-Za-z0-9_\-]{10,}/', '***REDACTED***', $msg) ?? $msg;
        $msg = preg_replace('/sk-(?:ant-)?[A-Za-z0-9_\-]{20,}/', '***REDACTED***', $msg) ?? $msg;
        $msg = preg_replace('/gsk_[A-Za-z0-9_\-]{20,}/', '***REDACTED***', $msg) ?? $msg;
        $msg = preg_replace('/pa-[A-Za-z0-9_\-]{20,}/', '***REDACTED***', $msg) ?? $msg;
        // Voyage（pa- は上でカバー）/ 保険: key/token を含む長いランダム文字列の残骸を圧縮しない（誤爆回避）
        return $msg;
    }

    /**
     * ユーザー向けの汎用エラーメッセージ。HTTPステータスから推測。
     */
    public function publicMessage(): string
    {
        return match (true) {
            $this->httpStatus === 429 => $this->rateLimitMessage(),
            $this->httpStatus === 401 || $this->httpStatus === 403 => 'LLMの認証に失敗しました。管理画面でAPIキーをご確認ください。',
            $this->httpStatus === 400 => 'LLMへのリクエストに問題がありました。',
            $this->httpStatus !== null && $this->httpStatus >= 500 => 'LLMサービス側で一時的なエラーが発生しています。',
            default => 'システムエラーが発生しました。時間をおいて再度お試しください。',
        };
    }

    /**
     * 利用上限（HTTP 429）到達時のユーザー向けメッセージ。
     *
     * 「運用側が設けた利用制限」に見えるよう、外部APIや無料枠には一切触れない。
     * 日次上限（1日あたりの回数上限）と、短時間の集中アクセス（分あたり等）を
     * レスポンス本文から判別して出し分ける。
     */
    private function rateLimitMessage(): string
    {
        $body = (string) ($this->responseBody ?? '');
        // 日次上限（例: quotaId に "PerDay" を含む）→ 本日は終了
        if (preg_match('/per\s*[-_]?\s*day/i', $body) === 1) {
            return '多くの方にご利用いただくため、1日あたりのご利用回数に上限を設けております。'
                . '本日分の上限に達しましたので、恐れ入りますが翌日以降に改めてお試しください。';
        }
        // 短時間のアクセス集中（分あたり上限など）→ 少し待って再試行
        return 'ただいま混み合っております。少し時間をおいてから再度お試しください。';
    }
}
