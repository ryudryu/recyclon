<?php
require_once __DIR__ . '/config/db.php';

$language = (string) ($_GET['lang'] ?? 'en');
if (in_array($language, ['en', 'ms', 'zh'], true)) {
    $_SESSION['language'] = $language;
}

$referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
$refererHost = (string) (parse_url($referer, PHP_URL_HOST) ?? '');
$requestHost = (string) ($_SERVER['HTTP_HOST'] ?? '');
$redirect = $referer && (!$refererHost || strcasecmp($refererHost, $requestHost) === 0)
    ? $referer
    : 'index.php';

header('Location: ' . $redirect, true, 302);
exit;
