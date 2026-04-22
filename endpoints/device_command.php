<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../includes/schema.php';

ensure_app_schema($conn);
$state = get_device_state($conn);

$photoCount = (int) ($state['pending_photo_count'] ?? 0);
$interval = (int) ($state['photo_interval_sec'] ?? 2);
$activeUid = $state['active_uid'] ?? null;
$command = 'idle';

if ($photoCount > 0 && $activeUid !== null) {
    $command = 'capture';

    $stmt = $conn->prepare("UPDATE device_state SET pending_photo_count = 0, status = 'capturing' WHERE id = 1");
    $stmt->execute();
    $stmt->close();
}

echo json_encode([
    'status' => 'ok',
    'command' => $command,
    'photo_count' => $command === 'capture' ? $photoCount : 0,
    'photo_interval_sec' => $interval,
    'active_uid' => $activeUid,
    'upload_endpoint' => 'endpoints/upload.php',
    'capture_done_endpoint' => 'endpoints/capture_done.php',
]);

$conn->close();
