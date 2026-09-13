<?php
// Configure the session before any session is started. Web sessions are
// forced onto HTTPS; CLI scripts remain usable for linting and maintenance.
$isCli = PHP_SAPI === 'cli';
$forwardedProto = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0] ?? ''));
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (string)($_SERVER['SERVER_PORT'] ?? '') === '443'
    || $forwardedProto === 'https';
$httpsSetting = getenv('ENFORCE_HTTPS');
$enforceHttps = $httpsSetting === false
    ? true
    : filter_var($httpsSetting, FILTER_VALIDATE_BOOLEAN);

if (!$isCli && $enforceHttps && !$isHttps) {
    $requestHost = preg_replace('/[^A-Za-z0-9.:-]/', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
    $requestUri = (string)($_SERVER['REQUEST_URI'] ?? '/');
    if ($requestHost !== '') {
        header('Location: https://' . $requestHost . $requestUri, true, 301);
        exit;
    }
}

$sessionDirectory = __DIR__ . '/../storage/sessions';
if (!is_dir($sessionDirectory)) {
    @mkdir($sessionDirectory, 0700, true);
}
if (is_dir($sessionDirectory) && is_writable($sessionDirectory)) {
    session_save_path($sessionDirectory);
}

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => !$isCli && ($isHttps || $enforceHttps),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

if (!function_exists('ensure_csrf_token')) {
    function ensure_csrf_token(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return (string)$_SESSION['csrf_token'];
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return '<input type="hidden" name="csrf_token" value="'
            . htmlspecialchars(ensure_csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
    }
}

if (!function_exists('verify_csrf_token')) {
    function verify_csrf_token(?string $submitted = null): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $expected = (string)($_SESSION['csrf_token'] ?? '');
        $submitted = $submitted ?? ($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
        return $expected !== '' && is_string($submitted) && $submitted !== ''
            && hash_equals($expected, $submitted);
    }
}
