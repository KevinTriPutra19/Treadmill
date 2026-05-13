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
$timeValue = isset($input['time_value']) ? trim((string) $input['time_value']) : '';
$distanceValue = isset($input['distance']) ? trim((string) $input['distance']) : '';

if ($id <= 0 || $timeValue === '' || $distanceValue === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Data tidak valid']);
    exit;
}

$readStmt = $conn->prepare('SELECT time_value, distance, is_time_edited, is_distance_edited FROM treadmill_sessions WHERE id = ? AND rfid_uid = ? LIMIT 1');
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

$newTime = $timeValue !== '' ? $timeValue : (string) ($current['time_value'] ?? '0:00');
$newDistance = $distanceValue !== '' ? $distanceValue : (string) ($current['distance'] ?? '0 km');

$isTimeEdited = (int) ($current['is_time_edited'] ?? 0);
$isDistanceEdited = (int) ($current['is_distance_edited'] ?? 0);

if ($newTime !== (string) ($current['time_value'] ?? '')) {
    $isTimeEdited = 1;
}
if ($newDistance !== (string) ($current['distance'] ?? '')) {
    $isDistanceEdited = 1;
}

$edited = ($isTimeEdited || $isDistanceEdited) ? 1 : 0;
$calories = estimate_calories(parse_duration_minutes($newTime), parse_distance_km($newDistance));

$update = $conn->prepare('UPDATE treadmill_sessions SET time_value = ?, distance = ?, calories = ?, edited = ?, is_time_edited = ?, is_distance_edited = ? WHERE id = ? AND rfid_uid = ?');
$update->bind_param('ssiiiis', $newTime, $newDistance, $calories, $edited, $isTimeEdited, $isDistanceEdited, $id, $activeUid);
$update->execute();
$update->close();

$durationLabel = format_duration_readable(parse_duration_minutes($newTime));
$distanceKm = parse_distance_km($newDistance);
$distanceLabel = $distanceKm > 0 ? number_format($distanceKm, 1, ',', '.') . ' km' : '—';
$caloriesLabel = number_format($calories, 0, ',', '.');

$conn->close();

echo json_encode([
    'status' => 'ok',
    'id' => $id,
    'time_value' => $newTime,
    'distance' => $newDistance,
    'duration_label' => $durationLabel,
    'distance_label' => $distanceLabel,
    'calories' => $calories,
    'calories_label' => $caloriesLabel,
    'is_time_edited' => $isTimeEdited,
    'is_distance_edited' => $isDistanceEdited,
]);
