<?php
declare(strict_types=1);

namespace App\Knowledge;

use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Excel(.xlsx) 本文抽出（phpoffice/phpspreadsheet）。
 *
 * 各シートの各行を tab 区切りで連結し、シート間は ---- 区切り。
 */
final class XlsxExtractor implements ExtractorInterface
{
    public function extract(string $filePath): string
    {
        if (!is_file($filePath)) {
            throw new \RuntimeException("File not found: {$filePath}");
        }
        $reader = IOFactory::createReaderForFile($filePath);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($filePath);

        $sections = [];
        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $title = $sheet->getTitle();
            $rows = $sheet->toArray(null, true, true, false);
            if ($rows === []) {
                continue;
            }
            $lines = ["[Sheet: {$title}]"];
            foreach ($rows as $row) {
                // 空行スキップ
                $filtered = array_filter($row, fn($c) => $c !== null && $c !== '');
                if ($filtered === []) {
                    continue;
                }
                $lines[] = implode("\t", array_map(fn($c) => (string) $c, $row));
            }
            $sections[] = implode("\n", $lines);
        }
        return implode("\n\n----\n\n", $sections);
    }

    public function supportedExtensions(): array
    {
        return ['xlsx', 'xls', 'csv'];
    }
}
