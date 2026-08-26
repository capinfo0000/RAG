<?php
declare(strict_types=1);

namespace App\Llm;

/**
 * 回答中に現れた引用1件分のメタ情報。
 *
 * Anthropic Citations API なら API が返す位置情報を、それ以外（Gemini/OpenAI/Ollama）は
 * 「[N]」記法をプロンプトで強制し、生成後に parse して詰める。
 */
final class Citation
{
    public function __construct(
        /** [1][2] の番号（1始まり）。プロバイダー横断の安定キー。 */
        public readonly int $index,
        public readonly ?int $chunkId = null,
        public readonly ?int $documentId = null,
        public readonly ?string $documentTitle = null,
        /** 引用元テキスト（抜粋） */
        public readonly ?string $citedText = null,
        /** 元文書中の文字オフセット（取れる場合のみ） */
        public readonly ?int $startChar = null,
        public readonly ?int $endChar = null,
    ) {
    }

    public function toArray(): array
    {
        return [
            'index' => $this->index,
            'chunk_id' => $this->chunkId,
            'document_id' => $this->documentId,
            'document_title' => $this->documentTitle,
            'cited_text' => $this->citedText,
            'start_char' => $this->startChar,
            'end_char' => $this->endChar,
        ];
    }
}
