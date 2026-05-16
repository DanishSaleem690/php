<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
bootstrap_require_lib();
bootstrap_require_db();

cors_apply([
    'methods' => 'POST, OPTIONS',
    'headers' => 'Content-Type, X-Requested-With',
]);
header('Content-Type: application/json');

if (cors_is_preflight()) {
    cors_send_preflight();
}

$uploadDir = __DIR__ . '/upload/';
$uploadUrl = db_public_api_base_url() . '/upload/';

if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

if (isset($_FILES['cv'])) {
    $file = $_FILES['cv'];
    $filename = uniqid() . '_' . basename($file['name']);
    $destination = $uploadDir . $filename;

    if (move_uploaded_file($file['tmp_name'], $destination)) {
        echo json_encode([
            'success' => true,
            'file' => $uploadUrl . $filename,
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'Upload failed',
        ]);
    }
} else {
    echo json_encode([
        'success' => false,
        'error' => 'No file uploaded',
    ]);
}
