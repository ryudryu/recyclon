<?php
function loginRateLimitFile(string $key): string
{
    return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR . 'recyclon_login_' . hash('sha256', $key) . '.json';
}

function checkLoginRateLimit(string $key, int $maxAttempts = 5, int $windowSeconds = 900): array
{
    $file = loginRateLimitFile($key);
    $handle = fopen($file, 'c+');
    if (!$handle) return ['allowed' => true, 'retry_after' => 0];

    flock($handle, LOCK_EX);
    $raw = stream_get_contents($handle);
    $state = is_string($raw) ? json_decode($raw, true) : null;
    $now = time();
    if (!is_array($state) || ($now - (int)($state['started_at'] ?? 0)) >= $windowSeconds) {
        $state = ['started_at' => $now, 'attempts' => 0];
    }

    $state['attempts'] = (int)$state['attempts'] + 1;
    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, json_encode($state));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);

    $allowed = $state['attempts'] <= $maxAttempts;
    return [
        'allowed' => $allowed,
        'retry_after' => $allowed ? 0 : max(1, $windowSeconds - ($now - (int)$state['started_at'])),
    ];
}

function clearLoginRateLimit(string $key): void
{
    $file = loginRateLimitFile($key);
    if (is_file($file)) @unlink($file);
}
