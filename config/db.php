<?php
require_once __DIR__ . '/session.php';

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: geolocation=(self), camera=(), microphone=()');


if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Keep application settings available to the rest of the app. The local
// protected file is temporary compatibility for this checkout; secrets must
// move to a deployment secret manager before production.
$env = [
    'DB_DRIVER' => (string)(getenv('DB_DRIVER') ?: 'mysql'),
    'DB_HOST' => (string)(getenv('DB_HOST') ?: 'localhost'),
    'DB_PORT' => (string)(getenv('DB_PORT') ?: '3306'),
    'DB_NAME' => (string)(getenv('DB_NAME') ?: 'recyclon'),
    'DB_USER' => (string)(getenv('DB_USER') ?: 'root'),
    'DB_PASSWORD' => (string)(getenv('DB_PASSWORD') ?: ''),
    'SUPABASE_DB_URL' => (string)(getenv('SUPABASE_DB_URL') ?: ''),
    'APP_ENCRYPTION_KEY' => (string)(getenv('APP_ENCRYPTION_KEY') ?: ''),
    'RECYCLON_GPS_API_KEY' => (string)(getenv('RECYCLON_GPS_API_KEY') ?: ''),
    'DEFAULT_TEMP_PASSWORD' => (string)(getenv('DEFAULT_TEMP_PASSWORD') ?: ''),
    'ENFORCE_HTTPS' => (string)(getenv('ENFORCE_HTTPS') === false ? '1' : getenv('ENFORCE_HTTPS')),
];
$envFile = __DIR__ . '/../storage/private/.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) continue;
        $key = trim($parts[0]);
        $env[$key] = trim($parts[1]);
    }
}

$dbDriver = strtolower($env['DB_DRIVER']);
$dbUrl = (string)$env['SUPABASE_DB_URL'];

$encryptionKey = (string)$env['APP_ENCRYPTION_KEY'];
$enforceHttps = filter_var($env['ENFORCE_HTTPS'], FILTER_VALIDATE_BOOLEAN);
$forwardedProto = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0] ?? ''));
$isHttpsRequest = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (string)($_SERVER['SERVER_PORT'] ?? '') === '443'
    || $forwardedProto === 'https';

if ($enforceHttps && PHP_SAPI !== 'cli' && !$isHttpsRequest) {
    $host = preg_replace('/[^A-Za-z0-9.:-]/', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
    header('Location: https://' . $host . $uri, true, 301);
    exit;
}

if ($isHttpsRequest) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

function app_encrypt(string $plaintext, string $key): ?string {
    if ($plaintext === '' || $key === '') return $plaintext;
    $ivLen = openssl_cipher_iv_length('AES-256-CBC');
    $iv = random_bytes($ivLen);
    $cipher = openssl_encrypt($plaintext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    if ($cipher === false) return null;
    return base64_encode($iv . $cipher);
}

function app_decrypt(string $ciphertext, string $key): string {
    if ($ciphertext === '' || $key === '') return $ciphertext;
    $data = base64_decode($ciphertext, true);
    if ($data === false) return $ciphertext;
    $ivLen = openssl_cipher_iv_length('AES-256-CBC');
    if (strlen($data) < $ivLen) return $ciphertext;
    $iv = substr($data, 0, $ivLen);
    $cipher = substr($data, $ivLen);
    $plain = openssl_decrypt($cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    if ($plain === false) return $ciphertext;
    return $plain;
}

function app_table_exists(PDO $conn, string $table): bool {
    $stmt = $conn->prepare(
        "SELECT 1 FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = ? LIMIT 1"
    );
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
}

function app_table_columns(PDO $conn, string $table): array {
    $stmt = $conn->prepare(
        "SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? ORDER BY ordinal_position"
    );
    $stmt->execute([$table]);
    return array_map('strtolower', $stmt->fetchAll(PDO::FETCH_COLUMN, 0));
}

try {
    if ($dbDriver === 'pgsql') {
        if (!extension_loaded('pdo_pgsql')) {
            throw new RuntimeException('The pdo_pgsql PHP extension is required for Supabase PostgreSQL connections.');
        }

        $parsedUrl = $dbUrl !== '' ? parse_url($dbUrl) : false;
        if ($parsedUrl === false || empty($parsedUrl['host'])) {
            throw new RuntimeException('SUPABASE_DB_URL must be a valid PostgreSQL connection URL.');
        }

        $pgHost = $parsedUrl['host'];
        $pgPort = (int)($parsedUrl['port'] ?? 5432);
        $pgDatabase = ltrim((string)($parsedUrl['path'] ?? '/postgres'), '/');
        $pgUser = rawurldecode((string)($parsedUrl['user'] ?? $env['DB_USER']));
        $pgPassword = rawurldecode((string)($parsedUrl['pass'] ?? $env['DB_PASSWORD']));
        $conn = new PDO(
            "pgsql:host=$pgHost;port=$pgPort;dbname=$pgDatabase",
            $pgUser,
            $pgPassword
        );
    } else {
        $conn = new PDO(
            "mysql:host={$env['DB_HOST']};port={$env['DB_PORT']};dbname={$env['DB_NAME']};charset=utf8mb4",
            $env['DB_USER'],
            $env['DB_PASSWORD']
        );
    }
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

} catch (PDOException | RuntimeException $e) {
    error_log('Recyclon database connection failed: ' . $e->getMessage());
    http_response_code(503);
    die('The application is temporarily unavailable.');
}
