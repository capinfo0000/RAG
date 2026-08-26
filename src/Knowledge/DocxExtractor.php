<?php
declare(strict_types=1);

namespace App\Knowledge;

use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\Element\TextBreak;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\Element\ListItem;
use PhpOffice\PhpWord\Element\Title;

/**
 * Word(.docx) 本文抽出（phpoffice/phpword）。
 */
final class DocxExtractor implements ExtractorInterface
{
    public function extract(string $filePath): string
    {
        if (!is_file($filePath)) {
            throw new \RuntimeException("File not found: {$filePath}");
        }
        $phpWord = IOFactory::load($filePath);

        $out = [];
        foreach ($phpWord->getSections() as $section) {
            $out[] = $this->walkElements($section->getElements());
        }
        $text = implode("\n\n", array_filter($out, fn($s) => trim($s) !== ''));
        return $this->normalize($text);
    }

    public function supportedExtensions(): array
    {
        return ['docx'];
    }

    /** @param object[] $elements */
    private function walkElements(array $elements): string
    {
        $lines = [];
        foreach ($elements as $el) {
            if ($el instanceof Title) {
                $lines[] = str_repeat('#', max(1, (int) $el->getDepth() ?: 1)) . ' ' . $el->getText();
                continue;
            }
            if ($el instanceof Text) {
                $lines[] = $el->getText();
                continue;
            }
            if ($el instanceof TextRun) {
                $buf = '';
                foreach ($el->getElements() as $child) {
                    if (method_exists($child, 'getText')) {
                        $t = $child->getText();
                        $buf .= is_string($t) ? $t : '';
                    }
                }
                $lines[] = $buf;
                continue;
            }
            if ($el instanceof TextBreak) {
                $lines[] = '';
                continue;
            }
            if ($el instanceof ListItem) {
                $lines[] = '- ' . $el->getTextObject()->getText();
                continue;
            }
            if ($el instanceof Table) {
                foreach ($el->getRows() as $row) {
                    $cells = [];
                    foreach ($row->getCells() as $cell) {
                        $cells[] = $this->walkElements($cell->getElements());
                    }
                    $lines[] = '| ' . implode(' | ', $cells) . ' |';
                }
                continue;
            }
            if (method_exists($el, 'getElements')) {
                $lines[] = $this->walkElements($el->getElements());
            }
        }
        return implode("\n", $lines);
    }

    private function normalize(string $text): string
    {
        $text = preg_replace('/\n{3,}/u', "\n\n", $text);
        return trim($text);
    }
}
