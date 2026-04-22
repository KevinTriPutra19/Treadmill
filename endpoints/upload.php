<?php
$uploadDir = __DIR__ . "/../uploads/";

function write_ocr_status($uploadDir, $processing, $message)
{
    file_put_contents($uploadDir . 'ocr_status.json', json_encode([
        'processing' => (bool) $processing,
        'message' => $message,
        'updated_at' => date('Y-m-d H:i:s'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function quote_shell_arg_cross_platform($value)
{
    $value = (string) $value;
    if (PHP_OS_FAMILY === 'Windows') {
        // Gunakan double quote di Windows (single quote tidak diproses oleh cmd.exe).
        return '"' . str_replace('"', '\\"', $value) . '"';
    }

    return escapeshellarg($value);
}

function resolve_python_command(array $candidates)
{
    foreach ($candidates as $candidate) {
        $candidate = trim((string) $candidate);
        if ($candidate === '') {
            continue;
        }

        $versionCmd = $candidate . ' --version 2>&1';
        $out = [];
        $code = 1;
        @exec($versionCmd, $out, $code);
        if ($code === 0) {
            return $candidate;
        }
    }

    return null;
}

$photoIndex = isset($_GET['photo_index']) ? (int) $_GET['photo_index'] : 1;
$photoIndex = max(1, min(3, $photoIndex));
$runOcr = !isset($_GET['run_ocr']) || $_GET['run_ocr'] !== '0';
$fileName = 'latest.jpg';
if ($photoIndex === 1) {
    $fileName = 'latest_time.jpg';
} elseif ($photoIndex === 2) {
    $fileName = 'latest_distance.jpg';
} elseif ($photoIndex > 2) {
    $fileName = 'latest' . $photoIndex . '.jpg';
}

if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

$imageData = file_get_contents("php://input");

if ($imageData) {
    $filePath = $uploadDir . $fileName;
    file_put_contents($filePath, $imageData);

    // latest.jpg dipakai skrip OCR sebagai fallback/kompatibilitas.
    if ($runOcr || $photoIndex === 2 || $fileName === 'latest.jpg') {
        file_put_contents($uploadDir . 'latest.jpg', $imageData);
    }

    // Langsung respons ke ESP32 agar tidak timeout
    echo "Upload berhasil";

    // Flush output agar ESP32 dapat respons SEKARANG
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        ob_end_flush();
        flush();
    }

    // Jalankan OCR di background SETELAH respons terkirim
    $pythonExe = null;
    $pythonCandidates = [];
    foreach ([
        __DIR__ . "/../.venv/Scripts/python.exe",
        __DIR__ . "/../.venv/bin/python",
        __DIR__ . "/../.venv/bin/python3",
    ] as $candidatePath) {
        if (is_file($candidatePath)) {
            $resolved = realpath($candidatePath);
            if ($resolved) {
                $pythonCandidates[] = quote_shell_arg_cross_platform($resolved);
            }
        }
    }
    // Fallback ke interpreter sistem jika venv rusak/tidak valid.
    $pythonCandidates[] = 'py -3';
    $pythonCandidates[] = 'python';

    $pythonExe = resolve_python_command($pythonCandidates);
    $pythonScript = realpath(__DIR__ . "/../scripts/read_time.py");

    if (!$runOcr) {
        write_ocr_status($uploadDir, false, 'Menunggu foto kedua');
    }

    if ($runOcr && $pythonExe && $pythonScript) {
        $timeReady = is_file($uploadDir . 'latest_time.jpg');
        $distanceReady = is_file($uploadDir . 'latest_distance.jpg');

        if (!$timeReady || !$distanceReady) {
            write_ocr_status($uploadDir, false, 'Menunggu 2 foto lengkap');
            return;
        }

        write_ocr_status($uploadDir, true, 'OCR sedang diproses');

        $cmd = $pythonExe . " " . quote_shell_arg_cross_platform($pythonScript);

        if (PHP_OS_FAMILY === 'Windows') {
            pclose(popen('start "" /B ' . $cmd, 'r'));
        } else {
            exec($cmd . ' > /dev/null 2>&1 &');
        }
    } elseif ($runOcr) {
        write_ocr_status($uploadDir, false, 'OCR gagal: Python tidak tersedia');
    }
} else {
    http_response_code(400);
    echo "Tidak ada gambar";
}
