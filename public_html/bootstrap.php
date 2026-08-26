<?php
declare(strict_types=1);

/**
 * すべての公開エンドポイント / 管理画面の最初に require する共通初期化。
 *
 *   - Composer autoloader 読み込み
 *   - .env の読み込み（Config::load）
 *   - タイムゾーン・文字コード設定
 *   - エラーハンドラ
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Config;

// .env を読み込む（demo/ ディレクトリがルート）
Config::load(dirname(__DIR__));

date_default_timezone_set((string) Config::get('APP_TIMEZONE', 'Asia/Tokyo'));
mb_internal_encoding('UTF-8');
ini_set('default_charset', 'UTF-8');

// スタックトレースに関数引数（平文APIキー等）を載せない。キー漏洩の主要経路の一つ。
@ini_set('zend.exception_ignore_args', '1');

// 開発環境のみエラー表示
$appDebug = Config::bool('APP_DEBUG', false);
if ($appDebug) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', dirname(__DIR__) . '/storage/logs/php-error.log');
}

// 最後の砦: 未捕捉例外が「生のメッセージ/トレース（APIキーを含みうる）」を
// 画面やログへ出さないようにする。APP_DEBUG でもサニタイズ済みのみ表示する。
set_exception_handler(static function (\Throwable $e) use ($appDebug): void {
    $detail = \App\Llm\LlmException::sanitize(
        $e::class . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString()
    );
    error_log('[uncaught] ' . $detail);
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo $appDebug
        ? "500 Internal Server Error\n\n" . $detail
        : '500 Internal Server Error';
});

// ログ出力ディレクトリを保証
$logDir = dirname(__DIR__) . '/storage/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
