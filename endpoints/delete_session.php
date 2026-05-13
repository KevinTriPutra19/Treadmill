<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../includes/schema.php';

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

if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Data tidak valid']);
    exit;
}

$readStmt = $conn->prepare('SELECT image_path, image_time_path, image_distance_path FROM treadmill_sessions WHERE id = ? AND rfid_uid = ? LIMIT 1');
$readStmt->bind_param('is', $id, $activeUid);
$readStmt->execute();
$session = $readStmt->get_result()->fetch_assoc();
$readStmt->close();

if (!$session) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Sesi tidak ditemukan']);
    $conn->close();
    exit;
}

$deleteStmt = $conn->prepare('DELETE FROM treadmill_sessions WHERE id = ? AND rfid_uid = ?');
$deleteStmt->bind_param('is', $id, $activeUid);
$deleteStmt->execute();
$deleted = $deleteStmt->affected_rows > 0;
$deleteStmt->close();

function safe_delete_upload($uploadsDir, $relativePath)
{
    if (!$uploadsDir || !$relativePath) {
        return;
    }

    $relativePath = ltrim((string) $relativePath, '/\\');
    $fullPath = realpath($uploadsDir . DIRECTORY_SEPARATOR . $relativePath);

    if ($fullPath && strpos($fullPath, $uploadsDir) === 0 && is_file($fullPath)) {
        @unlink($fullPath);
    }
}

if ($deleted) {
    $uploadsDir = realpath(__DIR__ . '/../uploads');
    safe_delete_upload($uploadsDir, $session['image_time_path'] ?? null);
    safe_delete_upload($uploadsDir, $session['image_distance_path'] ?? null);
    safe_delete_upload($uploadsDir, $session['image_path'] ?? null);

    echo json_encode(['status' => 'ok', 'message' => 'Data berhasil dihapus']);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Gagal menghapus data']);
}

$conn->close();
