<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Catch ALL PHP errors and return them as JSON — never HTML
set_error_handler(function($errno, $errstr) {
    http_response_code(500);
    echo json_encode(['error' => "PHP Error: $errstr"]);
    exit;
});
set_exception_handler(function($e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server exception: ' . $e->getMessage()]);
    exit;
});

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

require_once '../config/database.php';
session_start(); // ✅ ADD THIS LINE

$method = $_SERVER['REQUEST_METHOD'];
$conn   = getDBConnection();

// ID from ?id= query param (most reliable) or URL path
$id = null;
if (!empty($_GET['id']) && is_numeric($_GET['id'])) {
    $id = intval($_GET['id']);
} else {
    $parts = explode('/', trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/'));
    foreach ($parts as $i => $part) {
        if (in_array($part, ['crew.php','crew']) && isset($parts[$i+1]) && is_numeric($parts[$i+1])) {
            $id = intval($parts[$i+1]);
            break;
        }
    }
}

switch ($method) {
    case 'GET':    $id ? getCrewById($conn, $id) : getAllCrew($conn); break;
    case 'POST':   registerCrew($conn);   break;
    case 'PUT':    updateCrew($conn, $id); break;
    
    case 'DELETE':

    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }

    if ($_SESSION['role'] !== 'general_manager') {
        http_response_code(403);
        echo json_encode(['error' => 'Only GM Admin can permanently delete crew']);
        exit;
    }

    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'Crew ID is required']);
        exit;
    }

    // ❗ NO training record check here — CASCADE handles it

    deleteCrew($conn, $id);
    break;

    

    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
}
$conn->close();

// ── READ ──────────────────────────────────────────────────

function getAllCrew($conn) {

    $result = $conn->query(
        "SELECT 
            c.id,
            c.name,
            c.birthday,
            c.date_hired,
            c.username,
            c.is_active,
            c.locked_account,
            c.created_at,

            COUNT(DISTINCT tr.id) AS total_records,

            SUM(
                CASE
                    WHEN tr.initial_status = 'Pass'
                    THEN 1
                    ELSE 0
                END
            ) AS stations_passed

         FROM crew c

         LEFT JOIN training_records tr
            ON c.id = tr.crew_id

         GROUP BY
            c.id,
            c.name,
            c.birthday,
            c.date_hired,
            c.username,
            c.is_active,
            c.locked_account,
            c.created_at

         ORDER BY c.name"
    );

    if (!$result) {
        http_response_code(500);

        echo json_encode([
            'error' => 'SQL Error: ' . $conn->error
        ]);

        return;
    }

    $crew = [];

    while ($row = $result->fetch_assoc()) {
        $crew[] = $row;
    }

    echo json_encode([
        'success' => true,
        'data' => $crew
    ]);
}
function getCrewById($conn, $id) {
    $stmt = $conn->prepare(
        "SELECT id, name, birthday, date_hired, username, is_active, locked_account, created_at FROM crew WHERE id = ?"
    );
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) echo json_encode(['success' => true, 'data' => $row]);
    else { http_response_code(404); echo json_encode(['error' => 'Crew member not found']); }
}

// ── REGISTER ──────────────────────────────────────────────

function registerCrew($conn) {
    $input      = json_decode(file_get_contents('php://input'), true);
    $name       = trim($input['name']             ?? '');
    $birthday   = trim($input['birthday']         ?? '');
    $date_hired = trim($input['date_hired']        ?? '');
    $username   = trim($input['username']         ?? '');
    $password   = $input['password']              ?? '';
    $confirm    = $input['confirm_password']      ?? '';

    // ── Validation ────────────────────────────────────────
    if (empty($name) || empty($birthday) || empty($date_hired) || empty($username) || empty($password)) {
        http_response_code(400);
        echo json_encode(['error' => 'All fields are required']);
        return;
    }

    // Username must not contain spaces
    if (strpos($username, ' ') !== false) {
        http_response_code(400);
        echo json_encode(['error' => 'Username cannot contain spaces']);
        return;
    }

    // Username: alphanumeric + underscore only
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        http_response_code(400);
        echo json_encode(['error' => 'Username can only contain letters, numbers, and underscores']);
        return;
    }

    if ($password !== $confirm) {
        http_response_code(400);
        echo json_encode(['error' => 'Passwords do not match']);
        return;
    }

    if (strlen($password) < 6) {
        http_response_code(400);
        echo json_encode(['error' => 'Password must be at least 6 characters']);
        return;
    }

    // ── Check username uniqueness ─────────────────────────
    // Check in crew table
    $chk = $conn->prepare("SELECT id FROM crew WHERE username = ?");
    $chk->bind_param("s", $username);
    $chk->execute();
    if ($chk->get_result()->num_rows > 0) {
        http_response_code(409);
        echo json_encode(['error' => 'Username already exists. Please choose a different one.']);
        $chk->close();
        return;
    }
    $chk->close();

    // Also check in users (manager) table to avoid conflicts
    $chk2 = $conn->prepare("SELECT id FROM users WHERE username = ?");
    $chk2->bind_param("s", $username);
    $chk2->execute();
    if ($chk2->get_result()->num_rows > 0) {
        http_response_code(409);
        echo json_encode(['error' => 'Username already taken. Please choose a different one.']);
        $chk2->close();
        return;
    }
    $chk2->close();

    // ── Insert crew ───────────────────────────────────────
    $hashed = password_hash($password, PASSWORD_BCRYPT);
    $stmt   = $conn->prepare(
        "INSERT INTO crew (name, birthday, date_hired, username, password) VALUES (?, ?, ?, ?, ?)"
    );
    $stmt->bind_param("sssss", $name, $birthday, $date_hired, $username, $hashed);

    if ($stmt->execute()) {

    $crew_id = $conn->insert_id;

    $stmt->close();

    // DO NOT create training records here
    // Crew account only

    http_response_code(201);

    echo json_encode([
        'success' => true,
        'message' => 'Crew account created successfully',
        'crew_id' => $crew_id
    ]);

} else {

    $stmt->close();

    http_response_code(500);

    echo json_encode([
        'error' => 'Registration failed: ' . $conn->error
    ]);
}
}

// ── UPDATE ────────────────────────────────────────────────

function updateCrew($conn, $id) {
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'ID required']); return; }
    $input     = json_decode(file_get_contents('php://input'), true);
    $name      = trim($input['name']      ?? '');
    $is_active = isset($input['is_active']) ? intval($input['is_active']) : 1;

    $stmt = $conn->prepare("UPDATE crew SET name=?, is_active=? WHERE id=?");
    $stmt->bind_param("sii", $name, $is_active, $id);
    if ($stmt->execute()) echo json_encode(['success' => true, 'message' => 'Crew updated']);
    else { http_response_code(500); echo json_encode(['error' => 'Update failed: ' . $conn->error]); }
    $stmt->close();
}

// ── DELETE ────────────────────────────────────────────────

function deleteCrew($conn, $id) {
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'ID required']); return; }
    $stmt = $conn->prepare("DELETE FROM crew WHERE id=?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) echo json_encode(['success' => true, 'message' => 'Crew member deleted']);
    else { http_response_code(500); echo json_encode(['error' => 'Delete failed: ' . $conn->error]); }
    $stmt->close();
}

