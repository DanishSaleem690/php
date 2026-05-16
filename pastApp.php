<?php
require_once __DIR__ . '/bootstrap.php';
bootstrap_require_lib();
bootstrap_require_db();

cors_public_api_headers();

try {
    $conn = db_mysqli();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB connection failed', 'details' => $e->getMessage()]);
    exit();
}

// === 5. Handle GET ===
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $result = $conn->query("SELECT * FROM app_dtl where status = 0");
    $data = [];

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
        echo json_encode($data);
    } else {
        http_response_code(500);
        echo json_encode(["error" => "Query failed", "details" => $conn->error]);
    }

    $conn->close();
    exit();
}



// === 7. Fallback for unsupported methods ===
http_response_code(405);
echo json_encode(["error" => "Method not allowed"]);

