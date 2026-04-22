<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../includes/schema.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

$uploadsDir = realpath(__DIR__ . '/../uploads');
if (!$uploadsDir) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Uploads directory not found']);
    exit;
}

$targets = [
    $uploadsDir . DIRECTORY_SEPARATOR . 'latest_result.json',
    $uploadsDir . DIRECTORY_SEPARATOR . 'latest.jpg',
    $uploadsDir . DIRECTORY_SEPARATOR . 'latest_time.jpg',
    $uploadsDir . DIRECTORY_SEPARATOR . 'latest_distance.jpg',
];

foreach ($targets as $file) {
    if (is_file($file)) {
        @unlink($file);
    }
}

$debugDir = $uploadsDir . DIRECTORY_SEPARATOR . 'debug';
if (is_dir($debugDir)) {
    $debugFiles = glob($debugDir . DIRECTORY_SEPARATOR . '*.jpg');
    if (is_array($debugFiles)) {
        foreach ($debugFiles as $debugFile) {
            if (is_file($debugFile)) {
                @unlink($debugFile);
            }
        }
    }
}

$statusPayload = [
    'processing' => false,
    'message' => 'Menunggu foto baru',
    'updated_at' => date('Y-m-d H:i:s'),
];
@file_put_contents(
    $uploadsDir . DIRECTORY_SEPARATOR . 'ocr_status.json',
    json_encode($statusPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);

ensure_app_schema($conn);
$stmt = $conn->prepare("UPDATE device_state SET pending_photo_count = 0, status = 'logged_in' WHERE id = 1 AND active_uid IS NOT NULL");
$stmt->execute();
$stmt->close();
$conn->close();

echo json_encode(['status' => 'ok', 'message' => 'Capture cache dibersihkan']);
