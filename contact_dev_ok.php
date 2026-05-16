<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
bootstrap_require_lib();

header('Content-Type: application/json; charset=utf-8');
cors_apply(['methods' => 'GET, OPTIONS', 'headers' => 'Content-Type']);

echo json_encode([
    'ok' => true,
    'hint' => 'PHP API is reachable. Document root should be the backend/ folder.',
], JSON_UNESCAPED_SLASHES);
