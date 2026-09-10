<?php
header('Content-Type: application/json');
require_once '../config/database.php';
session_start();
$conn = getDBConnection();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// ONLY GM ADMIN CAN ACCESS
if ($_SESSION['role'] !== 'general_manager') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

// ================= GET USERS =================
if ($method === 'GET') {
 $result = $conn->query("SELECT id, username, role, created_at, locked FROM users");

if (!$result) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Query failed',
        'details' => $conn->error
    ]);
    exit;
}

    $data = [];
   while ($row = $result->fetch_assoc()) {

    $row['locked'] = isset($row['locked']) ? (int)$row['locked'] : 0;

    $data[] = $row;
}

    echo json_encode(['data' => $data]);
    exit;
}

// ================= DELETE USER =================
if ($method === 'DELETE') {

    parse_str($_SERVER['QUERY_STRING'], $params);
    $id = intval($params['id'] ?? 0);

    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid ID']);
        exit;
    }

    // prevent deleting main gm_admin
$stmt = $conn->prepare("SELECT username FROM users WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$check = $stmt->get_result()->fetch_assoc();
$stmt->close();

    if (!$check) {
        http_response_code(404);
        echo json_encode(['error' => 'User not found']);
        exit;
    }

    if ($check['username'] === 'general_manager') {
        http_response_code(403);
        echo json_encode(['error' => 'Cannot delete main admin']);
        exit;
    }
$stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$stmt->close();
    echo json_encode(['success' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);