<?php
header('Content-Type: application/json');

$uploadsDir = realpath(__DIR__ . '/../uploads');
if (!$uploadsDir) {
    echo json_encode(['status' => 'error', 'message' => 'Uploads directory not found']);
    exit;
}

$resultFile = $uploadsDir . DIRECTORY_SEPARATOR . 'latest_result.json';
$statusFile = $uploadsDir . DIRECTORY_SEPARATOR . 'ocr_status.json';

$processing = false;
$processingMessage = null;
$processingUpdatedAt = null;
if (is_file($statusFile)) {
    $statusJson = json_decode((string) file_get_contents($statusFile), true);
    if (is_array($statusJson)) {
        $processing = !empty($statusJson['processing']);
        $processingMessage = $statusJson['message'] ?? null;
        $processingUpdatedAt = $statusJson['updated_at'] ?? null;
    }
}

if (!is_file($resultFile)) {
    echo json_encode([
        'status' => 'empty',
        'processing' => $processing,
        'processing_message' => $processingMessage,
        'processing_updated_at' => $processingUpdatedAt,
        'time_value' => null,
        'distance' => null,
        'raw_ocr' => null,
        'timestamp' => null,
    ]);
    exit;
}

$data = json_decode((string) file_get_contents($resultFile), true);
if (!is_array($data)) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Hasil OCR tidak valid',
        'processing' => $processing,
        'processing_message' => $processingMessage,
        'processing_updated_at' => $processingUpdatedAt,
    ]);
    exit;
}

echo json_encode([
    'status' => 'ok',
    'processing' => $processing,
    'processing_message' => $processingMessage,
    'processing_updated_at' => $processingUpdatedAt,
    'time_value' => $data['time_value'] ?? null,
    'distance' => $data['distance'] ?? null,
    'raw_ocr' => $data['raw_ocr'] ?? null,
    'distance_raw_ocr' => $data['distance_raw_ocr'] ?? null,
    'timestamp' => $data['timestamp'] ?? null,
]);
