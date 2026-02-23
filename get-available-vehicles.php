<?php
/**
 * API-Endpoint für verfügbare Fahrzeuge (einheitsspezifisch)
 */

session_start();
header('Content-Type: application/json');

require_once 'config/database.php';

$einheit_id = isset($_GET['einheit_id']) ? (int)$_GET['einheit_id'] : 0;
if ($einheit_id <= 0) {
    $einheit_id = isset($_SESSION['current_einheit_id']) ? (int)$_SESSION['current_einheit_id'] : 0;
}

try {
    if ($einheit_id > 0) {
        $stmt = $db->prepare("SELECT id, name, description FROM vehicles WHERE is_active = 1 AND (einheit_id = ? OR einheit_id IS NULL) ORDER BY name");
        $stmt->execute([$einheit_id]);
    } else {
        $stmt = $db->prepare("SELECT id, name, description FROM vehicles WHERE is_active = 1 ORDER BY name");
        $stmt->execute();
    }
    $vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($vehicles);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Fehler beim Laden der Fahrzeuge: ' . $e->getMessage()]);
}
?>
