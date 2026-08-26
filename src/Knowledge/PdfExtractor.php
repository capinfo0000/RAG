<?php
declare(strict_types=1);

namespace App\Knowledge;

use Smalot\PdfParser\Parser;

/**
 * PDF 本文抽出（smalot/pdfparser）。
 *
 * 画像PDF（スキャン）には対応しない。本格運用なら Tesseract OCR を併用する。
 */
final class PdfExtractor implements ExtractorInterface
{
    public function extract(string $filePath): string
    {
        if (!is_file($filePath)) {
            throw new \RuntimeException("File not found: {$filePath}");
        }
        $parser = new Parser();
        $pdf = $parser->parseFile($filePath);

        $sections = [];
        $pageNum = 1;
        foreach ($pdf->getPages() as $page) {
            $text = $page->getText();
            // PDFはレイアウト由来の改行が多いので軽く整形
            $text = preg_replace('/[ \t]+\n/u', "\n", $text);
            $text = preg_replace('/\n{3,}/u', "\n\n", $text);
            $sections[] = "[Page {$pageNum}]\n" . trim($text);
            $pageNum++;
        }
        return implode("\n\n", $sections);
    }

    public function supportedExtensions(): array
    {
        return ['pdf'];
    }
}
