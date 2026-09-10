<?php 
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// ✅ MUST check method first
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

require_once '../config/database.php';
session_start();

// ✅ Safely decode JSON
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

// Validate JSON
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON input']);
    exit;
}

$username = trim($input['username'] ?? '');
$password = $input['password'] ?? '';

if ($username === '' || $password === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Username and password required']);
    exit;
}

$conn = getDBConnection();
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (!$conn) {
    http_response_code(500);

    echo json_encode([
        'error' => 'Database connection failed'
    ]);

    exit;
}
/* =========================
   CHECK MANAGER ACCOUNTS
========================= */
$stmt = $conn->prepare("SELECT id, username, password, role, locked FROM users WHERE username = ?");

if (!$stmt) {

    http_response_code(500);

    echo json_encode([
        'error' => 'Users query failed: ' . $conn->error
    ]);

    exit;
}

$stmt->bind_param("s", $username);

$stmt->execute();

$result = $stmt->get_result();

$user = $result->fetch_assoc();

/* =========================
   USERS LOCK CHECK (SAFE)
========================= */
if ($user && password_verify($password, $user['password'])) {

    // SAFE DEFAULT
    $isLocked = isset($user['locked']) ? (int)$user['locked'] : 0;

    // ACCOUNT LOCK CHECK
    if ($isLocked === 1) {

        http_response_code(403);

        echo json_encode([
            'error' => 'Account locked. Please contact the General Manager.',
            'locked' => 1
        ]);

        exit;
    }

    // CREATE SESSION
    $_SESSION['user_id']  = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role']     = $user['role'];
    $_SESSION['type']     = 'manager';

    // SUCCESS RESPONSE
    echo json_encode([
        'success'  => true,
        'role'     => $user['role'],
        'username' => $user['username'],
        'type'     => 'manager',
        'locked'   => $isLocked,
        'redirect' => getRoleRedirect($user['role'])
    ]);

    exit;
}

$stmt->close();

/* =========================
   CHECK CREW ACCOUNTS
========================= */
$stmt = $conn->prepare("SELECT id, name, username, password, locked_account FROM crew WHERE username = ?");
if (!$stmt) {
    http_response_code(500);

    echo json_encode([
        'error' => $conn->error
    ]);

    exit;
}
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();
$crew = $result->fetch_assoc();

/* =========================
   CREW LOCK CHECK (SAFE)
========================= */
if ($crew) {

    if ((int)$crew['locked_account'] === 1) {

        http_response_code(403);

        echo json_encode([
            'error' => 'Account locked. Please contact the General Manager.',
            'locked_account' => 1
        ]);

        exit;
    }

    if (password_verify($password, $crew['password'])) {

        $_SESSION['user_id'] = $crew['id'];
        $_SESSION['username'] = $crew['username'];
        $_SESSION['role'] = 'crew';
        $_SESSION['type'] = 'crew';
        $_SESSION['crew_id'] = $crew['id'];

        echo json_encode([
    'success' => true,
    'role' => 'crew',
    'username' => $crew['username'],
    'name' => $crew['name'],
    'type' => 'crew',
    'crew_id' => $crew['id'],
    'locked_account' => (int)$crew['locked_account'] // ✅ ADD THIS LINE
]);
        $stmt->close();
        $conn->close();
        exit;
    }
}

$stmt->close();
$conn->close();

/* =========================
   INVALID LOGIN
========================= */
http_response_code(401);
echo json_encode(['error' => 'Invalid username or password']);

function getRoleRedirect($role) {
    switch ($role) {
        case 'general_manager': return 'dashboard.html';
        case 'training_manager': return 'training_manager.html';
        case 'manager_on_duty': return 'manager_update.html';
        default: return 'login.html';
    }
}
?>
