<?php
declare(strict_types=1);

namespace App\Knowledge;

/**
 * 各ファイル形式の本文抽出 Extractor。
 *
 * 戻り値はプレーンテキスト（複数ページ/シートはセクション区切りで連結）。
 */
interface ExtractorInterface
{
    /**
     * @return string プレーンテキスト本文
     */
    public function extract(string $filePath): string;

    /** @return string[] 例: ['pdf'], ['docx'] */
    public function supportedExtensions(): array;
}
