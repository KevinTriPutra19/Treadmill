<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

$jsonFile = __DIR__ . '/../uploads/latest_result.json';

if (file_exists($jsonFile)) {
    readfile($jsonFile);
    exit;
}

http_response_code(404);
echo json_encode(['error' => 'Belum ada data']);
