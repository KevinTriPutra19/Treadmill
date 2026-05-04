<?php

function run_ddl_ignore_errors($conn, $sql)
{
    try {
        $conn->query($sql);
    } catch (Exception $e) {
        // Silently ignore
    }
}

function ensure_app_schema($conn)
{
    run_ddl_ignore_errors($conn, "CREATE TABLE IF NOT EXISTS members (
        uid VARCHAR(64) PRIMARY KEY,
        full_name VARCHAR(120) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    run_ddl_ignore_errors($conn, "CREATE TABLE IF NOT EXISTS device_state (
        id TINYINT PRIMARY KEY,
        active_uid VARCHAR(64) NULL,
        tap_count INT NOT NULL DEFAULT 0,
        photo_mode TINYINT NOT NULL DEFAULT 2,
        photo_interval_sec INT NOT NULL DEFAULT 2,
        pending_photo_count INT NOT NULL DEFAULT 0,
        status VARCHAR(32) NOT NULL DEFAULT 'idle',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    run_ddl_ignore_errors($conn, "INSERT IGNORE INTO device_state (id, active_uid, tap_count, photo_mode, photo_interval_sec, pending_photo_count, status)
        VALUES (1, NULL, 0, 2, 2, 0, 'idle')");

    run_ddl_ignore_errors($conn, "CREATE TABLE IF NOT EXISTS rfid_tap_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        uid VARCHAR(64) NOT NULL,
        tap_number INT NOT NULL,
        action_name VARCHAR(32) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_rfid_tap_logs_uid (uid)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    run_ddl_ignore_errors($conn, "ALTER TABLE treadmill_sessions ADD COLUMN rfid_uid VARCHAR(64) NULL");
    run_ddl_ignore_errors($conn, "ALTER TABLE treadmill_sessions ADD COLUMN user_name VARCHAR(120) NULL");
    run_ddl_ignore_errors($conn, "ALTER TABLE treadmill_sessions ADD COLUMN edited TINYINT(1) NOT NULL DEFAULT 0");
    run_ddl_ignore_errors($conn, "ALTER TABLE treadmill_sessions ADD COLUMN is_time_edited TINYINT(1) NOT NULL DEFAULT 0");
    run_ddl_ignore_errors($conn, "ALTER TABLE treadmill_sessions ADD COLUMN is_distance_edited TINYINT(1) NOT NULL DEFAULT 0");
    run_ddl_ignore_errors($conn, "ALTER TABLE treadmill_sessions ADD COLUMN calories INT NOT NULL DEFAULT 0");
    run_ddl_ignore_errors($conn, "ALTER TABLE treadmill_sessions ADD COLUMN image_time_path VARCHAR(255) NULL");
    run_ddl_ignore_errors($conn, "ALTER TABLE treadmill_sessions ADD COLUMN image_distance_path VARCHAR(255) NULL");
    run_ddl_ignore_errors($conn, "ALTER TABLE treadmill_sessions ADD COLUMN save_source VARCHAR(32) NOT NULL DEFAULT 'manual'");
    run_ddl_ignore_errors($conn, "ALTER TABLE treadmill_sessions ADD INDEX idx_treadmill_sessions_rfid_uid (rfid_uid)");
}

function get_device_state($conn)
{
    $res = $conn->query("SELECT id, active_uid, tap_count, photo_mode, photo_interval_sec, pending_photo_count, status FROM device_state WHERE id = 1 LIMIT 1");
    return $res ? $res->fetch_assoc() : null;
}

function get_member_by_uid($conn, $uid)
{
    $stmt = $conn->prepare("SELECT uid, full_name FROM members WHERE uid = ? LIMIT 1");
    $stmt->bind_param('s', $uid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row;
}

function upsert_member($conn, $uid, $fullName)
{
    $fullName = trim((string) $fullName);
    $isPlaceholderName = $fullName === '' || strcasecmp($fullName, 'Nama User') === 0;
    if ($isPlaceholderName) {
        $fullName = 'User ' . substr($uid, 0, 6);
    }

    $existing = get_member_by_uid($conn, $uid);
    if ($existing) {
        $currentName = trim((string) ($existing['full_name'] ?? ''));
        if ($isPlaceholderName || $currentName === $fullName) {
            return;
        }

        $stmt = $conn->prepare("UPDATE members SET full_name = ? WHERE uid = ?");
        $stmt->bind_param('ss', $fullName, $uid);
        $stmt->execute();
        $stmt->close();
        return;
    }

    $stmt = $conn->prepare("INSERT INTO members (uid, full_name) VALUES (?, ?) ON DUPLICATE KEY UPDATE full_name = VALUES(full_name)");
    $stmt->bind_param('ss', $uid, $fullName);
    $stmt->execute();
    $stmt->close();
}
