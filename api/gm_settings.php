<?php

header('Content-Type: application/json');

require_once '../config/database.php';

$conn = getDBConnection();

/* ═══════════════════════════════════════════════════════
   ONLY POST METHOD (SECURITY)
═══════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'PUT') {
    http_response_code(405);
    echo json_encode(["error" => "Method not allowed"]);
    exit;
}

/* ═══════════════════════════════════════════════════════
   INPUT DATA
═══════════════════════════════════════════════════════ */
$data = json_decode(file_get_contents("php://input"), true);

$new_username = trim($data['new_username'] ?? '');
$old_password = $data['old_password'] ?? '';
$new_password = $data['new_password'] ?? '';

/* ═══════════════════════════════════════════════════════
   SESSION (ADJUST IF YOU USE SESSION SYSTEM)
═══════════════════════════════════════════════════════ */
session_start();

$user_id = $_SESSION['user_id'] ?? null;
$role    = $_SESSION['role'] ?? null;

/* ONLY GENERAL MANAGER ALLOWED */
if (!$user_id || $role !== 'general_manager') {
    http_response_code(403);
    echo json_encode(["error" => "Access denied"]);
    exit;
}

/* ═══════════════════════════════════════════════════════
   GET CURRENT USER DATA
═══════════════════════════════════════════════════════ */
$stmt = $conn->prepare("SELECT username, password FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();

$result = $stmt->get_result();
$user = $result->fetch_assoc();

if (!$user) {
    http_response_code(404);
    echo json_encode(["error" => "User not found"]);
    exit;
}

/* ═══════════════════════════════════════════════════════
   PASSWORD VERIFY (ONLY IF CHANGING PASSWORD)
═══════════════════════════════════════════════════════ */
if (!empty($new_password)) {

    if (empty($old_password)) {
        http_response_code(400);
        echo json_encode(["error" => "Old password required"]);
        exit;
    }

    if (!password_verify($old_password, $user['password'])) {
        http_response_code(401);
        echo json_encode(["error" => "Old password incorrect"]);
        exit;
    }

    $hashed_password = password_hash($new_password, PASSWORD_BCRYPT);
} else {
    $hashed_password = $user['password'];
}

/* ═══════════════════════════════════════════════════════
   BUILD UPDATE QUERY DYNAMICALLY
═══════════════════════════════════════════════════════ */
$fields = [];
$params = [];
$types  = "";

/* UPDATE USERNAME */
if (!empty($new_username)) {
    $fields[] = "username = ?";
    $params[] = $new_username;
    $types .= "s";
}

/* UPDATE PASSWORD */
if (!empty($new_password)) {
    $fields[] = "password = ?";
    $params[] = $hashed_password;
    $types .= "s";
}

/* NOTHING TO UPDATE */
if (empty($fields)) {
    echo json_encode(["message" => "Nothing to update"]);
    exit;
}

/* ADD USER ID */
$params[] = $user_id;
$types .= "i";

$sql = "UPDATE users SET " . implode(", ", $fields) . " WHERE id = ?";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);

if ($stmt->execute()) {

    /* UPDATE SESSION IF USERNAME CHANGED */
    if (!empty($new_username)) {
        $_SESSION['username'] = $new_username;
    }

    echo json_encode([
        "success" => true,
        "message" => "Settings updated successfully"
    ]);

} else {
    http_response_code(500);
    echo json_encode(["error" => "Update failed"]);
}