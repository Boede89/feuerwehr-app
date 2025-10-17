<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

try {
    // Login ist für Atemschutzeinträge nicht erforderlich
    
    // Stelle sicher, dass die Tabelle existiert (mit der korrekten Struktur)
    $db->exec("
        CREATE TABLE IF NOT EXISTS atemschutz_traeger (
            id INT AUTO_INCREMENT PRIMARY KEY,
            first_name VARCHAR(100) NOT NULL,
            last_name VARCHAR(100) NOT NULL,
            email VARCHAR(255) NULL,
            birthdate DATE NOT NULL,
            strecke_am DATE NOT NULL,
            g263_am DATE NOT NULL,
            uebung_am DATE NOT NULL,
            status VARCHAR(50) NOT NULL DEFAULT 'Aktiv',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    
    // Lade alle aktiven Atemschutzgeräteträger
    $stmt = $db->prepare("
        SELECT id, first_name, last_name
        FROM atemschutz_traeger 
        WHERE status = 'Aktiv' 
        ORDER BY last_name, first_name
    ");
    $stmt->execute();
    $traeger = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Debug-Log
    error_log("Atemschutz Traeger geladen: " . count($traeger) . " Einträge");
    
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
