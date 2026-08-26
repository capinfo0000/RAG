<?php
declare(strict_types=1);

namespace App\Knowledge;

use App\Embedding\EmbeddingFactory;
use App\Embedding\EmbeddingProviderInterface;
use App\Models\Chunk;
use App\Models\Document;
use App\Rag\DbVectorSearch;
use App\Rag\VectorSearchInterface;

/**
 * ファイル → 本文抽出 → チャンク化 → (任意) Embedding → DB 保存 のオーケストレーション。
 *
 * 一括処理する run() は冪等にしたいので、document_id を渡したら chunks を全削除して
 * 入れ直す（rebuild）動作にする。
 */
final class Indexer
{
    public function __construct(
        private readonly Chunker $chunker,
        private readonly ?EmbeddingProviderInterface $embedding = null,
        private readonly ?VectorSearchInterface $vectorIndex = null,
    ) {
    }

    public static function fromConfig(): self
    {
        // 埋め込みプロバイダがある場合はDB内ベクトルストアへ保存する。
        $embedding = EmbeddingFactory::create();
        return new self(
            chunker: Chunker::fromConfig(),
            embedding: $embedding,
            vectorIndex: $embedding !== null ? new DbVectorSearch($embedding->getModelName()) : null,
        );
    }

    /**
     * 1ファイルを取り込んで documents + chunks を作成。
     *
     * @return array{document_id:int, chunk_count:int}
     */
    public function ingestFile(
        string $filePath,
        string $title,
        ?string $category = null,
        ?string $tags = null,
        ?string $textOverride = null,
    ): array {
        if (!is_file($filePath)) {
            throw new \RuntimeException("File not found: {$filePath}");
        }

        // $textOverride があればそれを本文として使う（ナレッジ源側で前処理した
        // クリーンテキストを渡す用途。Obsidianのフロントマター除去など）。
        if ($textOverride !== null && trim($textOverride) !== '') {
            $text = $textOverride;
        } else {
            $extractor = ExtractorFactory::forFile($filePath);
            $text = $extractor->extract($filePath);
        }

        if (trim($text) === '') {
            throw new \RuntimeException("Extracted text is empty: {$filePath}");
        }

        // storage_path は正規化された絶対パスで保存（後の削除ロジックで realpath 比較するため）
        $storedPath = realpath($filePath);
        if ($storedPath === false) {
            $storedPath = $filePath;
        }
        $documentId = Document::create([
            'title' => $title,
            'original_filename' => basename($filePath),
            'storage_path' => $storedPath,
            'file_type' => strtolower(pathinfo($filePath, PATHINFO_EXTENSION)),
            'file_size_bytes' => (int) filesize($filePath),
            'category' => $category,
            'tags' => $tags,
            'status' => Document::STATUS_PROCESSING,
            'full_text' => $text,
        ]);

        try {
            $chunks = $this->chunker->chunk($text, $title);
            if ($chunks === []) {
                Document::updateStatus($documentId, Document::STATUS_FAILED, 'No chunks produced');
                return ['document_id' => $documentId, 'chunk_count' => 0];
            }

            $rows = [];
            foreach ($chunks as $c) {
                $rows[] = [
                    'document_id' => $documentId,
                    'chunk_index' => $c['chunk_index'],
                    'text' => $c['text'],
                    'context_text' => $c['context_text'],
                    'token_count' => $c['token_count'],
                    'vector_id' => null,
                    'metadata' => null,
                ];
            }
            $insertedIds = Chunk::createMany($rows);

            // ベクトル化（任意）
            if ($this->embedding !== null && $insertedIds !== []) {
                $this->indexVectors($insertedIds, $chunks);
            }

            Document::updateChunkCount($documentId, count($insertedIds));
            Document::updateStatus($documentId, Document::STATUS_INDEXED);

            return ['document_id' => $documentId, 'chunk_count' => count($insertedIds)];
        } catch (\Throwable $e) {
            Document::updateStatus($documentId, Document::STATUS_FAILED, $e->getMessage());
            throw $e;
        }
    }

    /**
     * 既存ドキュメントを再インデックス（chunks 全削除→再構築）。
     */
    public function reindex(int $documentId): array
    {
        $doc = Document::find($documentId);
        if ($doc === null) {
            throw new \RuntimeException("Document not found: id={$documentId}");
        }
        Chunk::deleteByDocumentId($documentId);

        $extractor = ExtractorFactory::forFile($doc['storage_path']);
        $text = $extractor->extract($doc['storage_path']);
        Document::updateFullText($documentId, $text);
        $chunks = $this->chunker->chunk($text, $doc['title']);

        $rows = array_map(fn(array $c) => [
            'document_id' => $documentId,
            'chunk_index' => $c['chunk_index'],
            'text' => $c['text'],
            'context_text' => $c['context_text'],
            'token_count' => $c['token_count'],
            'vector_id' => null,
            'metadata' => null,
        ], $chunks);
        $insertedIds = Chunk::createMany($rows);

        if ($this->embedding !== null && $insertedIds !== []) {
            $this->indexVectors($insertedIds, $chunks);
        }

        Document::updateChunkCount($documentId, count($insertedIds));
        Document::updateStatus($documentId, Document::STATUS_INDEXED);
        return ['document_id' => $documentId, 'chunk_count' => count($insertedIds)];
    }

    /**
     * 既存ドキュメントの本文を「渡された本文」で丸ごと上書きし、再インデックスする
     * （chunks 全削除→再チャンク→再ベクトル化）。タイトルは変更しない（意図せぬリネーム防止）。
     * storage のファイルがあれば新本文で上書きし整合を保つ（書込可能な場合のみ）。
     */
    public function updateContent(int $documentId, string $body): array
    {
        $doc = Document::find($documentId);
        if ($doc === null) {
            throw new \RuntimeException("Document not found: id={$documentId}");
        }
        if (trim($body) === '') {
            throw new \RuntimeException('本文が空です。');
        }

        $storagePath = (string) ($doc['storage_path'] ?? '');
        if ($storagePath !== '' && is_file($storagePath) && is_writable($storagePath)) {
            @file_put_contents($storagePath, $body);
        }

        Document::updateFullText($documentId, $body);
        Chunk::deleteByDocumentId($documentId);

        $chunks = $this->chunker->chunk($body, (string) $doc['title']);
        $rows = array_map(fn(array $c) => [
            'document_id' => $documentId,
            'chunk_index' => $c['chunk_index'],
            'text' => $c['text'],
            'context_text' => $c['context_text'],
            'token_count' => $c['token_count'],
            'vector_id' => null,
            'metadata' => null,
        ], $chunks);
        $insertedIds = Chunk::createMany($rows);

        if ($this->embedding !== null && $insertedIds !== []) {
            $this->indexVectors($insertedIds, $chunks);
        }

        Document::updateChunkCount($documentId, count($insertedIds));
        Document::updateStatus($documentId, Document::STATUS_INDEXED);
        return ['document_id' => $documentId, 'chunk_count' => count($insertedIds)];
    }

    /**
     * 挿入された chunks のテキストをまとめてEmbeddingし、ベクトルストアへ保存する。
     * 既定の {@see DbVectorSearch} では chunks.embedding 列に格納され、
     * HybridSearch がDB内コサイン類似度で検索できるようになる。
     *
     * @param int[] $chunkIds
     * @param array<int, array{text:string}> $chunkData
     */
    private function indexVectors(array $chunkIds, array $chunkData): void
    {
        if ($this->embedding === null || $this->vectorIndex === null) {
            return;
        }
        $texts = array_map(fn($c) => $c['text'], $chunkData);
        try {
            $vectors = $this->embedding->embed($texts, 'document');
            // 取り込み時のベクトル化も API 消費。推定トークンを統一台帳へ（best-effort）。
            \App\Models\UsageLog::record(
                \App\Models\UsageLog::KIND_EMBED_INGEST,
                \App\Models\UsageLog::estimateTokens($texts),
                $this->embedding->getModelName(),
                true
            );
        } catch (\Throwable $e) {
            // Embedding失敗時はベクトルなしのままキーワード検索のみで動く（縮退運転）
            error_log('[Indexer.indexVectors] embedding failed: ' . $e->getMessage());
            return;
        }
        if (count($vectors) !== count($chunkIds)) {
            error_log(sprintf(
                '[Indexer.indexVectors] vector count mismatch: %d vectors for %d chunks',
                count($vectors),
                count($chunkIds),
            ));
            return;
        }

        $points = [];
        foreach ($chunkIds as $i => $chunkId) {
            $points[] = ['chunk_id' => $chunkId, 'vector' => $vectors[$i], 'payload' => []];
        }
        $this->vectorIndex->upsert($points);
    }
}
