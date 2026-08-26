<?php
declare(strict_types=1);

namespace App\Knowledge;

use App\Config;
use App\Llm\LlmFactory;
use App\Llm\LlmMessage;
use App\Llm\LlmProviderInterface;

/**
 * テキストをチャンクに分割する。
 *
 * 基本: 段落・文境界を尊重した sliding window。
 * Contextual: 各チャンクに「この文書全体の文脈サマリ」を付加する（Anthropic推奨）。
 *   → context_text 列に格納し、FULLTEXT検索の対象に含める。
 *
 * 1チャンクの目安: 約500文字（日本語）。RAG_CHUNK_SIZE / RAG_CHUNK_OVERLAP で可変。
 */
final class Chunker
{
    public function __construct(
        private readonly int $chunkSize = 500,
        private readonly int $chunkOverlap = 100,
        private readonly ?LlmProviderInterface $contextLlm = null,
    ) {
    }

    public static function fromConfig(?LlmProviderInterface $llm = null, ?bool $withContext = null): self
    {
        // contextual chunking（各チャンクにLLM要約を付与）は既定OFF。
        // 取込のたびにLLMを呼ぶため遅く、思考モデルだと要約が空になりがち。
        // 精度を追い込む場合のみ RAG_CONTEXTUAL_CHUNKING=1 で有効化する。
        if ($withContext === null) {
            $withContext = (bool) Config::get('RAG_CONTEXTUAL_CHUNKING', false);
        }
        return new self(
            chunkSize: (int) Config::get('RAG_CHUNK_SIZE', 500),
            chunkOverlap: (int) Config::get('RAG_CHUNK_OVERLAP', 100),
            contextLlm: $withContext ? ($llm ?? LlmFactory::create()) : null,
        );
    }

    /**
     * @return array<int, array{chunk_index:int, text:string, context_text:?string, token_count:int}>
     */
    public function chunk(string $fullText, ?string $documentTitle = null): array
    {
        $fullText = $this->normalize($fullText);
        if ($fullText === '') {
            return [];
        }

        $pieces = $this->splitByParagraph($fullText);
        $chunks = $this->slidingWindow($pieces);

        // Contextual chunking: 文書全体のサマリを LLM で生成し、各チャンクに付与
        $documentContext = null;
        if ($this->contextLlm !== null && mb_strlen($fullText) >= 800) {
            try {
                $documentContext = $this->summarizeDocument($fullText, $documentTitle);
            } catch (\Throwable) {
                $documentContext = null;
            }
        }

        $out = [];
        foreach ($chunks as $i => $body) {
            $contextText = $documentContext !== null
                ? "【文書全体の要約】\n{$documentContext}\n\n【このチャンクの位置】" . ($i + 1) . '/' . count($chunks)
                : null;
            $out[] = [
                'chunk_index' => $i,
                'text' => $body,
                'context_text' => $contextText,
                'token_count' => $this->estimateTokens($body),
            ];
        }
        return $out;
    }

    private function normalize(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/\n{3,}/u', "\n\n", $text);
        $text = preg_replace('/[ \t]+\n/u', "\n", $text);
        return trim($text);
    }

    /** @return string[] */
    private function splitByParagraph(string $text): array
    {
        $paras = preg_split('/\n{2,}/u', $text) ?: [];
        $out = [];
        foreach ($paras as $p) {
            $p = trim($p);
            if ($p === '') {
                continue;
            }
            // 段落自体が大きすぎる場合は文単位で分割
            if (mb_strlen($p) > $this->chunkSize * 2) {
                $sentences = preg_split('/(?<=[。！？\!\?\.])\s*/u', $p) ?: [$p];
                foreach ($sentences as $s) {
                    if (trim($s) !== '') {
                        $out[] = trim($s);
                    }
                }
            } else {
                $out[] = $p;
            }
        }
        return $out;
    }

    /**
     * 段落・文の配列を chunkSize 程度ごとに集約し、隣接チャンクに overlap を持たせる。
     *
     * @param string[] $pieces
     * @return string[]
     */
    private function slidingWindow(array $pieces): array
    {
        if ($pieces === []) {
            return [];
        }

        $chunks = [];
        $current = '';
        foreach ($pieces as $piece) {
            $separator = $current === '' ? '' : "\n\n";
            $candidate = $current . $separator . $piece;
            if (mb_strlen($candidate) <= $this->chunkSize || $current === '') {
                $current = $candidate;
                continue;
            }
            $chunks[] = $current;
            // overlap を引き継ぐ
            $tail = mb_substr($current, max(0, mb_strlen($current) - $this->chunkOverlap));
            $current = trim($tail) . "\n\n" . $piece;
        }
        if (trim($current) !== '') {
            $chunks[] = $current;
        }
        return $chunks;
    }

    private function summarizeDocument(string $fullText, ?string $title): string
    {
        // 長文は先頭・末尾を残して中央を間引いて LLM 負荷を抑える
        $maxChars = 6000;
        if (mb_strlen($fullText) > $maxChars) {
            $half = (int) ($maxChars / 2);
            $head = mb_substr($fullText, 0, $half);
            $tail = mb_substr($fullText, -$half);
            $fullText = $head . "\n\n（中略）\n\n" . $tail;
        }

        $system = 'あなたは文書要約アシスタントです。与えられた文書全体を200文字以内で要約してください。要約のみを出力し、前置きやコードブロックは含めないでください。';
        $titleLine = $title ? "【文書タイトル】{$title}\n\n" : '';
        $resp = $this->contextLlm->generate(
            messages: [LlmMessage::user($titleLine . "【文書本文】\n" . $fullText)],
            options: ['system' => $system, 'temperature' => 0.0, 'max_tokens' => 300],
        );
        return trim($resp->content);
    }

    private function estimateTokens(string $text): int
    {
        // 日本語ざっくり: 1文字 ≒ 1 token として保守的に
        return mb_strlen($text);
    }
}
