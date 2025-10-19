<?php
/**
 * Dashboard-Einstellungen laden
 * API für das Laden von Kollaps-Status und Sortierung
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

try {
    $pdo = getDBConnection();
    $userId = $_SESSION['user_id'];
    
    // Dashboard-Einstellungen laden
    $stmt = $pdo->prepare("
        SELECT section_id, is_collapsed, sort_order 
        FROM dashboard_settings 
        WHERE user_id = ? 
        ORDER BY sort_order ASC
    ");
    $stmt->execute([$userId]);
    $settings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Standard-Einstellungen falls keine vorhanden
    if (empty($settings)) {
        $defaultSections = [
            'reservations' => ['name' => 'Offene Reservierungen', 'order' => 1, 'collapsed' => false],
            'atemschutz' => ['name' => 'Atemschutz-Übungen', 'order' => 2, 'collapsed' => false],
            'feedback' => ['name' => 'Feedback-Übersicht', 'order' => 3, 'collapsed' => false],
            'recent_activities' => ['name' => 'Letzte Aktivitäten', 'order' => 4, 'collapsed' => false]
        ];
        
        // Standard-Einstellungen in Datenbank speichern
        $insertStmt = $pdo->prepare("
            INSERT INTO dashboard_settings (user_id, section_id, is_collapsed, sort_order) 
            VALUES (?, ?, ?, ?)
        ");
        
        foreach ($defaultSections as $sectionId => $sectionData) {
            $insertStmt->execute([
                $userId, 
                $sectionId, 
                $sectionData['collapsed'] ? 1 : 0, 
                $sectionData['order']
            ]);
        }
        
        // Nochmal laden
        $stmt->execute([$userId]);
        $settings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Formatieren für Frontend
    $formattedSettings = [];
    foreach ($settings as $setting) {
        $formattedSettings[] = [
            'id' => $setting['section_id'],
            'collapsed' => (bool)$setting['is_collapsed'],
            'order' => (int)$setting['sort_order']
        ];
    }
    
    echo json_encode([
        'success' => true,
        'settings' => $formattedSettings
    ]);
    
} catch (Exception $e) {
    error_log('Dashboard-Einstellungen laden Fehler: ' . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Fehler beim Laden: ' . $e->getMessage()
    ]);
}
?>
