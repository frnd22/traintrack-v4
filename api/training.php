<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

require_once '../config/database.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

$manager_id = (int)$_SESSION['user_id'];

$method = $_SERVER['REQUEST_METHOD'];
$conn   = getDBConnection();

// ── ID resolution: check query param FIRST, then URL path ──
$id = null;
if (!empty($_GET['id']) && is_numeric($_GET['id'])) {
    $id = intval($_GET['id']);
} else {
    // fallback: /training.php/123  or  /training/123
    $pathParts = explode('/', trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/'));
    foreach ($pathParts as $i => $part) {
        if (in_array($part, ['training.php','training']) && isset($pathParts[$i+1]) && is_numeric($pathParts[$i+1])) {
            $id = intval($pathParts[$i+1]);
            break;
        }
    }
}

// ── Query params ──
$status  = $_GET['status']  ?? null;   // pending | overdue
$crew_id = !empty($_GET['crew_id']) ? intval($_GET['crew_id']) : null;

switch ($method) {

    case 'GET':

        if ($status === 'archived') {
            getArchivedTrainings($conn);
            break;
        }

        if ($status === 'pending') {
            getPendingTrainings($conn);
        }
        elseif ($status === 'overdue') {
            getOverdueTrainings($conn);
        }
        elseif ($id) {
            getTrainingById($conn, $id);
        }
        else {
            getAllTrainings($conn, $crew_id);
        }

        break;

    case 'POST':

        if (isset($_GET['restore_id'])) {
            restoreTraining($conn, intval($_GET['restore_id']));
        } else {
            createTraining($conn);
        }

        break;

    case 'PUT':
        updateTraining($conn, $id);
        break;

    case 'DELETE':
        deleteTraining($conn, $id);
        break;

    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
}
$conn->close();

// ── Helpers ───────────────────────────────────────────────

function calcSchedule($date) {
    if (!$date) return [];
    $base = strtotime($date);
    $s = [];
    for ($i = 1; $i <= 5; $i++) {
        $s["followup$i"] = date('Y-m-d', strtotime("+".($i*3)." months", $base));
    }
    return $s;
}

function enrich($row) {
    $row['followup_schedule'] = calcSchedule($row['initial_training_date']);

    $row['verifiers'] = [
        'initial' => $row['initial_verified_by_name'] ?? null,
        'followup1' => $row['f1_verified_by_name'] ?? null,
        'followup2' => $row['f2_verified_by_name'] ?? null,
        'followup3' => $row['f3_verified_by_name'] ?? null,
        'followup4' => $row['f4_verified_by_name'] ?? null,
        'followup5' => $row['f5_verified_by_name'] ?? null,
    ];

    return $row;
}

// ── READ ──────────────────────────────────────────────────
function getAllTrainings($conn, $crew_id = null) {

    $sql = "SELECT tr.*, 
        c.name AS crew_name, 
        s.station_name,

        u0.username AS initial_verified_by_name,
        u1.username AS f1_verified_by_name,
        u2.username AS f2_verified_by_name,
        u3.username AS f3_verified_by_name,
        u4.username AS f4_verified_by_name,
        u5.username AS f5_verified_by_name

        FROM training_records tr
        JOIN crew c ON tr.crew_id = c.id
        JOIN stations s ON tr.station_id = s.id

        LEFT JOIN users u0 ON tr.initial_verified_by = u0.id
        LEFT JOIN users u1 ON tr.followup1_verified_by = u1.id
        LEFT JOIN users u2 ON tr.followup2_verified_by = u2.id
        LEFT JOIN users u3 ON tr.followup3_verified_by = u3.id
        LEFT JOIN users u4 ON tr.followup4_verified_by = u4.id
        LEFT JOIN users u5 ON tr.followup5_verified_by = u5.id";

    if ($crew_id) {
        $sql .= " WHERE tr.crew_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $crew_id);
    } else {
        $stmt = $conn->prepare($sql);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    echo json_encode(['success' => true, 'data' => $rows]);

    $stmt->close();
}
function getTrainingById($conn, $id) {

    $user_role   = $_SESSION['role'] ?? null;
    $user_crew_id = $_SESSION['crew_id'] ?? null;

    $sql = "SELECT tr.*, 
    c.name AS crew_name, 
    c.date_hired, 
    s.station_name,

    u0.username AS initial_verified_by_name,
    u1.username AS f1_verified_by_name,
    u2.username AS f2_verified_by_name,
    u3.username AS f3_verified_by_name,
    u4.username AS f4_verified_by_name,
    u5.username AS f5_verified_by_name

    FROM training_records tr
    JOIN crew c ON tr.crew_id = c.id
    JOIN stations s ON tr.station_id = s.id

    LEFT JOIN users u0 ON tr.initial_verified_by = u0.id
    LEFT JOIN users u1 ON tr.followup1_verified_by = u1.id
    LEFT JOIN users u2 ON tr.followup2_verified_by = u2.id
    LEFT JOIN users u3 ON tr.followup3_verified_by = u3.id
    LEFT JOIN users u4 ON tr.followup4_verified_by = u4.id
    LEFT JOIN users u5 ON tr.followup5_verified_by = u5.id

    WHERE tr.id = ?";

    // 🔒 Restrict if crew user
    if ($user_role === 'crew') {
        $sql .= " AND tr.crew_id = ?";
    }

    $stmt = $conn->prepare($sql);

    if ($user_role === 'crew') {
        $stmt->bind_param("ii", $id, $user_crew_id);
    } else {
        $stmt->bind_param("i", $id);
    }

    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) {
        echo json_encode(['success' => true, 'data' => enrich($row)]);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Not found']);
    }
}


function getPendingTrainings($conn) {
    $today  = date('Y-m-d');
    $result = $conn->query(
        "SELECT tr.*, c.name AS crew_name, s.station_name
         FROM training_records tr
         JOIN crew c ON tr.crew_id = c.id
         JOIN stations s ON tr.station_id = s.id
         WHERE tr.initial_training_date IS NOT NULL
         ORDER BY c.name, s.station_name"
    );
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $base = strtotime($row['initial_training_date']);
        for ($i = 1; $i <= 5; $i++) {
            $due    = date('Y-m-d', strtotime("+".($i*3)." months", $base));
            $status = $row["followup$i"];
            if ($status === 'Pass' || $status === 'Fail') continue;
            if ($due >= $today) {
                $rows[] = [
                    'training_id'     => $row['id'],
                    'crew_name'       => $row['crew_name'],
                    'station_name'    => $row['station_name'],
                    'followup_number' => $i,
                    'due_date'        => $due,
                    'current_status'  => $status,
                    'days_until_due'  => (int)((strtotime($due) - strtotime($today)) / 86400)
                ];
            }
        }
    }
    echo json_encode(['success' => true, 'data' => $rows, 'count' => count($rows)]);
}

function getOverdueTrainings($conn) {
    $today  = date('Y-m-d');
    $result = $conn->query(
        "SELECT tr.*, c.name AS crew_name, s.station_name
         FROM training_records tr
         JOIN crew c ON tr.crew_id = c.id
         JOIN stations s ON tr.station_id = s.id
         WHERE tr.initial_training_date IS NOT NULL
         ORDER BY c.name, s.station_name"
    );
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $base = strtotime($row['initial_training_date']);
        for ($i = 1; $i <= 5; $i++) {
            $due    = date('Y-m-d', strtotime("+".($i*3)." months", $base));
            $status = $row["followup$i"];
            if ($status === 'Pass' || $status === 'Fail') continue;
            if ($due < $today) {
                $rows[] = [
                    'training_id'     => $row['id'],
                    'crew_name'       => $row['crew_name'],
                    'station_name'    => $row['station_name'],
                    'followup_number' => $i,
                    'due_date'        => $due,
                    'current_status'  => $status,
                    'days_overdue'    => (int)((strtotime($today) - strtotime($due)) / 86400)
                ];
            }
        }
    }
    echo json_encode(['success' => true, 'data' => $rows, 'count' => count($rows)]);
}
function getArchivedTrainings($conn) {
    $sql = "SELECT * FROM training_records_archive ORDER BY archived_at DESC";
    $result = $conn->query($sql);

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    echo json_encode(['success' => true, 'data' => $rows]);
}

function restoreTraining($conn, $id) {
    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'ID required']);
        return;
    }

    $stmt = $conn->prepare("SELECT * FROM training_records_archive WHERE id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $record = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$record) {
        http_response_code(404);
        echo json_encode(['error' => 'Not found']);
        return;
    }

    $restore = $conn->prepare("
        INSERT INTO training_records
        (id, crew_id, station_id, initial_training_date, initial_status,
         followup1, followup2, followup3, followup4, followup5, notes)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $restore->bind_param(
        "iiissssssss",
        $record['id'],
        $record['crew_id'],
        $record['station_id'],
        $record['initial_training_date'],
        $record['initial_status'],
        $record['followup1'],
        $record['followup2'],
        $record['followup3'],
        $record['followup4'],
        $record['followup5'],
        $record['notes']
    );

    $restore->execute();
    $restore->close();

    $del = $conn->prepare("DELETE FROM training_records_archive WHERE id=?");
    $del->bind_param("i", $id);
    $del->execute();
    $del->close();

    echo json_encode(['success' => true, 'message' => 'Restored']);
}
// ── CREATE ────────────────────────────────────────────────

function createTraining($conn) {
    $input      = json_decode(file_get_contents('php://input'), true);
    $crew_id    = intval($input['crew_id']    ?? 0);
    $station_id = intval($input['station_id'] ?? 0);
    $init_date  = $input['initial_training_date'] ?? null;
    $init_stat  = $input['initial_status']        ?? 'Pending';
    $notes      = $input['notes']                 ?? '';

    if (!$crew_id || !$station_id) {
        http_response_code(400);
        echo json_encode(['error' => 'crew_id and station_id are required']);
        return;
    }

    $chk = $conn->prepare("SELECT id FROM training_records WHERE crew_id=? AND station_id=?");
    $chk->bind_param("ii", $crew_id, $station_id);
   $chk->execute();
$chk->store_result();

if ($chk->num_rows > 0) {
        http_response_code(409);
        echo json_encode(['error' => 'A training record for this crew and station already exists']);
        $chk->close(); return;
    }
    $chk->close();

    $stmt = $conn->prepare(
        "INSERT INTO training_records (crew_id, station_id, initial_training_date, initial_status, notes)
         VALUES (?, ?, ?, ?, ?)"
    );
    $stmt->bind_param("iisss", $crew_id, $station_id, $init_date, $init_stat, $notes);
    if ($stmt->execute()) {
        http_response_code(201);
        echo json_encode(['success' => true, 'message' => 'Training record created', 'id' => $conn->insert_id]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to create record: ' . $conn->error]);
    }
    $stmt->close();
}

// ── UPDATE ────────────────────────────────────────────────

function updateTraining($conn, $id) {
    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'ID required']);
        return;
    }
    

    $input   = json_decode(file_get_contents('php://input'), true);
  $manager_id = $_SESSION['user_id'] ?? null;

$allowed = [
    'initial_training_date', 'initial_status',
    'followup1', 'followup1_date',
    'followup2', 'followup2_date',
    'followup3', 'followup3_date',
    'followup4', 'followup4_date',
    'followup5', 'followup5_date',
    'notes'
];

    $fields = []; $types = ''; $values = [];
   $statusFields = [
    'initial_status' => 'initial_verified_by',
    'followup1' => 'followup1_verified_by',
    'followup2' => 'followup2_verified_by',
    'followup3' => 'followup3_verified_by',
    'followup4' => 'followup4_verified_by',
    'followup5' => 'followup5_verified_by',
];

foreach ($allowed as $f) {
    if (!array_key_exists($f, $input)) continue;

    $value = ($input[$f] === '' || $input[$f] === null) ? null : $input[$f];

    // 1. Always update field
    $fields[] = "$f = ?";
    $types .= 's';
    $values[] = $value;

    // 2. ONLY assign verifier if status is Pass/Fail
    if (isset($statusFields[$f]) && in_array($value, ['Pass', 'Fail'], true)) {

    // check existing verifier first
    $check = $conn->prepare("SELECT {$statusFields[$f]} FROM training_records WHERE id=?");
    $check->bind_param("i", $id);
    $check->execute();
    $existing = $check->get_result()->fetch_assoc();
    $check->close();

    // only assign verifier if NOT already set
    if (empty($existing[$statusFields[$f]])) {
        $fields[] = $statusFields[$f] . " = ?";
        $types .= 'i';
        $values[] = $manager_id;
    }
}
}

    if (empty($fields)) {
        http_response_code(400);
        echo json_encode(['error' => 'No fields to update']);
        return;
    }

    $types   .= 'i';
    $values[] = $id;
    $stmt = $conn->prepare("UPDATE training_records SET " . implode(', ', $fields) . " WHERE id = ?");
    $stmt->bind_param($types, ...$values);

    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Training record updated']);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Update failed: ' . $conn->error]);
    }
    $stmt->close();
}

// ── DELETE ────────────────────────────────────────────────

function deleteTraining($conn, $id) {
    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'ID required']);
        return;
    }

    $user_id = $_SESSION['user_id'];

    // GET RECORD
    $stmt = $conn->prepare("SELECT * FROM training_records WHERE id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $record = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$record) {
        http_response_code(404);
        echo json_encode(['error' => 'Record not found']);
        return;
    }

    // INSERT INTO ARCHIVE
    $archive = $conn->prepare("
        INSERT INTO training_records_archive
        (id, crew_id, station_id, initial_training_date, initial_status,
         followup1, followup2, followup3, followup4, followup5,
         notes, archived_at, archived_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
    ");

    $archive->bind_param(
        "iiissssssssi",
        $record['id'],
        $record['crew_id'],
        $record['station_id'],
        $record['initial_training_date'],
        $record['initial_status'],
        $record['followup1'],
        $record['followup2'],
        $record['followup3'],
        $record['followup4'],
        $record['followup5'],
        $record['notes'],
        $user_id
    );

    $archive->execute();
    $archive->close();

    // DELETE ORIGINAL
    $del = $conn->prepare("DELETE FROM training_records WHERE id=?");
    $del->bind_param("i", $id);

    if ($del->execute()) {
        echo json_encode(['success' => true, 'message' => 'Moved to archive']);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Delete failed']);
    }

    $del->close();
}
