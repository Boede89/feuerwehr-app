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
    
    // Lade alle aktiven Atemschutzgeräteträger
    $stmt = $db->prepare("
        SELECT id, first_name, last_name, status 
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
