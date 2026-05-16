<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
bootstrap_require_lib();

cors_credentials_api_headers();
session_start();
session_unset();
session_destroy();

echo json_encode(['success' => true]);
