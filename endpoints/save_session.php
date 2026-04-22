<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../includes/schema.php';
require_once __DIR__ . '/../includes/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

ensure_app_schema($conn);

function as_bool($value)
{
    if (is_bool($value)) {
        return $value;
    }
    $str = strtolower(trim((string) $value));
    return in_array($str, ['1', 'true', 'yes', 'on'], true);
}

function archive_ocr_images($uploadsDir, $activeUid, $timestampApi)
{
    $result = [
        'time' => null,
        'distance' => null,
    ];

    if (!$uploadsDir) {
        return $result;
    }

    $historyDir = $uploadsDir . DIRECTORY_SEPARATOR . 'history';
    if (!is_dir($historyDir)) {
        @mkdir($historyDir, 0777, true);
    }

    if (!is_dir($historyDir)) {
        return $result;
    }

    $safeUid = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $activeUid);
    if ($safeUid === '') {
        $safeUid = 'unknown';
    }

    $stamp = preg_replace('/[^0-9]/', '', (string) $timestampApi);
    if ($stamp === '') {
        $stamp = date('YmdHis');
    }

    $pairs = [
        'time' => 'latest_time.jpg',
        'distance' => 'latest_distance.jpg',
    ];

    foreach ($pairs as $kind => $srcName) {
        $src = $uploadsDir . DIRECTORY_SEPARATOR . $srcName;
        if (!is_file($src)) {
            continue;
        }

        $destName = $safeUid . '_' . $stamp . '_' . $kind . '.jpg';
        $destAbs = $historyDir . DIRECTORY_SEPARATOR . $destName;
        if (@copy($src, $destAbs)) {
            $result[$kind] = 'history/' . $destName;
        }
    }

    return $result;
}

function write_latest_result_cache($uploadsDir, $timeValue, $distanceValue, $rawOcr, $timestampApi)
{
    if (!$uploadsDir) {
        return;
    }

    $file = $uploadsDir . DIRECTORY_SEPARATOR . 'latest_result.json';
    $payload = [
        'time_value' => $timeValue,
        'distance' => $distanceValue,
        'confidence' => 'saved',
        'method' => 'manual_or_saved',
        'raw_ocr' => $rawOcr,
        'distance_raw_ocr' => '',
        'timestamp' => $timestampApi ?: date('Y-m-d H:i:s'),
    ];

    @file_put_contents($file, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
}

$state = get_device_state($conn);
$activeUid = $state['active_uid'] ?? null;
if (!$activeUid) {
    echo json_encode(['status' => 'no-login', 'message' => 'Belum ada user login']);
    $conn->close();
    exit;
}

$member = get_member_by_uid($conn, $activeUid);
$activeName = $member['full_name'] ?? null;

$input = json_decode(file_get_contents('php://input'), true);
$timeValue = trim((string) ($input['time_value'] ?? ''));
$distanceValue = trim((string) ($input['distance'] ?? ''));
$rawOcr = trim((string) ($input['raw_ocr'] ?? ''));
$timestampApi = trim((string) ($input['timestamp_api'] ?? ''));
$timeEdited = as_bool($input['time_edited'] ?? false);
$distanceEdited = as_bool($input['distance_edited'] ?? false);
$saveSource = trim((string) ($input['save_source'] ?? 'manual'));
if ($saveSource !== 'auto') {
    $saveSource = 'manual';
}

if ($timeValue === '') {
    $timeValue = '0:00';
}
if ($distanceValue === '') {
    $distanceValue = '0 km';
}
if ($timestampApi === '') {
    $timestampApi = date('Y-m-d H:i:s');
}

$durationMinutes = parse_duration_minutes($timeValue);
$distanceKm = parse_distance_km($distanceValue);
$calories = estimate_calories($durationMinutes, $distanceKm);

$uploadsDir = realpath(__DIR__ . '/../uploads');
$imagePath = null;
if ($uploadsDir) {
    if (is_file($uploadsDir . DIRECTORY_SEPARATOR . 'latest_distance.jpg')) {
        $imagePath = 'latest_distance.jpg';
    } elseif (is_file($uploadsDir . DIRECTORY_SEPARATOR . 'latest.jpg')) {
        $imagePath = 'latest.jpg';
    }
}

$archived = archive_ocr_images($uploadsDir, $activeUid, $timestampApi);
$imageTimePath = $archived['time'] ?? null;
$imageDistancePath = $archived['distance'] ?? null;
$edited = ($timeEdited || $distanceEdited) ? 1 : 0;

$stmt = $conn->prepare('SELECT id FROM treadmill_sessions WHERE timestamp_api = ? AND rfid_uid = ? LIMIT 1');
$stmt->bind_param('ss', $timestampApi, $activeUid);
$stmt->execute();
$existing = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($existing) {
    $id = (int) $existing['id'];
    $update = $conn->prepare('UPDATE treadmill_sessions SET time_value = ?, distance = ?, calories = ?, image_path = ?, image_time_path = ?, image_distance_path = ?, raw_ocr = ?, user_name = ?, edited = ?, is_time_edited = ?, is_distance_edited = ?, save_source = ? WHERE id = ?');
    $update->bind_param('ssisssssiiisi', $timeValue, $distanceValue, $calories, $imagePath, $imageTimePath, $imageDistancePath, $rawOcr, $activeName, $edited, $timeEdited, $distanceEdited, $saveSource, $id);
    $update->execute();
    $update->close();

    write_latest_result_cache($uploadsDir, $timeValue, $distanceValue, $rawOcr, $timestampApi);

    echo json_encode(['status' => 'ok', 'id' => $id, 'calories' => $calories, 'message' => 'Data riwayat diperbarui']);
    $conn->close();
    exit;
}

$insert = $conn->prepare('INSERT INTO treadmill_sessions (time_value, distance, calories, timestamp_api, image_path, image_time_path, image_distance_path, raw_ocr, rfid_uid, user_name, edited, is_time_edited, is_distance_edited, save_source) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
$insert->bind_param('ssisssssssiiis', $timeValue, $distanceValue, $calories, $timestampApi, $imagePath, $imageTimePath, $imageDistancePath, $rawOcr, $activeUid, $activeName, $edited, $timeEdited, $distanceEdited, $saveSource);
$insert->execute();
$newId = $insert->insert_id;
$insert->close();

write_latest_result_cache($uploadsDir, $timeValue, $distanceValue, $rawOcr, $timestampApi);

$conn->close();

echo json_encode([
    'status' => 'ok',
    'id' => (int) $newId,
    'image_time_path' => $imageTimePath,
    'image_distance_path' => $imageDistancePath,
    'calories' => $calories,
    'edited' => $edited,
    'message' => 'Data berhasil disimpan ke riwayat',
]);
