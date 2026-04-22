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

$stmt = $conn->prepare("UPDATE device_state SET active_uid = NULL, tap_count = 0, pending_photo_count = 0, status = 'idle' WHERE id = 1");
$stmt->execute();
$stmt->close();

$conn->close();

echo json_encode([
    'status' => 'ok',
    'message' => 'Device state reset to idle',
]);
