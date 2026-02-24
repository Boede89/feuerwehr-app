<?php
/**
 * Räume-Tabellen erstellen falls nicht vorhanden.
 * Wird von settings-global.php, room-selection.php, room-reservation.php etc. eingebunden.
 */
if (!isset($db) || !$db) return;
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS rooms (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            description TEXT NULL,
            is_active TINYINT(1) DEFAULT 1,
            sort_order INT DEFAULT 0,
            einheit_id INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_einheit (einheit_id),
            KEY idx_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $db->exec("
        CREATE TABLE IF NOT EXISTS room_reservations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            room_id INT NOT NULL,
            requester_name VARCHAR(100) NOT NULL,
            requester_email VARCHAR(100) NOT NULL,
            reason TEXT NOT NULL,
            location VARCHAR(255) NULL,
            start_datetime DATETIME NOT NULL,
            end_datetime DATETIME NOT NULL,
            status ENUM('pending', 'approved', 'rejected', 'cancelled') DEFAULT 'pending',
            rejection_reason TEXT NULL,
            approved_by INT NULL,
            approved_at TIMESTAMP NULL,
            calendar_conflicts JSON NULL,
            einheit_id INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE,
            FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
            KEY idx_room (room_id),
            KEY idx_status (status),
            KEY idx_start (start_datetime)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) {
    error_log('rooms-setup: ' . $e->getMessage());
}
