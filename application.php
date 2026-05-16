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
    $join = isset($_GET['join']) && $_GET['join'] === 'true';

    if ($join) {
        // Perform the JOIN query
        $sql = "SELECT a.titles AS job_title, last_date, c.* FROM candidate_dtl c LEFT JOIN app_dtl a ON a.pk = c.APP_ID;";
    } else {
        // Default query
        $sql = "SELECT * FROM app_dtl where status = 1";
    }

    $result = $conn->query($sql);
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

http_response_code(405);
echo json_encode(["error" => "Method not allowed"]);
