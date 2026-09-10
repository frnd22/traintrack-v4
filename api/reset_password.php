<?php
header('Content-Type: application/json');

error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/../config/database.php';

// IMPORTANT: match login system
$conn = getDBConnection();

$rawInput = file_get_contents("php://input");
$data = json_decode($rawInput, true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(["error" => "Invalid JSON input"]);
    exit;
}

$username = trim($data['username'] ?? '');
$password = trim($data['password'] ?? '');

if ($username === '' || $password === '') {
    http_response_code(400);
    echo json_encode(["error" => "Missing fields"]);
    exit;
}

$hashed = password_hash($password, PASSWORD_DEFAULT);

/* ================= USERS ================= */
/* ================= USERS ================= */

$stmt = $conn->prepare("
    UPDATE users
    SET password = ?,
        locked = 1
    WHERE username = ?
");

if (!$stmt) {

    http_response_code(500);

    echo json_encode([
        'error' => 'Users SQL Error: ' . $conn->error
    ]);

    exit;
}

$stmt->bind_param("ss", $hashed, $username);

$stmt->execute();

if ($stmt->affected_rows > 0) {
    echo json_encode([
        "success" => true,
        "message" => "Password updated (users)"
    ]);
    exit;
}
$stmt->close();

/* ================= CREW ================= */
$stmt = $conn->prepare("UPDATE crew SET password = ?, locked_account = 1 WHERE username = ?");

if (!$stmt) {

    http_response_code(500);

    echo json_encode([
        'error' => 'SQL Error: ' . $conn->error
    ]);

    exit;
}
$stmt->bind_param("ss", $hashed, $username);
$stmt->execute();

if ($stmt->affected_rows > 0) {
    echo json_encode([
        "success" => true,
        "message" => "Password updated (crew)"
    ]);
    exit;
}
$stmt->close();

/* ================= NOT FOUND ================= */
http_response_code(404);
echo json_encode([
    "error" => "Username not found"
]);