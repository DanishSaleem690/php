<?php
declare(strict_types=1);

/**
 * Combined CORS + DB helpers for GitHub “upload files” deploys (no lib/ folder needed).
 * Prefer lib/cors.php + lib/db.php when using git; this file is an equivalent single-file fallback.
 */

// --- cors.php ---
function cors_static_allowed_origins(): array
{
    $origins = [
        'http://localhost:5173',
        'http://localhost:3000',
        'http://127.0.0.1:5173',
        'http://127.0.0.1:3000',
        'https://klashpvt.com',
        'https://www.klashpvt.com',
        'http://klashpvt.com',
        'http://www.klashpvt.com',
    ];

    foreach (['FRONTEND_ORIGIN', 'NETLIFY_URL'] as $key) {
        $v = getenv($key);
        if (is_string($v) && $v !== '') {
            $origins[] = rtrim($v, '/');
        }
    }

    $extra = getenv('CORS_ALLOWED_ORIGINS');
    if (is_string($extra) && $extra !== '') {
        foreach (explode(',', $extra) as $part) {
            $part = trim($part);
            if ($part !== '') {
                $origins[] = rtrim($part, '/');
            }
        }
    }

    return array_values(array_unique($origins));
}

function cors_origin_is_allowed(string $origin): bool
{
    if ($origin === '') {
        return false;
    }

    if (in_array($origin, cors_static_allowed_origins(), true)) {
        return true;
    }

    $host = parse_url($origin, PHP_URL_HOST);
    if (!is_string($host) || $host === '') {
        return false;
    }

    $host = strtolower($host);
    if ($host === 'netlify.app' || str_ends_with($host, '.netlify.app')) {
        return true;
    }

    return false;
}

function cors_apply(array $options = []): void
{
    $credentials = (bool) ($options['credentials'] ?? false);
    $methods = (string) ($options['methods'] ?? 'GET, POST, OPTIONS');
    $defaultOrigin = $options['default_origin'] ?? null;

    $origin = isset($_SERVER['HTTP_ORIGIN']) ? (string) $_SERVER['HTTP_ORIGIN'] : '';

    if ($origin !== '' && cors_origin_is_allowed($origin)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
        if ($credentials) {
            header('Access-Control-Allow-Credentials: true');
        }
    } elseif ($origin === '' && is_string($defaultOrigin) && $defaultOrigin !== '') {
        header('Access-Control-Allow-Origin: ' . $defaultOrigin);
        if ($credentials) {
            header('Access-Control-Allow-Credentials: true');
        }
    }

    header('Access-Control-Allow-Methods: ' . $methods);

    $reqHeaders = isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'])
        ? trim((string) $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'])
        : '';
    if ($reqHeaders !== '') {
        $safe = preg_replace('/[^a-zA-Z0-9,\s\-_]/', '', substr($reqHeaders, 0, 2048));
        header(
            'Access-Control-Allow-Headers: ' . ($safe !== '' ? $safe : 'Content-Type, Accept')
        );
    } else {
        $headers = $options['headers'] ?? 'Content-Type, Accept, Authorization, X-Requested-With';
        header('Access-Control-Allow-Headers: ' . (string) $headers);
    }
}

function cors_is_preflight(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS';
}

function cors_send_preflight(int $maxAge = 86400): void
{
    header('Access-Control-Max-Age: ' . (string) $maxAge);
    http_response_code(204);
    exit;
}

function cors_configure_session_cookies(): void
{
    $https =
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (
            isset($_SERVER['HTTP_X_FORWARDED_PROTO'])
            && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https'
        );

    if ($https) {
        ini_set('session.cookie_secure', '1');
        ini_set('session.cookie_samesite', 'None');
    }
}

function cors_public_api_headers(): void
{
    header('Content-Type: application/json');
    cors_apply([
        'methods' => 'GET, POST, OPTIONS',
        'headers' => 'Content-Type, Accept, X-Requested-With',
    ]);
    if (cors_is_preflight()) {
        cors_send_preflight();
    }
}

function cors_credentials_api_headers(): void
{
    header('Content-Type: application/json');
    cors_apply([
        'credentials' => true,
        'methods' => 'GET, POST, OPTIONS',
    ]);
    cors_configure_session_cookies();
    if (cors_is_preflight()) {
        cors_send_preflight();
    }
}

// --- db.php ---
function db_config(): array
{
    static $cfg = null;
    if (is_array($cfg)) {
        return $cfg;
    }

    $host = getenv('MYSQLHOST') ?: getenv('DB_HOST');
    if (is_string($host) && $host !== '') {
        $cfg = [
            'host' => $host,
            'port' => (string) (getenv('MYSQLPORT') ?: getenv('DB_PORT') ?: '3306'),
            'dbname' => (string) (getenv('MYSQLDATABASE') ?: getenv('DB_NAME') ?: ''),
            'user' => (string) (getenv('MYSQLUSER') ?: getenv('DB_USER') ?: ''),
            'pass' => (string) (getenv('MYSQLPASSWORD') ?: getenv('DB_PASSWORD') ?: ''),
        ];
        return $cfg;
    }

    foreach (['DATABASE_URL', 'MYSQL_URL', 'MYSQL_PUBLIC_URL'] as $envKey) {
        $url = getenv($envKey);
        if (!is_string($url) || $url === '') {
            continue;
        }
        $parsed = db_parse_database_url($url);
        if ($parsed !== null) {
            $cfg = $parsed;
            return $cfg;
        }
    }

    $dbFile = __DIR__ . '/database.php';
    if (is_readable($dbFile)) {
        $local = require $dbFile;
        if (is_array($local) && ($local['host'] ?? '') !== '' && ($local['dbname'] ?? '') !== '') {
            $cfg = [
                'host' => (string) $local['host'],
                'port' => (string) ($local['port'] ?? '3306'),
                'dbname' => (string) $local['dbname'],
                'user' => (string) ($local['user'] ?? ''),
                'pass' => (string) ($local['pass'] ?? $local['password'] ?? ''),
            ];
            return $cfg;
        }
    }

    throw new RuntimeException(
        'Database not configured. On Railway: add MySQL and link variables, or set MYSQL_URL. Locally: copy database.example.php → database.php'
    );
}

function db_parse_database_url(string $url): ?array
{
    $parts = parse_url($url);
    if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'mysql') {
        return null;
    }

    $host = (string) ($parts['host'] ?? '');
    $dbname = isset($parts['path']) ? ltrim((string) $parts['path'], '/') : '';
    if ($host === '' || $dbname === '') {
        return null;
    }

    return [
        'host' => $host,
        'port' => (string) ($parts['port'] ?? '3306'),
        'dbname' => $dbname,
        'user' => (string) (urldecode($parts['user'] ?? '')),
        'pass' => (string) (urldecode($parts['pass'] ?? '')),
    ];
}

function db_dsn(array $cfg): string
{
    $port = isset($cfg['port']) && (string) $cfg['port'] !== '' ? ';port=' . $cfg['port'] : '';
    return sprintf(
        'mysql:host=%s%s;dbname=%s;charset=utf8mb4',
        $cfg['host'],
        $port,
        $cfg['dbname']
    );
}

function db_pdo(): PDO
{
    $cfg = db_config();
    return new PDO(db_dsn($cfg), $cfg['user'], $cfg['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function db_mysqli(): mysqli
{
    $cfg = db_config();
    $port = (int) ($cfg['port'] ?? 3306);
    $conn = new mysqli($cfg['host'], $cfg['user'], $cfg['pass'], $cfg['dbname'], $port);
    if ($conn->connect_error) {
        throw new RuntimeException('DB connection failed: ' . $conn->connect_error);
    }
    $conn->set_charset('utf8mb4');
    return $conn;
}

function db_public_api_base_url(): string
{
    $fromEnv = getenv('PUBLIC_API_BASE_URL') ?: getenv('RAILWAY_PUBLIC_DOMAIN');
    if (is_string($fromEnv) && $fromEnv !== '') {
        $base = $fromEnv;
        if (!preg_match('#^https?://#i', $base)) {
            $base = 'https://' . $base;
        }
        return rtrim($base, '/');
    }

    $scheme = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (
            isset($_SERVER['HTTP_X_FORWARDED_PROTO'])
            && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https'
        )
    ) ? 'https' : 'http';

    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host;
}
