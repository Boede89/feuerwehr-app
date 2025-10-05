<?php
/**
 * AJAX-Endpoint für Kalender-Konflikt-Prüfung
 */

header('Content-Type: application/json');

// Session starten
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../config/database.php';
require_once '../includes/functions.php';

// Fallback-Funktion für lokale Konflikt-Prüfung
function check_local_conflicts($vehicle_name, $start_datetime, $end_datetime) {
    global $db;
    
    try {
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
        ");
        
        $stmt->execute([
            $vehicle_name, 
            $start_datetime, $start_datetime,
            $end_datetime, $end_datetime,
            $start_datetime, $end_datetime
        ]);
        
        $existing_reservations = $stmt->fetchAll();
        $conflicts = [];
        
        foreach ($existing_reservations as $reservation) {
            $conflicts[] = [
                'title' => $vehicle_name . ' - ' . $reservation['reason'],
                'start' => $reservation['start_datetime'],
                'end' => $reservation['end_datetime'],
                'requester' => $reservation['requester_name'],
                'source' => 'local_database'
            ];
        }
        
        return $conflicts;
    } catch (Exception $e) {
        error_log('Lokale Konflikt-Prüfung Fehler: ' . $e->getMessage());
        return [];
    }
}

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
require_once '../includes/google_calendar_service_account.php';
require_once '../includes/google_calendar.php';

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
    
    // Prüfe Kalender-Konflikte mit Google Calendar
    $conflicts = [];
    if (function_exists('check_calendar_conflicts')) {
        try {
            $conflicts = check_calendar_conflicts($vehicle_name, $start_datetime, $end_datetime);
        } catch (Exception $e) {
            error_log('Google Calendar Konflikt-Prüfung Fehler: ' . $e->getMessage());
            // Fallback: Prüfe nur lokale Datenbank
            $conflicts = check_local_conflicts($vehicle_name, $start_datetime, $end_datetime);
        }
    } else {
        // Fallback: Prüfe nur lokale Datenbank
        $conflicts = check_local_conflicts($vehicle_name, $start_datetime, $end_datetime);
    }
    
    echo json_encode([
        'success' => true,
        'conflicts' => $conflicts,
        'vehicle_name' => $vehicle_name,
        'start_datetime' => $start_datetime,
        'end_datetime' => $end_datetime
    ]);
    
} catch (Exception $e) {
    error_log('Kalender-Konflikt-Prüfung Fehler: ' . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'error' => 'Server-Fehler: ' . $e->getMessage()
    ]);
}
?>
