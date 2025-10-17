<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Prüfe ob Benutzer eingeloggt ist
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Nicht angemeldet']);
    exit;
}

// Prüfe Admin-Berechtigung
$is_admin = hasAdminPermission();
$can_reservations = has_permission('reservations');

if (!$is_admin && !$can_reservations) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Keine Berechtigung für Reservierungen']);
    exit;
}

// JSON Input lesen
$input = json_decode(file_get_contents('php://input'), true);
$reservation_id = $input['reservation_id'] ?? null;

if (!$reservation_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Reservierungs-ID erforderlich']);
    exit;
}

try {
    // Reservierung laden
    $stmt = $db->prepare("
        SELECT r.*, v.name as vehicle_name 
        FROM reservations r 
        LEFT JOIN vehicles v ON r.vehicle_id = v.id 
        WHERE r.id = ? AND r.status = 'pending'
    ");
    $stmt->execute([$reservation_id]);
    $reservation = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$reservation) {
        echo json_encode(['success' => false, 'message' => 'Reservierung nicht gefunden']);
        exit;
    }
    
    // Konflikte prüfen
    $conflicts = [];
    
    // Prüfe auf Überschneidungen mit anderen genehmigten Reservierungen
    $stmt = $db->prepare("
        SELECT r2.id, r2.requester_name, r2.start_datetime, r2.end_datetime, r2.reason, v2.name as vehicle_name
        FROM reservations r2
        LEFT JOIN vehicles v2 ON r2.vehicle_id = v2.id
        WHERE r2.vehicle_id = ? 
        AND r2.status = 'approved'
        AND r2.id != ?
        AND (
            (r2.start_datetime < ? AND r2.end_datetime > ?) OR
            (r2.start_datetime < ? AND r2.end_datetime > ?) OR
            (r2.start_datetime >= ? AND r2.end_datetime <= ?)
        )
        ORDER BY r2.start_datetime
    ");
    
    $stmt->execute([
        $reservation['vehicle_id'],
        $reservation_id,
        $reservation['end_datetime'],
        $reservation['start_datetime'],
        $reservation['start_datetime'],
        $reservation['end_datetime'],
        $reservation['start_datetime'],
        $reservation['end_datetime']
    ]);
    
    $conflicts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Konflikte formatieren
    $formatted_conflicts = [];
    foreach ($conflicts as $conflict) {
        $formatted_conflicts[] = [
            'id' => $conflict['id'],
            'requester_name' => $conflict['requester_name'],
            'vehicle_name' => $conflict['vehicle_name'],
            'start_datetime' => $conflict['start_datetime'],
            'end_datetime' => $conflict['end_datetime'],
            'reason' => $conflict['reason'],
            'start_date' => date('d.m.Y', strtotime($conflict['start_datetime'])),
            'start_time' => date('H:i', strtotime($conflict['start_datetime'])),
            'end_time' => date('H:i', strtotime($conflict['end_datetime']))
        ];
    }
    
    echo json_encode([
        'success' => true,
        'has_conflicts' => !empty($conflicts),
        'conflicts' => $formatted_conflicts,
        'conflict_count' => count($conflicts)
    ]);
    
} catch (Exception $e) {
    error_log("Konfliktprüfung Fehler: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Fehler bei der Konfliktprüfung: ' . $e->getMessage()]);
}
?>
