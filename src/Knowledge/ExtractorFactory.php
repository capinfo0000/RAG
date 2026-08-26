<?php
declare(strict_types=1);

namespace App\Knowledge;

/**
 * 拡張子から適切な Extractor を選択するファクトリ。
 */
final class ExtractorFactory
{
    public static function forFile(string $filePath): ExtractorInterface
    {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        return self::forExtension($ext);
    }

    public static function forExtension(string $extension): ExtractorInterface
    {
        $ext = strtolower(ltrim($extension, '.'));
        // PDF/画像は取り込み精度・安定性が低いため受け付けない（テキスト系のみ対応）。
        return match ($ext) {
            'docx' => new DocxExtractor(),
            'xlsx', 'xls' => new XlsxExtractor(),
            'csv', 'txt', 'md', 'markdown', 'log' => new TextExtractor(),
            default => throw new \InvalidArgumentException("Unsupported file extension: {$ext}"),
        };
    }

    /** @return string[] */
    public static function supportedExtensions(): array
    {
        // 取り込み精度・安定性が最も高いテキスト系のみを受け付ける。
        // （DOCX/XLSX/PDF/画像は精度・安定性の観点から受け付けない）
        return ['txt', 'md', 'markdown', 'csv', 'log'];
    }
}
