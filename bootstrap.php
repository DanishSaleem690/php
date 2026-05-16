<?php
declare(strict_types=1);

/**
 * Load CORS + DB helpers from lib/ or single-file lib_bundle.php (GitHub upload friendly).
 */
function bootstrap_require_lib(): void
{
    if (is_readable(__DIR__ . '/lib/cors.php')) {
        require_once __DIR__ . '/lib/cors.php';
        if (is_readable(__DIR__ . '/lib/db.php')) {
            require_once __DIR__ . '/lib/db.php';
        }
        return;
    }

    if (is_readable(__DIR__ . '/lib_bundle.php')) {
        require_once __DIR__ . '/lib_bundle.php';
        return;
    }

    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin !== '') {
        $host = parse_url($origin, PHP_URL_HOST);
        if (is_string($host) && $host !== '') {
            $host = strtolower($host);
            if ($host === 'netlify.app' || str_ends_with($host, '.netlify.app')) {
                header('Access-Control-Allow-Origin: ' . $origin);
                header('Vary: Origin');
                header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
                header('Access-Control-Allow-Headers: Content-Type, Accept, Authorization, X-Requested-With');
            }
        }
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }

    header('Content-Type: application/json; charset=utf-8');
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'message' => 'API misconfigured: upload lib/cors.php + lib/db.php OR lib_bundle.php, then redeploy.',
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

/** @internal */
function bootstrap_require_db(): void
{
    if (function_exists('db_config')) {
        return;
    }
    if (is_readable(__DIR__ . '/lib/db.php')) {
        require_once __DIR__ . '/lib/db.php';
        return;
    }
    if (is_readable(__DIR__ . '/lib_bundle.php')) {
        require_once __DIR__ . '/lib_bundle.php';
    }
}
