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
$input = json_decode(file_get_contents('php://input'), true);
$photoMode = isset($input['photo_mode']) ? (int) $input['photo_mode'] : 2;
$interval = isset($input['photo_interval_sec']) ? (int) $input['photo_interval_sec'] : 2;

if (!in_array($photoMode, [1, 2], true)) {
    $photoMode = 2;
}
$interval = max(1, min(10, $interval));

$stmt = $conn->prepare("UPDATE device_state SET photo_mode = ?, photo_interval_sec = ? WHERE id = 1");
$stmt->bind_param('ii', $photoMode, $interval);
$stmt->execute();
$stmt->close();

echo json_encode([
    'status' => 'ok',
    'photo_mode' => $photoMode,
    'photo_interval_sec' => $interval,
]);

$conn->close();
