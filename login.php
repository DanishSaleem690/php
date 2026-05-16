<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
bootstrap_require_lib();
bootstrap_require_db();

cors_apply([
    'credentials' => true,
    'methods' => 'POST, OPTIONS',
]);
cors_configure_session_cookies();
header('Content-Type: application/json');

if (cors_is_preflight()) {
    cors_send_preflight();
}

session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

$raw = file_get_contents('php://input');
$data = json_decode($raw !== false && $raw !== '' ? $raw : '{}', true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON body']);
    exit();
}

$email = trim((string) ($data['email'] ?? ''));
$password = (string) ($data['password'] ?? '');

try {
    $pdo = db_pdo();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'DB connection failed',
        'message' => 'Server could not connect to the database.',
    ]);
    exit();
}

$stmt = $pdo->prepare('SELECT id, password FROM users WHERE email = ? LIMIT 1');
$stmt->execute([$email]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

$hash = is_array($row) ? (string) ($row['password'] ?? '') : '';
$ok = $hash !== '' && password_verify($password, $hash);

if ($ok) {
    $_SESSION['user_id'] = (int) $row['id'];
    echo json_encode(['success' => true]);
    exit();
}

http_response_code(401);
echo json_encode(['success' => false, 'message' => 'Invalid credentials']);
