<?php
header('Content-Type: application/json');

require_once '../config/database.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if ($_SESSION['role'] !== 'general_manager') {
    http_response_code(403);
    echo json_encode(['error' => 'Only GM can unlock accounts']);
    exit;
}

$id = intval($_POST['id'] ?? 0);

if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid crew ID']);
    exit;
}

$conn = getDBConnection();


$stmt = $conn->prepare("
    UPDATE crew
    SET locked_account = 0
    WHERE id = ?
");

if (!$stmt) {

    http_response_code(500);

    echo json_encode([
        'error' => 'Unlock SQL Error: ' . $conn->error
    ]);

    exit;
}

$stmt->bind_param("i", $id);

if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to unlock account']);
    exit;
}

echo json_encode([
    'success' => true
]);
?>