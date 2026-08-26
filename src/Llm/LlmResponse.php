<?php
declare(strict_types=1);

namespace App\Llm;

final class LlmResponse
{
    /**
     * @param Citation[] $citations
     * @param array $raw プロバイダーの生レスポンス（デバッグ用、ログには出さない）
     */
    public function __construct(
        public readonly string $content,
        public readonly array $citations = [],
        public readonly ?int $inputTokens = null,
        public readonly ?int $outputTokens = null,
        public readonly ?string $finishReason = null,
        public readonly ?string $model = null,
        public readonly string $provider = '',
        public readonly array $raw = [],
    ) {
    }

    public function totalTokens(): int
    {
        return ($this->inputTokens ?? 0) + ($this->outputTokens ?? 0);
    }

    public function toArray(bool $includeRaw = false): array
    {
        $arr = [
            'content' => $this->content,
            'citations' => array_map(fn(Citation $c) => $c->toArray(), $this->citations),
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'finish_reason' => $this->finishReason,
            'model' => $this->model,
            'provider' => $this->provider,
        ];
        if ($includeRaw) {
            $arr['raw'] = $this->raw;
        }
        return $arr;
    }
}
