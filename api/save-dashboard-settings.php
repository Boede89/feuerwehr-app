<?php
/**
 * Dashboard-Einstellungen speichern
 * API für das Speichern von Kollaps-Status und Sortierung
 */

require_once '../includes/functions.php';

// Session starten
session_start();

// Prüfen ob Benutzer eingeloggt ist
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Nicht autorisiert']);
    exit;
}

// Prüfen ob POST-Request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Nur POST-Requests erlaubt']);
    exit;
}

// JSON-Daten lesen
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['sections'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Ungültige Daten']);
    exit;
}

$userId = $_SESSION['user_id'];
$sections = $input['sections'];

try {
    $pdo = getDBConnection();
    
    // Transaktion starten
    $pdo->beginTransaction();
    
    // Vorbereitete Statements
    $updateStmt = $pdo->prepare("
        INSERT INTO dashboard_settings (user_id, section_id, is_collapsed, sort_order) 
        VALUES (?, ?, ?, ?) 
        ON DUPLICATE KEY UPDATE 
        is_collapsed = VALUES(is_collapsed), 
        sort_order = VALUES(sort_order),
        updated_at = CURRENT_TIMESTAMP
    ");
    
    // Alle Sektionen speichern
    foreach ($sections as $section) {
        if (!isset($section['id']) || !isset($section['collapsed']) || !isset($section['order'])) {
            throw new Exception('Ungültige Sektionsdaten');
        }
        
        $updateStmt->execute([
            $userId,
            $section['id'],
            $section['collapsed'] ? 1 : 0,
            $section['order']
        ]);
    }
    
    // Transaktion bestätigen
    $pdo->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Dashboard-Einstellungen gespeichert',
        'saved_sections' => count($sections)
    ]);
    
} catch (Exception $e) {
    // Transaktion rückgängig machen
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    error_log('Dashboard-Einstellungen Fehler: ' . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Fehler beim Speichern: ' . $e->getMessage()
    ]);
}
?>
