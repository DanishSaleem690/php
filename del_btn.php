<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
bootstrap_require_lib();
bootstrap_require_db();

cors_public_api_headers();

try {
    $pdo = db_pdo();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit();
}

try {
    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['PK'])) {
        echo json_encode(['error' => "Missing 'PK' in request body"]);
        http_response_code(400);
        exit();
    }

    $PK = (int) $data['PK'];
    $status = isset($data['status']) ? (int) $data['status'] : 0;

    $stmt = $pdo->prepare('UPDATE app_dtl SET status = :status WHERE PK = :PK');
    $stmt->bindParam(':status', $status, PDO::PARAM_INT);
    $stmt->bindParam(':PK', $PK, PDO::PARAM_INT);

    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Record updated successfully.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update record.']);
    }
} catch (PDOException $e) {
    echo json_encode(['error' => $e->getMessage()]);
    http_response_code(500);
}
