<?php

header('Content-Type: application/json');

require_once '../config/database.php';
$conn = getDBConnection();

session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode([
        'error' => 'Unauthorized'
    ]);
    exit;
}

if ($_SESSION['role'] !== 'crew') {
    http_response_code(403);
    echo json_encode([
        'error' => 'Crew access only'
    ]);
    exit;
}

$crewId = $_SESSION['crew_id'];

$method = $_SERVER['REQUEST_METHOD'];

try {

    // =========================================
    // GET PROFILE
    // =========================================

    if ($method === 'GET') {

        $stmt = $conn->prepare("
            SELECT
                id,
                name,
                birthday,
                date_hired,
                username
            FROM crew
            WHERE id = ?
        ");

        $stmt->bind_param("i", $crewId);

        $stmt->execute();

        $result = $stmt->get_result();
$crew = $result->fetch_assoc();

if (!$crew) {
    http_response_code(404);
    echo json_encode([
        'error' => 'Crew profile not found'
    ]);
    exit;
}

echo json_encode([
    'data' => $crew
]);

        exit;
    }

    // =========================================
    // UPDATE PROFILE
    // =========================================

    if ($method === 'PUT') {

        $input = json_decode(file_get_contents('php://input'), true);

        $birthday = trim($input['birthday'] ?? '');
        $date_hired = trim($input['date_hired'] ?? '');

        $old_username = trim($input['old_username'] ?? '');
        $new_username = trim($input['new_username'] ?? '');

        $old_password = trim($input['old_password'] ?? '');
        $new_password = trim($input['new_password'] ?? '');

        // GET CURRENT DATA

        $stmt = $conn->prepare("
            SELECT username, password
            FROM crew
            WHERE id = ?
        ");

        $stmt->bind_param("i", $crewId);

        $stmt->execute();

        $current = $stmt->get_result()->fetch_assoc();

        $finalUsername = $current['username'];
        $finalPassword = $current['password'];

        // =====================================
        // USERNAME VALIDATION
        // =====================================

        if (!empty($new_username)) {

            if ($old_username !== $current['username']) {

                throw new Exception('Old username is incorrect');
            }

            if (!preg_match('/^[a-zA-Z0-9_]+$/', $new_username)) {

                throw new Exception(
                    'Username must contain only letters, numbers and underscores'
                );
            }

            $check = $conn->prepare("
                SELECT id
                FROM crew
                WHERE username = ?
                AND id != ?
            ");

            $check->bind_param(
                "si",
                $new_username,
                $crewId
            );

            $check->execute();

            if ($check->get_result()->num_rows > 0) {

                throw new Exception('Username already exists');
            }

            $finalUsername = $new_username;
        }

        // =====================================
        // PASSWORD VALIDATION
        // =====================================

        if (!empty($new_password)) {

            if (!password_verify(
                $old_password,
                $current['password']
            )) {

                throw new Exception('Old password is incorrect');
            }

            $finalPassword =
                password_hash($new_password, PASSWORD_DEFAULT);
        }

        // =====================================
        // UPDATE
        // =====================================

        $update = $conn->prepare("
            UPDATE crew
            SET
                birthday = ?,
                date_hired = ?,
                username = ?,
                password = ?
            WHERE id = ?
        ");

        $update->bind_param(
            "ssssi",
            $birthday,
            $date_hired,
            $finalUsername,
            $finalPassword,
            $crewId
        );

        $update->execute();

        echo json_encode([
            'success' => true
        ]);

        exit;
    }

    http_response_code(405);

    echo json_encode([
        'error' => 'Method not allowed'
    ]);

} catch (Exception $e) {

    http_response_code(400);

    echo json_encode([
        'error' => $e->getMessage()
    ]);
}