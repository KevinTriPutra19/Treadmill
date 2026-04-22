<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../includes/schema.php';

function reset_capture_cache_files()
{
    $uploadRoot = realpath(__DIR__ . '/../uploads');
    if (!$uploadRoot) {
        return;
    }

    $targets = [
        $uploadRoot . DIRECTORY_SEPARATOR . 'latest_result.json',
        $uploadRoot . DIRECTORY_SEPARATOR . 'latest.jpg',
        $uploadRoot . DIRECTORY_SEPARATOR . 'latest_time.jpg',
        $uploadRoot . DIRECTORY_SEPARATOR . 'latest_distance.jpg',
        $uploadRoot . DIRECTORY_SEPARATOR . 'debug' . DIRECTORY_SEPARATOR . '01_cropped.jpg',
        $uploadRoot . DIRECTORY_SEPARATOR . 'debug' . DIRECTORY_SEPARATOR . '02_clahe_otsu.jpg',
    ];

    foreach ($targets as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

ensure_app_schema($conn);
$input = json_decode(file_get_contents('php://input'), true);
$uid = strtoupper(trim((string) ($input['uid'] ?? '')));
$fullName = trim((string) ($input['name'] ?? ''));

if ($uid === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'UID wajib diisi']);
    exit;
}

$state = get_device_state($conn);
$currentUid = $state['active_uid'] ?? null;
$currentTap = (int) ($state['tap_count'] ?? 0);
$photoMode = 2;
$action = 'noop';
$nextTap = $currentTap;
$pendingPhoto = 0;
$newStatus = $state['status'] ?? 'idle';

if ($currentUid === null) {
    upsert_member($conn, $uid, $fullName);
    reset_capture_cache_files();
    $currentUid = $uid;
    $nextTap = 1;
    $action = 'login';
    $newStatus = 'logged_in';
} elseif ($currentUid !== $uid) {
    // Card berbeda dianggap switch user cepat.
    upsert_member($conn, $uid, $fullName);
    reset_capture_cache_files();
    $currentUid = $uid;
    $nextTap = 1;
    $action = 'switch_user';
    $newStatus = 'logged_in';
} else {
    // UID sama: tap kedua langsung logout.
    $action = 'logout';
    $currentUid = null;
    $nextTap = 0;
    $pendingPhoto = 0;
    $newStatus = 'idle';
}

$stmt = $conn->prepare("UPDATE device_state SET active_uid = ?, tap_count = ?, pending_photo_count = ?, status = ? WHERE id = 1");
$stmt->bind_param('siis', $currentUid, $nextTap, $pendingPhoto, $newStatus);
$stmt->execute();
$stmt->close();

$logUid = $uid;
$logTap = $nextTap;
$logStmt = $conn->prepare("INSERT INTO rfid_tap_logs (uid, tap_number, action_name) VALUES (?, ?, ?)");
$logStmt->bind_param('sis', $logUid, $logTap, $action);
$logStmt->execute();
$logStmt->close();

$memberName = null;
if ($currentUid !== null) {
    $member = get_member_by_uid($conn, $currentUid);
    $memberName = $member['full_name'] ?? null;
}

echo json_encode([
    'status' => 'ok',
    'action' => $action,
    'active_uid' => $currentUid,
    'tap_count' => $nextTap,
    'pending_photo_count' => $pendingPhoto,
    'photo_mode' => $photoMode,
    'is_logged_in' => $currentUid !== null,
    'member_name' => $memberName,
]);

$conn->close();
