<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Always return JSON — never raw PHP errors
set_error_handler(function($errno, $errstr) {
    http_response_code(500);
    echo json_encode(['error' => "PHP Error [$errno]: $errstr"]);
    exit;
});
set_exception_handler(function($e) {
    http_response_code(500);
    echo json_encode(['error' => 'Exception: ' . $e->getMessage()]);
    exit;
});

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

require_once '../config/database.php';

$conn  = getDBConnection();
$today = date('Y-m-d');

// ── 1. Total active crew ──────────────────────────────────
$totalCrew = (int)$conn->query(
    "SELECT COUNT(*) AS c FROM crew WHERE is_active = 1"
)->fetch_assoc()['c'];

// ── 2. Total stations ─────────────────────────────────────
$totalStations = (int)$conn->query(
    "SELECT COUNT(*) AS c FROM stations"
)->fetch_assoc()['c'];

// ── 3. Total training records ─────────────────────────────
$totalRecords = (int)$conn->query(
    "SELECT COUNT(*) AS c FROM training_records"
)->fetch_assoc()['c'];

// ── 4. Initial passes ─────────────────────────────────────
$completedInitial = (int)$conn->query(
    "SELECT COUNT(*) AS c FROM training_records WHERE initial_status = 'Pass'"
)->fetch_assoc()['c'];

// ── 5. Crew with at least one Pass ───────────────────────
$crewTrained = (int)$conn->query(
    "SELECT COUNT(DISTINCT crew_id) AS c FROM training_records WHERE initial_status = 'Pass'"
)->fetch_assoc()['c'];

// ── 6. Completion % ───────────────────────────────────────
$completionPct = $totalRecords > 0
    ? round(($completedInitial / $totalRecords) * 100, 1)
    : 0;

// ── 7. Pending / Overdue follow-ups + station breakdown ──
// FIX: JOIN stations so we get station_name (v4 schema uses station_id FK)
$allRecords = $conn->query(
    "SELECT tr.*, s.station_name, c.name AS crew_name
     FROM training_records tr
     JOIN stations s ON tr.station_id = s.id
     JOIN crew    c ON tr.crew_id    = c.id
     WHERE tr.initial_training_date IS NOT NULL"
);

$pendingCount       = 0;
$overdueCount       = 0;
$completedFollowups = 0;
$stationMap         = [];

if ($allRecords) {
    while ($row = $allRecords->fetch_assoc()) {
        $base = strtotime($row['initial_training_date']);
        $sn   = $row['station_name'];           // FIX: was $row['station'] — column does not exist

        // Station breakdown
        if (!isset($stationMap[$sn])) {
            $stationMap[$sn] = ['station' => $sn, 'total' => 0, 'passed' => 0];
        }
        $stationMap[$sn]['total']++;
        if ($row['initial_status'] === 'Pass') {
            $stationMap[$sn]['passed']++;
        }

        // Follow-up counts
        for ($i = 1; $i <= 5; $i++) {
            $dueDate = date('Y-m-d', strtotime("+". ($i * 3) ." months", $base));
            $status  = $row["followup$i"];

            if ($status === 'Pass' || $status === 'Fail') {
                $completedFollowups++;
                continue;
            }
            if ($dueDate < $today) {
                $overdueCount++;
            } else {
                $pendingCount++;
            }
        }
    }
}

// ── 8. Recent activity (last 5 updated records) ──────────
// FIX: was SELECT tr.station — column does not exist in v4; must JOIN stations
$recentResult = $conn->query(
    "SELECT tr.last_updated, tr.initial_status,
            s.station_name,
            c.name AS crew_name
     FROM training_records tr
     JOIN stations s ON tr.station_id = s.id
     JOIN crew    c ON tr.crew_id    = c.id
     ORDER BY tr.last_updated DESC
     LIMIT 5"
);

$recentList = [];
if ($recentResult) {
    while ($r = $recentResult->fetch_assoc()) {
        $recentList[] = $r;
    }
}

// ── Response ──────────────────────────────────────────────
echo json_encode([
    'success' => true,
    'stats'   => [
        'total_crew'            => $totalCrew,
        'total_stations'        => $totalStations,
        'crew_trained'          => $crewTrained,
        'total_records'         => $totalRecords,
        'completed_initial'     => $completedInitial,
        'pending_followups'     => $pendingCount,
        'overdue_followups'     => $overdueCount,
        'completed_followups'   => $completedFollowups,
        'completion_percentage' => $completionPct
    ],
    'station_stats'   => array_values($stationMap),
    'recent_activity' => $recentList
]);

$conn->close();
?>
