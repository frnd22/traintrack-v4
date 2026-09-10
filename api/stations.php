<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

require_once '../config/database.php';

$method = $_SERVER['REQUEST_METHOD'];
$conn   = getDBConnection();

$id = null;
if (!empty($_GET['id']) && is_numeric($_GET['id'])) {
    $id = intval($_GET['id']);
} else {
    $pathParts = explode('/', trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/'));
    foreach ($pathParts as $i => $part) {
        if (in_array($part,['stations.php','stations']) && isset($pathParts[$i+1]) && is_numeric($pathParts[$i+1])) {
            $id = intval($pathParts[$i+1]); break;
        }
    }
}

switch ($method) {
    case 'GET':    $id ? getStationById($conn,$id) : getAllStations($conn); break;
    case 'POST':   createStation($conn); break;
    case 'PUT':    updateStation($conn,$id); break;
    case 'DELETE': deleteStation($conn,$id); break;
    default: http_response_code(405); echo json_encode(['error'=>'Method not allowed']);
}
$conn->close();

function getAllStations($conn) {
    $result = $conn->query("
        SELECT s.*, COUNT(tr.id) as total_records, SUM(tr.initial_status='Pass') as passed_count
        FROM stations s LEFT JOIN training_records tr ON s.id=tr.station_id
        GROUP BY s.id ORDER BY s.station_name
    ");
    $rows = [];
    while ($r = $result->fetch_assoc()) $rows[] = $r;
    echo json_encode(['success'=>true,'data'=>$rows]);
}

function getStationById($conn,$id) {
    $stmt = $conn->prepare("SELECT * FROM stations WHERE id=?");
    $stmt->bind_param("i",$id); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if ($row) echo json_encode(['success'=>true,'data'=>$row]);
    else { http_response_code(404); echo json_encode(['error'=>'Station not found']); }
}

function createStation($conn) {
    $input = json_decode(file_get_contents('php://input'),true);
    $name  = trim($input['station_name'] ?? '');
    $desc  = trim($input['description']  ?? '');
    if (!$name) { http_response_code(400); echo json_encode(['error'=>'Station name required']); return; }
    $chk = $conn->prepare("SELECT id FROM stations WHERE station_name=?");
    $chk->bind_param("s",$name); $chk->execute();
    if ($chk->get_result()->num_rows>0) { http_response_code(409); echo json_encode(['error'=>'Station name already exists']); $chk->close(); return; }
    $chk->close();
    $stmt = $conn->prepare("INSERT INTO stations (station_name,description) VALUES (?,?)");
    $stmt->bind_param("ss",$name,$desc);
    if ($stmt->execute()) { http_response_code(201); echo json_encode(['success'=>true,'message'=>'Station created','id'=>$conn->insert_id]); }
    else { http_response_code(500); echo json_encode(['error'=>'Failed to create station']); }
    $stmt->close();
}

function updateStation($conn,$id) {
    if (!$id) { http_response_code(400); echo json_encode(['error'=>'ID required']); return; }
    $input = json_decode(file_get_contents('php://input'),true);
    $name  = trim($input['station_name'] ?? '');
    $desc  = trim($input['description']  ?? '');
    if (!$name) { http_response_code(400); echo json_encode(['error'=>'Station name required']); return; }
    $chk = $conn->prepare("SELECT id FROM stations WHERE station_name=? AND id!=?");
    $chk->bind_param("si",$name,$id); $chk->execute();
    if ($chk->get_result()->num_rows>0) { http_response_code(409); echo json_encode(['error'=>'Station name already exists']); $chk->close(); return; }
    $chk->close();
    $stmt = $conn->prepare("UPDATE stations SET station_name=?,description=? WHERE id=?");
    $stmt->bind_param("ssi",$name,$desc,$id);
    if ($stmt->execute()) echo json_encode(['success'=>true,'message'=>'Station updated']);
    else { http_response_code(500); echo json_encode(['error'=>'Update failed']); }
    $stmt->close();
}

function deleteStation($conn,$id) {
    if (!$id) { http_response_code(400); echo json_encode(['error'=>'ID required']); return; }
    $chk = $conn->prepare("SELECT COUNT(*) as c FROM training_records WHERE station_id=?");
    $chk->bind_param("i",$id); $chk->execute();
    $cnt = $chk->get_result()->fetch_assoc()['c']; $chk->close();
    if ($cnt>0) { http_response_code(409); echo json_encode(['error'=>"Cannot delete: station has $cnt record(s)"]); return; }
    $stmt = $conn->prepare("DELETE FROM stations WHERE id=?");
    $stmt->bind_param("i",$id);
    if ($stmt->execute()) echo json_encode(['success'=>true,'message'=>'Station deleted']);
    else { http_response_code(500); echo json_encode(['error'=>'Delete failed']); }
    $stmt->close();
}
