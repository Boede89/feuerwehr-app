<?php
/**
 * API-Endpoint für verfügbare Fahrzeuge
 */

header('Content-Type: application/json');

require_once 'config/database.php';

try {
    // Lade alle verfügbaren Fahrzeuge
    $stmt = $db->prepare("SELECT id, name, description FROM vehicles ORDER BY name");
    $stmt->execute();
    $vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($vehicles);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Fehler beim Laden der Fahrzeuge: ' . $e->getMessage()]);
}
?>
