<?php
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'treadmill_db';

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    die('Koneksi database gagal: ' . $conn->connect_error);
}
$conn->set_charset('utf8mb4');
