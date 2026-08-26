<?php
declare(strict_types=1);

namespace App\Knowledge;

use App\Config;
use App\Models\Document;

/**
 * Obsidian vault（Markdown ファイル群）をナレッジ源として取り込む。
 *
 * - 指定フォルダを再帰走査して *.md を列挙（.obsidian / .trash / 隠しフォルダは除外）
 * - YAML フロントマターから title / category / tags を解釈（無ければファイル名・親フォルダで補完）
 * - documents.source_ref（vault からの相対パス）と source_hash（内容のsha1）で
 *   追加 / 更新 / 削除 を検知する増分同期
 *
 * 取込処理そのものは既存の Indexer / Chunker / TextExtractor を再利用する。
 * ※ Obsidian アプリのインストールは不要。回答源の実体は .md ファイル群でよい。
 */
final class ObsidianVaultSource implements KnowledgeSourceInterface
{
    private const SOURCE_TYPE = 'obsidian';
    private const SKIP_DIRS = ['.obsidian', '.trash', '.git', 'node_modules'];
    /** 回答ナレッジに含めない .md（案内・説明用）。ファイル名の小文字で比較。 */
    private const SKIP_FILES = ['readme.md'];

    public function __construct(
        private readonly string $vaultPath,
        private readonly Indexer $indexer,
    ) {
    }

    public static function fromConfig(?string $vaultPath = null): self
    {
        $path = $vaultPath ?? (string) Config::get('OBSIDIAN_VAULT_PATH', '');
        return new self($path, Indexer::fromConfig());
    }

    public function type(): string
    {
        return self::SOURCE_TYPE;
    }

    public function sync(): array
    {
        if ($this->vaultPath === '') {
            throw new \RuntimeException('OBSIDIAN_VAULT_PATH が未設定です（.env で設定するか sync 時に指定してください）。');
        }
        $root = realpath($this->vaultPath);
        if ($root === false || !is_dir($root)) {
            throw new \RuntimeException("Obsidian vault が見つかりません: {$this->vaultPath}");
        }

        $files = $this->scan($root);                       // relPath => absPath
        $existing = Document::listBySourceType(self::SOURCE_TYPE); // relPath => row

        $added = $updated = $deleted = $unchanged = 0;
        $errors = [];
        $detail = [];

        foreach ($files as $rel => $abs) {
            try {
                $content = (string) file_get_contents($abs);
                $hash = sha1($content);
                $meta = $this->deriveMeta($content, $rel);

                if (!isset($existing[$rel])) {
                    $r = $this->indexer->ingestFile($abs, $meta['title'], $meta['category'], $meta['tags'], $this->cleanBody($content));
                    Document::setSource($r['document_id'], self::SOURCE_TYPE, $rel, $hash);
                    $added++;
                    $detail[] = ['ref' => $rel, 'action' => 'added'];
                } elseif (($existing[$rel]['source_hash'] ?? '') !== $hash) {
                    // 内容変更 → 一旦削除して入れ直し（title/categoryの変更も確実に反映）
                    Document::delete((int) $existing[$rel]['id']);
                    $r = $this->indexer->ingestFile($abs, $meta['title'], $meta['category'], $meta['tags'], $this->cleanBody($content));
                    Document::setSource($r['document_id'], self::SOURCE_TYPE, $rel, $hash);
                    $updated++;
                    $detail[] = ['ref' => $rel, 'action' => 'updated'];
                } else {
                    $unchanged++;
                }
            } catch (\Throwable $e) {
                $errors[] = "{$rel}: " . $e->getMessage();
                $detail[] = ['ref' => $rel, 'action' => 'error'];
            }
            unset($existing[$rel]);
        }

        // vault から消えたファイルに対応する documents を削除
        foreach ($existing as $rel => $row) {
            try {
                Document::delete((int) $row['id']);
                $deleted++;
                $detail[] = ['ref' => $rel, 'action' => 'deleted'];
            } catch (\Throwable $e) {
                $errors[] = "{$rel} (delete): " . $e->getMessage();
            }
        }

        return [
            'added' => $added,
            'updated' => $updated,
            'deleted' => $deleted,
            'unchanged' => $unchanged,
            'errors' => $errors,
            'detail' => $detail,
        ];
    }

    /**
     * vault 配下の *.md を再帰列挙。
     *
     * @return array<string,string> 相対パス(区切りは/に正規化) => 絶対パス
     */
    private function scan(string $root): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                function (\SplFileInfo $current): bool {
                    if ($current->isDir()) {
                        return !in_array($current->getFilename(), self::SKIP_DIRS, true)
                            && !str_starts_with($current->getFilename(), '.');
                    }
                    if (strtolower($current->getExtension()) !== 'md') {
                        return false;
                    }
                    // README や「_」始まり（下書き等）は回答ナレッジに含めない
                    $fn = $current->getFilename();
                    if (in_array(strtolower($fn), self::SKIP_FILES, true) || str_starts_with($fn, '_')) {
                        return false;
                    }
                    return true;
                }
            )
        );
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile()) {
                continue;
            }
            $abs = $file->getPathname();
            $rel = ltrim(str_replace('\\', '/', substr($abs, strlen($root))), '/');
            $out[$rel] = $abs;
        }
        ksort($out);
        return $out;
    }

    /**
     * 取込用に本文を整える: 先頭YAMLフロントマターを除去し、Obsidian記法を素のテキストへ。
     * （出典表示や検索にメタ情報のノイズが混ざるのを防ぐ）
     */
    private function cleanBody(string $content): string
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;      // BOM
        $content = preg_replace('/^---\r?\n.*?\r?\n---\s*\r?\n/s', '', $content) ?? $content; // frontmatter
        // [[link|alias]] -> alias, [[link]] -> link
        $content = preg_replace('/\[\[([^\]|]+)\|([^\]]+)\]\]/', '$2', $content) ?? $content;
        $content = preg_replace('/\[\[([^\]]+)\]\]/', '$1', $content) ?? $content;
        // ![[embed]] の埋め込み記法は目印だけ残す
        $content = str_replace('![[', '[', $content);
        return trim($content);
    }

    /**
     * フロントマター＋パスから title / category / tags を決める。
     *
     * @return array{title:string, category:?string, tags:?string}
     */
    private function deriveMeta(string $content, string $rel): array
    {
        $fm = $this->parseFrontmatter($content);

        // title: frontmatter.title > 先頭H1 > ファイル名(拡張子なし)
        $title = $fm['title'] ?? null;
        if ($title === null || $title === '') {
            if (preg_match('/^\s*#\s+(.+?)\s*$/m', $content, $m)) {
                $title = trim($m[1]);
            }
        }
        if ($title === null || $title === '') {
            $title = pathinfo($rel, PATHINFO_FILENAME);
        }

        // category: frontmatter.category > vault内のトップ階層フォルダ名
        $category = $fm['category'] ?? null;
        if ($category === null || $category === '') {
            $dir = trim(str_replace('\\', '/', dirname($rel)), '/.');
            $category = $dir !== '' ? explode('/', $dir)[0] : null;
        }

        // tags: frontmatter.tags（配列/カンマ区切り）→ カンマ区切り文字列
        $tags = $fm['tags'] ?? null;

        return [
            'title' => mb_substr((string) $title, 0, 500),
            'category' => $category !== null ? mb_substr((string) $category, 0, 100) : null,
            'tags' => $tags !== null ? mb_substr((string) $tags, 0, 500) : null,
        ];
    }

    /**
     * 先頭の YAML フロントマター（--- ... ---）を簡易パースする。
     * 完全なYAMLではなく `key: value` と tags のリスト/インラインのみ対応（依存追加を避ける）。
     *
     * @return array{title?:string, category?:string, tags?:string}
     */
    private function parseFrontmatter(string $content): array
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content; // BOM除去
        if (!preg_match('/^---\r?\n(.*?)\r?\n---\s*\r?\n/s', $content, $m)) {
            return [];
        }
        $block = $m[1];
        $out = [];
        $tags = [];
        $inTagsList = false;

        foreach (preg_split('/\r?\n/', $block) ?: [] as $line) {
            // tags のブロックリスト（"  - foo"）
            if ($inTagsList && preg_match('/^\s*-\s*(.+?)\s*$/', $line, $mm)) {
                $tags[] = trim($mm[1], "\"' ");
                continue;
            }
            $inTagsList = false;

            if (!preg_match('/^([A-Za-z0-9_]+)\s*:\s*(.*)$/', $line, $mm)) {
                continue;
            }
            $key = strtolower($mm[1]);
            $val = trim($mm[2]);

            if ($key === 'tags') {
                if ($val === '') {
                    $inTagsList = true;          // 次行以降のリスト形式
                } elseif (preg_match('/^\[(.*)\]$/', $val, $arr)) {
                    foreach (explode(',', $arr[1]) as $t) {
                        $t = trim($t, "\"' ");
                        if ($t !== '') {
                            $tags[] = $t;
                        }
                    }
                } else {
                    foreach (preg_split('/[,\s]+/', $val) ?: [] as $t) {
                        $t = trim($t, "\"' ");
                        if ($t !== '') {
                            $tags[] = $t;
                        }
                    }
                }
                continue;
            }

            if (in_array($key, ['title', 'category'], true)) {
                $out[$key] = trim($val, "\"' ");
            }
        }

        if ($tags !== []) {
            $out['tags'] = implode(',', array_slice($tags, 0, 30));
        }
        return $out;
    }
}
