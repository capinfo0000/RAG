<?php
declare(strict_types=1);

namespace App\Models;

use App\Config;

/**
 * settings テーブルの key-value ストア。
 *
 * 管理画面で動的に変更される: llm_provider / llm_api_key_encrypted / embedding_provider 等。
 *
 * APIキーは setEncrypted() / getEncrypted() で AES-256-GCM 暗号化して保存。
 * 暗号化キーは .env の APP_ENCRYPTION_KEY（'base64:xxxx' 形式の32バイト）。
 */
final class Settings
{
    /** @var array<string, ?string> 同一リクエスト内のキャッシュ */
    private static array $cache = [];

    public static function get(string $key, ?string $default = null): ?string
    {
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }
        $stmt = Database::pdo()->prepare(
            'SELECT setting_value FROM settings WHERE setting_key = :k LIMIT 1'
        );
        $stmt->execute([':k' => $key]);
        $row = $stmt->fetch();
        $value = $row === false ? $default : $row['setting_value'];
        self::$cache[$key] = $value;
        return $value;
    }

    public static function set(string $key, ?string $value): void
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO settings (setting_key, setting_value) VALUES (:k, :v)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        $stmt->execute([':k' => $key, ':v' => $value]);
        self::$cache[$key] = $value;
    }

    public static function getEncrypted(string $key): ?string
    {
        $cipher = self::get($key);
        if ($cipher === null || $cipher === '') {
            return null;
        }
        return self::decrypt($cipher);
    }

    public static function setEncrypted(string $key, ?string $plain): void
    {
        if ($plain === null || $plain === '') {
            self::set($key, '');
            return;
        }
        self::set($key, self::encrypt($plain));
    }

    /**
     * env と DB をマージして「実効値」を返す。
     * DBの値が空文字なら env をフォールバック。
     */
    public static function effective(string $dbKey, string $envKey, ?string $default = null): ?string
    {
        $v = self::get($dbKey);
        if ($v === null || $v === '') {
            $v = Config::get($envKey, $default);
            return $v === null ? $default : (string) $v;
        }
        return $v;
    }

    /**
     * APIキー版の effective: DBに暗号化保存されていればそれを復号、なければenvから。
     */
    public static function effectiveApiKey(string $dbEncryptedKey, string $envKey): ?string
    {
        try {
            $decoded = self::getEncrypted($dbEncryptedKey);
            if ($decoded !== null && $decoded !== '') {
                return $decoded;
            }
        } catch (\Throwable) {
            // 復号失敗時はenvにフォールバック
        }
        $env = (string) Config::get($envKey, '');
        return $env !== '' ? $env : null;
    }

    /** @return array<string, ?string> */
    public static function all(): array
    {
        $stmt = Database::pdo()->query('SELECT setting_key, setting_value FROM settings');
        $rows = $stmt->fetchAll();
        $out = [];
        foreach ($rows as $row) {
            $out[$row['setting_key']] = $row['setting_value'];
            self::$cache[$row['setting_key']] = $row['setting_value'];
        }
        return $out;
    }

    public static function clearCache(): void
    {
        self::$cache = [];
    }

    /**
     * 32バイトのランダムキーを生成し base64:XXX 形式で返す。.envに貼り付ける用。
     */
    public static function generateEncryptionKey(): string
    {
        return 'base64:' . base64_encode(random_bytes(32));
    }

    // ============================================================
    // 暗号化／復号（AES-256-GCM）
    // ============================================================
    // フォーマット: base64( iv(12B) || tag(16B) || ciphertext )

    private static function encrypt(string $plain): string
    {
        $key = self::getEncryptionKey();
        $iv = \random_bytes(12);
        $tag = '';
        // \openssl_* で global namespace を明示（App\Models 内で App\Models\openssl_* と
        // 解決されてしまうのを防ぐ）
        $cipher = \openssl_encrypt(
            $plain,
            'aes-256-gcm',
            $key,
            \OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16
        );
        if ($cipher === false) {
            throw new \RuntimeException('Settings encrypt failed: ' . (\openssl_error_string() ?: 'unknown'));
        }
        return \base64_encode($iv . $tag . $cipher);
    }

    private static function decrypt(string $encoded): string
    {
        $key = self::getEncryptionKey();
        $raw = \base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < 28) {
            throw new \RuntimeException('Settings decrypt: invalid payload');
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain = \openssl_decrypt(
            $cipher,
            'aes-256-gcm',
            $key,
            \OPENSSL_RAW_DATA,
            $iv,
            $tag,
        );
        if ($plain === false) {
            throw new \RuntimeException('Settings decrypt failed (tag mismatch or wrong key)');
        }
        return $plain;
    }

    private static function getEncryptionKey(): string
    {
        $raw = (string) Config::get('APP_ENCRYPTION_KEY', '');
        if (!str_starts_with($raw, 'base64:')) {
            throw new \RuntimeException(
                'APP_ENCRYPTION_KEY must be set in .env as "base64:<32-bytes-base64>". '
                . 'Generate one with: '
                . 'php -r "require \'vendor/autoload.php\'; App\\Config::load(\'.\'); '
                . 'echo App\\Models\\Settings::generateEncryptionKey() . PHP_EOL;"'
            );
        }
        $key = \base64_decode(substr($raw, 7), true);
        if ($key === false || strlen($key) !== 32) {
            throw new \RuntimeException(
                'APP_ENCRYPTION_KEY is invalid (must decode to exactly 32 bytes). '
                . 'Got ' . ($key === false ? 'decode failure' : strlen($key) . ' bytes')
            );
        }
        return $key;
    }
}
