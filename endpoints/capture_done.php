<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../includes/schema.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

ensure_app_schema($conn);
$stmt = $conn->prepare("UPDATE device_state SET status = 'logged_in' WHERE id = 1 AND active_uid IS NOT NULL");
$stmt->execute();
$stmt->close();

echo json_encode(['status' => 'ok']);
$conn->close();
