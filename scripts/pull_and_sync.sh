#!/bin/sh
# ============================================================
# Obsidian自動同期パターン用ヘルパ
# ============================================================
# 顧客がObsidian(+Gitプラグイン)でvaultリポジトリに push した内容を、
# サーバ側で取り込む。cron で定期実行する想定。
#
#   パターン① Obsidian自動同期:
#     顧客PC: Obsidianで .md 編集 → Obsidian Git が remote へ push
#     サーバ: 本スクリプトを cron 実行 → git pull → sync_obsidian.php で反映
#
#   パターン② サーバ格納（.md を SFTP 等で直接配置）の場合は git 管理でない
#     ことが多いので pull はスキップされ、sync だけ走る（そのまま使える）。
#
# cron 例（30分毎）:
#   */30 * * * * /path/to/rag/scripts/pull_and_sync.sh >> /path/to/rag/storage/logs/sync.log 2>&1
#
# 引数:
#   $1 = vault ディレクトリ（省略時は .env の OBSIDIAN_VAULT_PATH を使用）
#
# 注意: PHP CLI のパスは環境に合わせて調整（Coreserver等ではフルパス指定が必要な場合あり）。
#   例: PHP_BIN=/usr/bin/php8.2
# ============================================================
set -eu

APP_DIR="$(cd "$(dirname "$0")/.." && pwd)"
PHP_BIN="${PHP_BIN:-php}"
VAULT_DIR="${1:-}"

echo "[$(date '+%Y-%m-%d %H:%M:%S')] pull_and_sync 開始"

# vault が Git 管理下なら pull（Obsidian Git が push した最新を取得）。
# 管理下でなければ（パターン②）スキップ。
if [ -n "$VAULT_DIR" ] && [ -d "$VAULT_DIR/.git" ]; then
    echo "  git pull: $VAULT_DIR"
    if ! git -C "$VAULT_DIR" pull --ff-only; then
        echo "  [warn] git pull に失敗（ネットワーク/コンフリクト）。同期は続行します。"
    fi
fi

cd "$APP_DIR"
if [ -n "$VAULT_DIR" ]; then
    "$PHP_BIN" scripts/sync_obsidian.php "$VAULT_DIR"
else
    "$PHP_BIN" scripts/sync_obsidian.php
fi

echo "[$(date '+%Y-%m-%d %H:%M:%S')] pull_and_sync 完了"
