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
            member_id INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    
    // member_id Spalte hinzufügen falls nicht vorhanden
    try {
        $db->exec("ALTER TABLE atemschutz_traeger ADD COLUMN member_id INT NULL");
    } catch (Exception $e) {
        // Spalte existiert bereits, ignoriere Fehler
    }
    
    // Sicherstellen, dass is_pa_traeger Spalte in members existiert
    try {
        $db->exec("ALTER TABLE members ADD COLUMN is_pa_traeger TINYINT(1) DEFAULT 0");
    } catch (Exception $e) {
        // Spalte existiert bereits
    }
    
    // Setze alle bestehenden Geräteträger automatisch auf is_pa_traeger = 1
    try {
        $db->exec("
            UPDATE members m
            INNER JOIN atemschutz_traeger at ON m.id = at.member_id
            SET m.is_pa_traeger = 1
            WHERE m.is_pa_traeger = 0 OR m.is_pa_traeger IS NULL
        ");
    } catch (Exception $e) {
        // Fehler ignorieren
    }
    
    // Lade nur aktive Atemschutzgeräteträger, deren Mitglied is_pa_traeger = 1 hat
    $stmt = $db->prepare("
        SELECT at.id, at.first_name, at.last_name
        FROM atemschutz_traeger at
        LEFT JOIN members m ON at.member_id = m.id
        WHERE at.status = 'Aktiv' 
        AND (m.is_pa_traeger = 1 OR (at.member_id IS NULL AND EXISTS (
            SELECT 1 FROM members m2 
            WHERE m2.first_name = at.first_name 
            AND m2.last_name = at.last_name 
            AND m2.is_pa_traeger = 1
        )))
        ORDER BY at.last_name, at.first_name
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
