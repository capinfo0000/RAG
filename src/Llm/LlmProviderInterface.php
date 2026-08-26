<?php
declare(strict_types=1);

namespace App\Llm;

/**
 * LLMプロバイダーの統一インターフェース。
 *
 * 実装: GeminiProvider / AnthropicProvider / OpenAiProvider / OllamaProvider
 *
 * options で受け付ける共通キー:
 *   - temperature: float (0.0-2.0)
 *   - max_tokens: int
 *   - stop: string[] 停止シーケンス
 *   - system: string システムプロンプト（messages内のsystemロールでも可）
 *   - response_json: bool JSONレスポンス強制（対応プロバイダーのみ）
 *
 * プロバイダー固有のオプションは各実装のdocコメント参照。
 */
interface LlmProviderInterface
{
    /**
     * 一括生成（非ストリーミング）。
     *
     * @param LlmMessage[] $messages
     */
    public function generate(array $messages, array $options = []): LlmResponse;

    /**
     * ストリーミング生成。各yieldはテキストchunk（差分）。
     * 終端で完成版のLlmResponseを Generator::getReturn() で取得可能。
     *
     * @param LlmMessage[] $messages
     * @return \Generator<int, string, mixed, LlmResponse>
     */
    public function stream(array $messages, array $options = []): \Generator;

    public function getProviderName(): string;

    public function getModelName(): string;
}
