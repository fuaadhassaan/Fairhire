<?php
// db.php
// Edit credentials for your environment
$DB_HOST = 'localhost';
$DB_USER = 'root';
$DB_PASS = '';    // set if you use a password
$DB_NAME = 'fairhire_plus';

$mysqli = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
if ($mysqli->connect_errno) {
    http_response_code(500);
    echo "DB connect error: " . $mysqli->connect_error;
    exit;
}
$mysqli->set_charset('utf8mb4');

function esc($s) {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}
?>
