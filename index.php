<?php
declare(strict_types=1);

/**
 * Root URL for the PHP API — browsers opening the Railway domain see this instead of 404.
 * The React app lives on Netlify; this service only serves *.php endpoints.
 */
header('Content-Type: application/json; charset=utf-8');
http_response_code(200);

echo json_encode([
    'ok' => true,
    'service' => 'klash-backend',
    'message' => 'PHP API is running. This is not the website — open your Netlify URL for the frontend.',
    'endpoints' => [
        'health' => '/health.php',
        'diagnostics' => '/contact_health.php',
        'contact' => 'POST /contact.php',
        'login' => 'POST /login.php',
        'jobs' => 'GET /api.php',
    ],
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
