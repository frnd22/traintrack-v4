<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

require_once '../config/database.php';
session_start();

// 🔐 GM ONLY ACCESS
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'general_manager') {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

// READ JSON BODY (IMPORTANT FIX)
$data = json_decode(file_get_contents("php://input"), true);

$username = trim($data['username'] ?? '');
$password = $data['password'] ?? '';
$role     = $data['role'] ?? '';

$allowedRoles = ['training_manager', 'manager_on_duty', 'general_manager'];

if (!$username || !$password || !$role) {
    echo json_encode(['error' => 'All fields required']);
    exit;
}

if (!in_array($role, $allowedRoles)) {
    echo json_encode(['error' => 'Invalid role']);
    exit;
}

$conn = getDBConnection();

$hashedPassword = password_hash($password, PASSWORD_DEFAULT);

$stmt = $conn->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, ?)");
$stmt->bind_param("sss", $username, $hashedPassword, $role);

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'message' => 'User added successfully']);
} else {
    echo json_encode(['error' => 'Username already exists']);
}

$stmt->close();
$conn->close();