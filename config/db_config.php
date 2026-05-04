<?php
$db_host = getenv('DB_HOST') ?: 'localhost';
$db_port = (int) (getenv('DB_PORT') ?: 3306);
$db_user = getenv('DB_USER') ?: 'root';
$db_pass = getenv('DB_PASS');
$db_name = getenv('DB_NAME') ?: 'treadmill_db';

if ($db_pass === false) {
    $db_pass = '';
}
$conn = new mysqli($db_host, $db_user, $db_pass, $db_name, $db_port);
if ($conn->connect_error) {
    die('Koneksi database gagal: ' . $conn->connect_error);
}
$conn->set_charset('utf8mb4');
