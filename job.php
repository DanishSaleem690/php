<?php
require_once __DIR__ . '/bootstrap.php';
bootstrap_require_lib();
bootstrap_require_db();

cors_public_api_headers();

try {
    $conn = db_mysqli();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'DB connection failed',
        'details' => $e->getMessage(),
    ]);
    exit();
}

// === 5. Handle POST Request ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawData = file_get_contents("php://input");

    // Optional: Debug raw input (uncomment for troubleshooting)
     file_put_contents("debug_job_raw_input.json", $rawData);

    $data = json_decode($rawData, true);
    if (!$data || !is_array($data)) {
        http_response_code(400);
        echo json_encode(["error" => "Invalid JSON"]);
        exit();
    }

    // === Extract & Sanitize Inputs ===
    $titles      = trim($data['jobName'] ?? '');
    $dep         = trim($data['depName'] ?? '');
    $description = trim($data['description'] ?? '');
    $last_date_raw = trim($data['last_date'] ?? '');
    $status = 1;

    // === Validate Required Fields ===
    if (empty($titles) || empty($dep) || empty($description) || empty($last_date_raw)) {
        http_response_code(400);
        echo json_encode(["error" => "All fields are required."]);
        exit();
    }

    // === Validate Date Format ===
    $dateObj = DateTime::createFromFormat('Y-m-d', $last_date_raw);
    if (!$dateObj || $dateObj->format('Y-m-d') !== $last_date_raw) {
        http_response_code(400);
        echo json_encode(["error" => "Invalid date format. Expected YYYY-MM-DD."]);
        exit();
    }

    $last_date = $dateObj->format('Y-m-d');

    // === Use Prepared Statement for Insert ===
    $stmt = $conn->prepare("
        INSERT INTO app_dtl (titles, dep, description, last_date, status, created_date) 
        VALUES (?, ?, ?, ?, ?, NOW())
    ");

    if (!$stmt) {
        http_response_code(500);
        echo json_encode([
            "status" => "error",
            "message" => "Failed to prepare statement",
            "mysql_error" => $conn->error
        ]);
        $conn->close();
        exit();
    }

    $stmt->bind_param("ssssi", $titles, $dep, $description, $last_date, $status);

    if ($stmt->execute()) {
        echo json_encode(["status" => "success", "message" => "Job inserted successfully"]);
    } else {
        http_response_code(500);
        echo json_encode([
            "status" => "error",
            "message" => "Database insert failed",
            "mysql_error" => $stmt->error
        ]);
    }

    $stmt->close();
    $conn->close();
    exit();
}

// === 6. Method Not Allowed ===
http_response_code(405);
echo json_encode(["error" => "Method not allowed"]);
