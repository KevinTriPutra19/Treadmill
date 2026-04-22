<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../includes/schema.php';
require_once __DIR__ . '/../includes/helpers.php';

ensure_app_schema($conn);

$state = get_device_state($conn);
$activeUid = $state['active_uid'] ?? null;

if ($activeUid === null) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Tidak ada user login RFID']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

$id = isset($input['id']) ? (int) $input['id'] : 0;
$field = isset($input['field']) ? $input['field'] : '';
$value = isset($input['value']) ? trim($input['value']) : '';

$allowedFields = ['time_value', 'distance'];
if ($id <= 0 || !in_array($field, $allowedFields, true) || $value === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Data tidak valid']);
    exit;
}

$readStmt = $conn->prepare('SELECT time_value, distance FROM treadmill_sessions WHERE id = ? AND rfid_uid = ? LIMIT 1');
$readStmt->bind_param('is', $id, $activeUid);
$readStmt->execute();
$current = $readStmt->get_result()->fetch_assoc();
$readStmt->close();

if (!$current) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Sesi tidak ditemukan']);
    $conn->close();
    exit;
}

$newTime = $field === 'time_value' ? $value : (string) ($current['time_value'] ?? '0:00');
$newDistance = $field === 'distance' ? $value : (string) ($current['distance'] ?? '0 km');
$calories = estimate_calories(parse_duration_minutes($newTime), parse_distance_km($newDistance));

$extraSet = '';
if ($field === 'time_value') {
    $extraSet = ', is_time_edited = 1';
} elseif ($field === 'distance') {
    $extraSet = ', is_distance_edited = 1';
}

$stmt = $conn->prepare("UPDATE treadmill_sessions SET $field = ?, calories = ?, edited = 1$extraSet WHERE id = ? AND rfid_uid = ?");
$stmt->bind_param('siis', $value, $calories, $id, $activeUid);
$stmt->execute();

if ($stmt->affected_rows >= 0) {
    echo json_encode(['status' => 'ok', 'message' => 'Berhasil diupdate', 'field' => $field, 'value' => $value, 'calories' => $calories]);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Gagal update']);
}

$stmt->close();
$conn->close();
