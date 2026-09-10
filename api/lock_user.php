<?php

header('Content-Type: application/json');

require_once '../config/database.php';

/* ================= DB CONNECTION ================= */

$conn = getDBConnection();

if (!$conn) {

    http_response_code(500);

    echo json_encode([
        "error" => "Database connection failed"
    ]);

    exit;
}

/* ================= METHOD CHECK ================= */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    http_response_code(405);

    echo json_encode([
        "error" => "Method not allowed"
    ]);

    exit;
}

/* ================= GET JSON ================= */

$user_id = intval($_POST['id'] ?? 0);

if (!$user_id) {

    http_response_code(400);

    echo json_encode([
        "error" => "Missing user ID"
    ]);

    exit;
}

/* ================= LOCK ACCOUNT ================= */

$stmt = $conn->prepare(
    "UPDATE users
     SET locked = 1
     WHERE id = ?"
);

if (!$stmt) {

    http_response_code(500);

    echo json_encode([
        "error" => $conn->error
    ]);

    exit;
}

$stmt->bind_param("i", $user_id);

$stmt->execute();

/* ================= RESPONSE ================= */

if ($stmt->affected_rows > 0) {

    echo json_encode([
        "success" => true,
        "message" => "User locked successfully"
    ]);

} else {

    echo json_encode([
        "success" => false,
        "message" => "No user updated"
    ]);
}

$stmt->close();

$conn->close();