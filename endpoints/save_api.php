<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../includes/schema.php';

ensure_app_schema($conn);

$state = get_device_state($conn);
$activeUid = $state['active_uid'] ?? null;
$activeName = null;
if ($activeUid) {
    $member = get_member_by_uid($conn, $activeUid);
    $activeName = $member['full_name'] ?? null;
} else {
    echo json_encode(['status' => 'no-login', 'message' => 'Belum ada user RFID login']);
    $conn->close();
    exit;
}

$jsonFile = __DIR__ . '/../uploads/latest_result.json';
if (!file_exists($jsonFile)) {
    echo json_encode(['status' => 'error', 'message' => 'Belum ada hasil OCR']);
    exit;
}

$data = json_decode(file_get_contents($jsonFile), true);
if (!$data || !isset($data['time_value'])) {
    echo json_encode(['status' => 'error', 'message' => 'Data hasil OCR tidak valid']);
    exit;
}

$time_value = $data['time_value'];
$distance_value = isset($data['distance']) ? trim((string) $data['distance']) : null;
$distance_value = $distance_value !== '' ? $distance_value : null;
$timestamp_api = $data['timestamp'] ?? null;
$raw_ocr = $data['raw_ocr'] ?? null;
$image_path = file_exists(__DIR__ . '/../uploads/latest_distance.jpg') ? 'latest_distance.jpg' : (file_exists(__DIR__ . '/../uploads/latest.jpg') ? 'latest.jpg' : null);

$stmt = $conn->prepare('SELECT id, time_value, edited FROM treadmill_sessions WHERE timestamp_api = ? AND ((rfid_uid IS NULL AND ? IS NULL) OR rfid_uid = ?)');
$stmt->bind_param('sss', $timestamp_api, $activeUid, $activeUid);
$stmt->execute();
$result = $stmt->get_result();

if ($result && $result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $session_id = (int) $row['id'];

    if ((int) $row['edited'] === 0 && ($row['time_value'] !== $time_value || $distance_value !== null)) {
        $update = $conn->prepare('UPDATE treadmill_sessions SET time_value = ?, distance = COALESCE(?, distance), raw_ocr = ?, image_path = ? WHERE id = ?');
        $update->bind_param('ssssi', $time_value, $distance_value, $raw_ocr, $image_path, $session_id);
        $update->execute();
        $update->close();
    }

    if ((int) $row['edited'] === 0) {
        $upImg = $conn->prepare('UPDATE treadmill_sessions SET image_path = COALESCE(image_path, ?), raw_ocr = COALESCE(raw_ocr, ?) WHERE id = ?');
        $upImg->bind_param('ssi', $image_path, $raw_ocr, $session_id);
        $upImg->execute();
        $upImg->close();
    }
} else {
    $insert = $conn->prepare('INSERT INTO treadmill_sessions (time_value, distance, timestamp_api, image_path, raw_ocr, rfid_uid, user_name) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $insert->bind_param('sssssss', $time_value, $distance_value, $timestamp_api, $image_path, $raw_ocr, $activeUid, $activeName);
    $insert->execute();
    $insert->close();
}
$stmt->close();

$latest = $conn->prepare('SELECT id, time_value, distance, timestamp_api, image_path, raw_ocr, edited, rfid_uid, user_name FROM treadmill_sessions WHERE ((rfid_uid IS NULL AND ? IS NULL) OR rfid_uid = ?) ORDER BY id DESC LIMIT 1');
$latest->bind_param('ss', $activeUid, $activeUid);
$latest->execute();
$row = $latest->get_result()->fetch_assoc();
$latest->close();
$conn->close();

if (!$row) {
    echo json_encode([
        'status' => 'ok',
        'id' => 0,
        'time_value' => null,
        'distance' => null,
        'timestamp_api' => null,
        'image_path' => null,
        'raw_ocr' => null,
        'edited' => 0,
        'rfid_uid' => $activeUid,
        'user_name' => $activeName,
    ]);
    exit;
}

echo json_encode([
    'status' => 'ok',
    'id' => (int) $row['id'],
    'time_value' => $row['time_value'],
    'distance' => $row['distance'],
    'timestamp_api' => $row['timestamp_api'],
    'image_path' => $row['image_path'],
    'raw_ocr' => $row['raw_ocr'],
    'edited' => (int) $row['edited'],
    'rfid_uid' => $row['rfid_uid'],
    'user_name' => $row['user_name'],
]);
