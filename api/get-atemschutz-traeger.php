<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

try {
    // Prüfe ob Benutzer eingeloggt ist
    if (!is_logged_in()) {
        throw new Exception('Nicht angemeldet');
    }
    
    // Stelle sicher, dass die Tabelle existiert
    $db->exec("
        CREATE TABLE IF NOT EXISTS atemschutz_traeger (
            id INT AUTO_INCREMENT PRIMARY KEY,
            first_name VARCHAR(50) NOT NULL,
            last_name VARCHAR(50) NOT NULL,
            status VARCHAR(20) DEFAULT 'Tauglich',
            is_active BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");
    
    // Lade alle aktiven Atemschutzgeräteträger
    $stmt = $db->prepare("
        SELECT id, first_name, last_name
        FROM atemschutz_traeger 
        WHERE is_active = 1 
        ORDER BY last_name, first_name
    ");
    $stmt->execute();
    $traeger = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'traeger' => $traeger
    ]);
    
} catch (Exception $e) {
    error_log("Get Atemschutz Traeger Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Fehler beim Laden der Geräteträger: ' . $e->getMessage()
    ]);
}
?>
