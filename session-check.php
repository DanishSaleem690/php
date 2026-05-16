<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
bootstrap_require_lib();

$timeout_duration = 120;

cors_credentials_api_headers();
session_start();

if (isset($_SESSION['last_activity'])) {
    $inactive_time = time() - $_SESSION['last_activity'];
    if ($inactive_time > $timeout_duration) {
        session_unset();
        session_destroy();
        echo json_encode(['loggedIn' => false, 'reason' => 'Session expired']);
        exit();
    }
}

if (isset($_SESSION['user_id'])) {
    $_SESSION['last_activity'] = time();
    echo json_encode(['loggedIn' => true]);
    exit();
}

echo json_encode(['loggedIn' => false]);
