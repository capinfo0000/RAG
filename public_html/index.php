<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

// ルートアクセスは /chat にリダイレクト
header('Location: ./chat/', true, 302);
exit;
