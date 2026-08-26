<?php
// php -S 用ルータ: Windows のビルトインサーバが ~8KB超の静的ファイルを
// 配信できない癖を回避する。.php は従来どおり実行させる（return false）。
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$docroot = $_SERVER['DOCUMENT_ROOT'] ?? __DIR__ . '/public';
$file = realpath($docroot . $path);

// docroot 外へのトラバーサル防止
if ($file !== false && strncmp($file, realpath($docroot), strlen(realpath($docroot))) === 0
    && is_file($file)
    && strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'php') {
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $mimes = [
        'html' => 'text/html', 'htm' => 'text/html', 'js' => 'application/javascript',
        'css' => 'text/css', 'json' => 'application/json', 'svg' => 'image/svg+xml',
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'gif' => 'image/gif', 'ico' => 'image/x-icon', 'woff2' => 'font/woff2',
    ];
    $mime = $mimes[$ext] ?? 'application/octet-stream';
    $charset = str_starts_with($mime, 'text/') || $mime === 'application/javascript' || $mime === 'application/json'
        ? '; charset=utf-8' : '';
    header('Content-Type: ' . $mime . $charset);
    header('Content-Length: ' . filesize($file));
    readfile($file);
    return true;
}

return false; // .php・ディレクトリ index はビルトインサーバに任せる
