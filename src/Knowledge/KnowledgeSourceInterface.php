<?php
declare(strict_types=1);

namespace App\Knowledge;

/**
 * ナレッジ源（知識の取込元）の抽象。
 *
 * 「回答源をどこから取り込むか」を差し替え可能にするための契約。
 * 現在の実装: ObsidianVaultSource（Markdown vault）。
 * 将来: NotionSource / WebCrawlSource / GoogleDriveSource などを同じ口で追加できる。
 *
 * 各実装は sync() で外部ソースと documents/chunks の差分を取り、
 * 追加・更新・削除を反映して結果サマリを返す（冪等な再同期）。
 */
interface KnowledgeSourceInterface
{
    /** ソース種別の識別子（例: 'obsidian'）。documents.source_type に保存される。 */
    public function type(): string;

    /**
     * ソースと取込済みデータを同期する（増分）。
     *
     * @return array{
     *   added:int, updated:int, deleted:int, unchanged:int,
     *   errors:array<int,string>, detail:array<int,array{ref:string,action:string}>
     * }
     */
    public function sync(): array;
}
