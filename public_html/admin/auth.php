<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use App\Config;

// セッション開始（管理画面は HttpOnly Cookie）
if (session_status() === PHP_SESSION_NONE) {
    // リバプロ配下でも HTTPS を正しく検出
    $isHttps = (($_SERVER['HTTPS'] ?? '') === 'on')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443);

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('rag_admin');
    session_start();
}

function admin_is_logged_in(): bool
{
    return isset($_SESSION['admin_user']) && is_string($_SESSION['admin_user']);
}

function admin_require_login(): void
{
    if (!admin_is_logged_in()) {
        // login.php は同じディレクトリにあるので相対
        header('Location: login.php');
        exit;
    }
}

/**
 * ログイン中ユーザーのロール。
 * この管理画面は「顧客専用」。ベンダーはここにログインせず、統括コンソールで管理する。
 * 常に 'customer' を返す（ベンダーロールは廃止）。
 */
function admin_role(): string
{
    return 'customer';
}

// 後方互換のためのスタブ（呼び出し元が残っていても安全側に倒す）。この画面にベンダーはいない。
function admin_is_vendor(): bool
{
    return false;
}

/**
 * 旧「ベンダー専用ページ」ガードの後方互換スタブ。顧客専用化に伴い、誰が来ても
 * ダッシュボードへ戻す（ベンダー専用ページ自体は撤去済み）。
 */
function admin_require_vendor(): void
{
    admin_require_login();
    header('Location: index.php');
    exit;
}

function admin_login(string $username, string $password): bool
{
    // 顧客専用アカウント（CUSTOMER_*）のみ。ベンダー(ADMIN_*)はこの画面では使わない。
    $accounts = [
        'customer' => ['CUSTOMER_USERNAME', 'CUSTOMER_PASSWORD_HASH'],
    ];

    $matchedRole = null;
    foreach ($accounts as $role => [$userKey, $hashKey]) {
        $expectedUser = (string) Config::get($userKey, '');
        $expectedHash = (string) Config::get($hashKey, '');
        if ($expectedUser === '' || $expectedHash === '') {
            // 未設定アカウントも timing を揃えるためダミー検証を通す
            password_verify($password, '$2y$10$' . str_repeat('a', 53));
            continue;
        }
        // ユーザー名・パスワードとも常に検証を通し、timing 差を抑える
        $userOk = hash_equals($expectedUser, $username);
        $passOk = password_verify($password, $expectedHash);
        if ($userOk && $passOk && $matchedRole === null) {
            $matchedRole = $role;
        }
    }

    // fail-loud: 顧客アカウント(CUSTOMER_*)が未設定なら誰もログインさせない（安全側）。
    // 未設定＝まだ setup-password ウィザードで初期設定していない状態。
    $customerConfigured = (string) Config::get('CUSTOMER_USERNAME', '') !== ''
        && (string) Config::get('CUSTOMER_PASSWORD_HASH', '') !== '';
    if (!$customerConfigured) {
        error_log('[admin_login] CUSTOMER_* not configured in .env (run setup-password)');
        return false;
    }

    if ($matchedRole === null) {
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['admin_user'] = $username;
    $_SESSION['admin_role'] = $matchedRole;
    $_SESSION['admin_login_at'] = time();
    return true;
}

function admin_logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $p['path'],
            $p['domain'] ?? '',
            (bool) ($p['secure'] ?? false),
            (bool) ($p['httponly'] ?? false),
        );
    }
    session_destroy();
}

/**
 * 簡易CSRFトークン。
 * フォーム内 hidden input に <input type="hidden" name="_csrf" value="<?= htmlspecialchars(admin_csrf_token()) ?>"> を入れて、
 * POST 受領側で admin_csrf_check() を呼ぶ。
 */
function admin_csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function admin_csrf_check(): void
{
    $expected = $_SESSION['_csrf'] ?? '';
    $sent = $_POST['_csrf'] ?? '';
    // 空文字バイパス防止: 期待値が空 / 送信値が空文字なら必ず拒否
    if (!is_string($expected) || $expected === ''
        || !is_string($sent) || $sent === ''
        || !hash_equals($expected, $sent)
    ) {
        http_response_code(400);
        echo 'CSRF check failed';
        exit;
    }
}
