<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
bootstrap_require_lib();
bootstrap_require_db();

http_response_code(200);
header('Content-Type: application/json; charset=utf-8');
cors_apply(['methods' => 'GET, OPTIONS', 'headers' => 'Content-Type']);

$dbOk = false;
$dbError = null;
try {
    db_config();
    $dbOk = true;
} catch (Throwable $e) {
    $dbError = $e->getMessage();
}

echo json_encode([
    'ok' => true,
    'php' => PHP_VERSION,
    'pdo_mysql' => extension_loaded('pdo_mysql'),
    'database_configured' => $dbOk,
    'database_error' => $dbError,
    'database_php_readable' => is_readable(__DIR__ . '/database.php'),
    'contact_php_readable' => is_readable(__DIR__ . '/contact.php'),
    'frontend_origin_env' => getenv('FRONTEND_ORIGIN') ?: null,
], JSON_UNESCAPED_SLASHES);
