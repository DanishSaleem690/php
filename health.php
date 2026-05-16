<?php
declare(strict_types=1);

/**
 * Railway liveness probe — no DB, no includes. Must return 200 quickly.
 */
http_response_code(200);
header('Content-Type: application/json; charset=utf-8');
echo '{"ok":true,"service":"backend"}';
