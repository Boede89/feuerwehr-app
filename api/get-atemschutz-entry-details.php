<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

try {
    // Login ist für Atemschutzeinträge nicht erforderlich
    
    $input = json_decode(file_get_contents('php://input'), true);
    $entry_id = (int)($input['entry_id'] ?? 0);
    
    if ($entry_id <= 0) {
        throw new Exception('Ungültige Eintrag-ID');
    }
    
    // Lade Atemschutzeintrag-Details
    $stmt = $db->prepare("
        SELECT ae.*, 
               GROUP_CONCAT(CONCAT(at.first_name, ' ', at.last_name) ORDER BY at.last_name, at.first_name SEPARATOR ', ') as traeger_names
        FROM atemschutz_entries ae
        LEFT JOIN atemschutz_entry_traeger aet ON ae.id = aet.entry_id
        LEFT JOIN atemschutz_traeger at ON aet.traeger_id = at.id
        WHERE ae.id = ?
        GROUP BY ae.id
    ");
    $stmt->execute([$entry_id]);
    $entry = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$entry) {
        throw new Exception('Atemschutzeintrag nicht gefunden');
    }
    
    echo json_encode([
        'success' => true,
        'entry' => $entry
    ]);
    
} catch (Exception $e) {
    error_log("Get Atemschutz Entry Details Error: " . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
