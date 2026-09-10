    <?php
define('DB_HOST', 'sql108.infinityfree.com');
define('DB_USER', 'if0_42882528');
define('DB_PASS', 'cVqbxB1vQ56yIh');
define('DB_NAME', 'if0_42882528_svf_training_db');

function getDBConnection() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        http_response_code(500);
        die(json_encode(['error' => 'Database connection failed: ' . $conn->connect_error]));
    }
    $conn->set_charset('utf8mb4');
    return $conn;
}
?>
