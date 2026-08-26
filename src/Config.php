<?php
declare(strict_types=1);

namespace App;

use Dotenv\Dotenv;

final class Config
{
    private static ?array $values = null;

    public static function load(string $rootPath): void
    {
        if (self::$values !== null) {
            return;
        }

        if (file_exists($rootPath . '/.env')) {
            $dotenv = Dotenv::createImmutable($rootPath);
            $dotenv->safeLoad();
        }

        self::$values = $_ENV + $_SERVER;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (self::$values === null) {
            throw new \RuntimeException('Config not loaded. Call Config::load() first.');
        }
        $value = self::$values[$key] ?? getenv($key);
        if ($value === false || $value === null || $value === '') {
            return $default;
        }
        return $value;
    }

    public static function require(string $key): string
    {
        $value = self::get($key);
        if ($value === null) {
            throw new \RuntimeException("Required env var '{$key}' is not set.");
        }
        return (string) $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        if ($value === null) {
            return $default;
        }
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key);
        return $value === null ? $default : (int) $value;
    }

    public static function float(string $key, float $default = 0.0): float
    {
        $value = self::get($key);
        return $value === null ? $default : (float) $value;
    }
}
