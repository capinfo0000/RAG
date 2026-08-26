<?php
declare(strict_types=1);

namespace App\Knowledge;

/**
 * .txt / .md / .csv など、テキストファイルから本文を読み込む。
 *
 * 文字コード推定（mb_detect_encoding）して utf-8 に変換。
 */
final class TextExtractor implements ExtractorInterface
{
    public function extract(string $filePath): string
    {
        if (!is_file($filePath)) {
            throw new \RuntimeException("File not found: {$filePath}");
        }
        $raw = file_get_contents($filePath);
        if ($raw === false) {
            throw new \RuntimeException("Cannot read file: {$filePath}");
        }

        // BOM 除去
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }

        // エンコーディング推定
        $enc = mb_detect_encoding($raw, ['UTF-8', 'SJIS-win', 'CP932', 'EUC-JP', 'ISO-2022-JP'], true);
        if ($enc !== false && $enc !== 'UTF-8') {
            $raw = mb_convert_encoding($raw, 'UTF-8', $enc);
        }

        // CRLF -> LF
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        return $raw;
    }

    public function supportedExtensions(): array
    {
        return ['txt', 'md', 'markdown', 'csv', 'log'];
    }
}
