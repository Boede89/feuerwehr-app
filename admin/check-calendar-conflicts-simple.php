<?php
/**
 * Vereinfachter AJAX-Endpoint für Kalender-Konflikt-Prüfung
 */

header('Content-Type: application/json');

// Session starten
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../config/database.php';
require_once '../includes/functions.php';

// Prüfe ob Benutzer eingeloggt ist
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    echo json_encode(['success' => false, 'error' => 'Nicht angemeldet']);
    exit;
}

// Prüfe ob Benutzer Admin-Rechte hat
if (!can_approve_reservations()) {
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
        echo json_encode(['success' => false, 'error' => 'Keine Berechtigung']);
        exit;
    }
}

try {
    // JSON-Daten lesen
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['vehicle_name']) || !isset($input['start_datetime']) || !isset($input['end_datetime'])) {
        echo json_encode(['success' => false, 'error' => 'Ungültige Parameter']);
        exit;
    }
    
    $vehicle_name = $input['vehicle_name'];
    $start_datetime = $input['start_datetime'];
    $end_datetime = $input['end_datetime'];
    
    // Vereinfachte Kalender-Konflikt-Prüfung (ohne Google Calendar)
    $conflicts = [];
    
    // Prüfe andere Reservierungen für das gleiche Fahrzeug im gleichen Zeitraum
    $stmt = $db->prepare("
        SELECT id, requester_name, reason, start_datetime, end_datetime 
        FROM reservations 
        WHERE vehicle_id = (SELECT id FROM vehicles WHERE name = ?) 
        AND status = 'approved' 
        AND (
            (start_datetime <= ? AND end_datetime >= ?) OR
            (start_datetime <= ? AND end_datetime >= ?) OR
            (start_datetime >= ? AND end_datetime <= ?)
        )
        AND id != ?
    ");
    
    $stmt->execute([
        $vehicle_name, 
        $start_datetime, $start_datetime,
        $end_datetime, $end_datetime,
        $start_datetime, $end_datetime,
        0 // Placeholder für id != ? (wird ignoriert)
    ]);
    
    $existing_reservations = $stmt->fetchAll();
    
    foreach ($existing_reservations as $reservation) {
        $conflicts[] = [
            'title' => $vehicle_name . ' - ' . $reservation['reason'],
            'start' => $reservation['start_datetime'],
            'end' => $reservation['end_datetime'],
            'requester' => $reservation['requester_name']
        ];
    }
    
    echo json_encode([
        'success' => true,
        'conflicts' => $conflicts,
        'vehicle_name' => $vehicle_name,
        'start_datetime' => $start_datetime,
        'end_datetime' => $end_datetime,
        'debug' => [
            'found_reservations' => count($existing_reservations),
            'query_params' => [$vehicle_name, $start_datetime, $end_datetime]
        ]
    ]);
    
} catch (Exception $e) {
    error_log('Kalender-Konflikt-Prüfung Fehler: ' . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'error' => 'Server-Fehler: ' . $e->getMessage(),
        'debug' => [
            'file' => __FILE__,
            'line' => $e->getLine(),
            'message' => $e->getMessage()
        ]
    ]);
}
?>
