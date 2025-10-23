<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// JSON-Header setzen
header('Content-Type: application/json');

// Login-Prüfung
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Nicht angemeldet']);
    exit;
}

// POST-Daten lesen
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['section_name']) || !isset($input['is_collapsed'])) {
    echo json_encode(['success' => false, 'message' => 'Ungültige Daten']);
    exit;
}

$section_name = sanitize_input($input['section_name']);
$is_collapsed = (bool)$input['is_collapsed'];
$user_id = $_SESSION['user_id'];

try {
    // Dashboard-Einstellungen Tabelle erstellen (falls nicht vorhanden)
    $db->exec("
        CREATE TABLE IF NOT EXISTS dashboard_preferences (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            section_name VARCHAR(50) NOT NULL,
            is_collapsed BOOLEAN DEFAULT FALSE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_user_section (user_id, section_name),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    
    // Einstellung speichern oder aktualisieren
    $stmt = $db->prepare("
        INSERT INTO dashboard_preferences (user_id, section_name, is_collapsed) 
        VALUES (?, ?, ?) 
        ON DUPLICATE KEY UPDATE 
        is_collapsed = VALUES(is_collapsed),
        updated_at = CURRENT_TIMESTAMP
    ");
    
    $stmt->execute([$user_id, $section_name, $is_collapsed]);
    
    echo json_encode(['success' => true, 'message' => 'Einstellung gespeichert']);
    
} catch (Exception $e) {
    error_log("Fehler beim Speichern der Dashboard-Einstellung: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Fehler beim Speichern']);
}
?>
