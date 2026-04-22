<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../includes/schema.php';

ensure_app_schema($conn);
$state = get_device_state($conn);

$activeUid = $state['active_uid'] ?? null;
$member = null;
if ($activeUid) {
    $member = get_member_by_uid($conn, $activeUid);
}

echo json_encode([
    'status' => 'ok',
    'active_uid' => $activeUid,
    'tap_count' => (int) ($state['tap_count'] ?? 0),
    'photo_mode' => (int) ($state['photo_mode'] ?? 2),
    'photo_interval_sec' => (int) ($state['photo_interval_sec'] ?? 2),
    'pending_photo_count' => (int) ($state['pending_photo_count'] ?? 0),
    'device_status' => $state['status'] ?? 'idle',
    'is_logged_in' => $activeUid !== null,
    'member_name' => $member['full_name'] ?? null,
]);

$conn->close();
