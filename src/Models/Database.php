<?php
declare(strict_types=1);

namespace App\Models;

use App\Config;
use PDO;
use PDOException;

/**
 * MySQL/MariaDB 接続シングルトン。
 *
 * 接続情報は env から取得。utf8mb4 を強制し、emulation prepares を無効化して
 * 真のプリペアドステートメントを使う（SQLi 対策の基本）。
 */
final class Database
{
    private static ?PDO $instance = null;
    private static bool $schemaChecked = false;

    private function __construct()
    {
    }

    public static function pdo(): PDO
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $host = (string) Config::get('DB_HOST', '127.0.0.1');
        $port = (int) Config::get('DB_PORT', 3306);
        $name = (string) Config::get('DB_NAME', 'rag_chatbot');
        $user = (string) Config::get('DB_USER', 'root');
        $pass = (string) Config::get('DB_PASSWORD', '');

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name);

        try {
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
            ]);
        } catch (PDOException $e) {
            throw new \RuntimeException(
                'Database connection failed: ' . $e->getMessage(),
                (int) $e->getCode(),
                $e,
            );
        }

        self::$instance = $pdo;
        // 接続できたら、テーブルが無い初回だけ schema.sql を自動適用（導入時のインポート手順を不要に）。
        self::ensureSchema();
        return self::$instance;
    }

    /**
     * 初回起動時、コアテーブルが無ければ sql/schema.sql を自動適用する。
     * schema.sql は全テーブル CREATE TABLE IF NOT EXISTS で冪等。
     * 同時アクセスの二重実行は MySQL アドバイザリロックで直列化する。
     * DB作成（CREATE DATABASE）は共用ホストでは権限が無く手動のまま。ここではテーブルのみ。
     */
    private static function ensureSchema(): void
    {
        if (self::$schemaChecked) {
            return;
        }
        self::$schemaChecked = true;
        $pdo = self::$instance;
        if ($pdo === null) {
            return;
        }

        try {
            if ($pdo->query("SHOW TABLES LIKE 'settings'")->fetch() !== false) {
                return; // 既に初期化済み
            }
        } catch (\Throwable) {
            return; // 判定不能なら従来どおり（何もしない）
        }

        $schemaFile = dirname(__DIR__, 2) . '/sql/schema.sql';
        if (!is_file($schemaFile)) {
            return;
        }

        $locked = false;
        try {
            $locked = (bool) $pdo->query("SELECT GET_LOCK('rag_schema_init', 10)")->fetchColumn();
            if (!$locked) {
                return;
            }
            // ロック取得後に再確認（他リクエストが先に作成済みかもしれない）
            if ($pdo->query("SHOW TABLES LIKE 'settings'")->fetch() !== false) {
                return;
            }
            self::runSqlFile($pdo, (string) file_get_contents($schemaFile));
            error_log('[Database] schema.sql を自動適用しました（初回セットアップ）。');
        } catch (\Throwable $e) {
            error_log('[Database::ensureSchema] 自動適用に失敗: ' . $e->getMessage());
        } finally {
            if ($locked) {
                try {
                    $pdo->query("SELECT RELEASE_LOCK('rag_schema_init')");
                } catch (\Throwable) {
                }
            }
        }
    }

    /** .sql ファイルを ; 区切りで1文ずつ実行（行/ブロックコメントは除去）。 */
    private static function runSqlFile(PDO $pdo, string $sql): void
    {
        $sql = (string) preg_replace('/^\s*--.*$/m', '', $sql);       // 行コメント
        $sql = (string) preg_replace('#/\*.*?\*/#s', '', $sql);       // ブロックコメント
        foreach (explode(';', $sql) as $stmt) {
            $stmt = trim($stmt);
            if ($stmt !== '') {
                $pdo->exec($stmt);
            }
        }
    }

    /** テスト用ヘルパー: 接続を強制リセット */
    public static function reset(): void
    {
        self::$instance = null;
        self::$schemaChecked = false;
    }

    /** トランザクション内で関数を実行する糖衣構文 */
    public static function transaction(callable $fn): mixed
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $result = $fn($pdo);
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
