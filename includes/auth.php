<?php

// ========================================
// SESSION CONFIGURATION
// ========================================

if (session_status() !== PHP_SESSION_ACTIVE) {

    $isHttps = (
        isset($_SERVER['HTTPS']) &&
        $_SERVER['HTTPS'] !== 'off'
    );

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    session_start();
}

// ========================================
// CSRF TOKEN
// ========================================

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ========================================
// HTML ESCAPE
// ========================================

function e($value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

// ========================================
// APPLICATION BASE URL
// Mendukung localhost/tokoku dan tokoku.test
// ========================================

function appUrl(string $path = ''): string
{
    $script = str_replace(
        '\\',
        '/',
        $_SERVER['SCRIPT_NAME'] ?? '/index.php'
    );

    $directory = trim(dirname($script), '/');

    $segments = $directory === ''
        ? []
        : explode('/', $directory);

    $roleFolders = ['admin', 'kasir', 'customer'];

    if (
        !empty($segments) &&
        in_array(end($segments), $roleFolders, true)
    ) {
        array_pop($segments);
    }

    $basePath = empty($segments)
        ? ''
        : '/' . implode('/', $segments);

    return $basePath . '/' . ltrim($path, '/');
}

// ========================================
// REDIRECT BERDASARKAN ROLE
// ========================================

function redirectByRole(): void
{
    $role = $_SESSION['role'] ?? '';

    $routes = [
        'admin' => 'admin/dashboard.php',
        'kasir' => 'kasir/dashboard.php',
        'pelanggan' => 'customer/index.php'
    ];

    if (!isset($routes[$role])) {
        logoutUser();
    }

    header('Location: ' . appUrl($routes[$role]));
    exit;
}

// ========================================
// LOGIN CHECK
// ========================================

function requireLogin(): void
{
    if (
        empty($_SESSION['user_id']) ||
        empty($_SESSION['role'])
    ) {
        header('Location: ' . appUrl('index.php'));
        exit;
    }
}

// ========================================
// ROLE AUTHORIZATION
// ========================================

function requireRole(string $requiredRole): void
{
    requireLogin();

    if (($_SESSION['role'] ?? '') !== $requiredRole) {

        $_SESSION = [
            'access_error' => 'Anda tidak memiliki izin untuk mengakses halaman tersebut.'
        ];

        session_regenerate_id(true);

        header('Location: ' . appUrl('index.php'));
        exit;
    }
}

// ========================================
// LOGOUT
// ========================================

function logoutUser(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {

        $params = session_get_cookie_params();

        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax'
        ]);
    }

    session_destroy();

    header('Location: ' . appUrl('index.php'));
    exit;
}
