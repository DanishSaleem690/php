<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Karachi');

require_once __DIR__ . '/bootstrap.php';
bootstrap_require_lib();
bootstrap_require_db();

cors_credentials_api_headers();
session_start();

try {
    $pdo = db_pdo();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit();
}

function deleteOutdatedJobs(PDO $pdo): int
{
    $currentDate = date('Y-m-d');
    try {
        $stmt = $pdo->prepare('UPDATE app_dtl SET status = 0 WHERE last_date <= :currentDate AND status = 1');
        $stmt->execute([':currentDate' => $currentDate]);
        return $stmt->rowCount();
    } catch (PDOException $e) {
        error_log('Failed to update outdated jobs: ' . $e->getMessage());
        return 0;
    }
}

$deletedCount = deleteOutdatedJobs($pdo);

echo json_encode([
    'success' => true,
    'message' => "$deletedCount outdated job(s) marked as inactive",
    'count' => $deletedCount,
]);
