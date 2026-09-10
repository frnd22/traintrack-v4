<?php

header('Content-Type: application/json');

require_once '../config/database.php';
$conn = getDBConnection();

if (!$conn) {
    http_response_code(500);
    echo json_encode(["error" => "Database connection failed"]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["error" => "Method not allowed"]);
    exit;
}

/* ✅ FIX: use POST, not JSON */
$user_id = intval($_POST['id'] ?? 0);

if (!$user_id) {
    http_response_code(400);
    echo json_encode(["error" => "Missing user ID"]);
    exit;
}

$stmt = $conn->prepare(
    "UPDATE users
     SET locked = 0
     WHERE id = ?"
);

if (!$stmt) {
    http_response_code(500);
    echo json_encode(["error" => $conn->error]);
    exit;
}

$stmt->bind_param("i", $user_id);
$stmt->execute();

echo json_encode([
    "success" => true,
    "message" => "User unlocked successfully"
]);

$stmt->close();
$conn->close();