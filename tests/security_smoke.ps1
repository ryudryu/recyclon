param(
    [string]$BaseUrl = 'http://localhost/recyclon_v1.2.5'
)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$failures = [System.Collections.Generic.List[string]]::new()

if (-not (Get-Command php -ErrorAction SilentlyContinue)) {
    $failures.Add('php was not found on PATH.')
} else {
    Get-ChildItem -LiteralPath $root -Recurse -Filter *.php -File |
        Where-Object { $_.FullName -notlike "$root\storage\private\*" } |
        ForEach-Object {
            & php -l $_.FullName *> $null
            if ($LASTEXITCODE -ne 0) {
                $failures.Add("PHP syntax failed: $($_.FullName)")
            }
        }
}

function Get-StatusCode([string]$Url) {
    $result = & curl.exe -k -sS -o NUL -w '%{http_code}' $Url 2>$null
    if ($LASTEXITCODE -ne 0) { return 0 }
    return [int]($result | Select-Object -Last 1)
}

$protectedUrls = @(
    "$BaseUrl/.env",
    "$BaseUrl/recyclon%20%284%29.sql",
    "$BaseUrl/storage/private/.env",
    "$BaseUrl/api/gps_poll.php",
    "$BaseUrl/api/gps_history.php?lorry_id=1",
    "$BaseUrl/api/arrival_queue.php",
    "$BaseUrl/add_users.php"
)

foreach ($url in $protectedUrls) {
    $status = Get-StatusCode $url
    if ($status -eq 200) {
        $failures.Add("Unexpected public HTTP 200: $url")
    }
}

if ($failures.Count -gt 0) {
    $failures | ForEach-Object { Write-Error $_ }
    exit 1
}

Write-Output "Security smoke checks passed for $BaseUrl"
