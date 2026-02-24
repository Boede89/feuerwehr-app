<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Nicht angemeldet']);
    exit;
}

$is_admin = hasAdminPermission();
$can_reservations = has_permission('reservations');
if (!$is_admin && !$can_reservations) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Keine Berechtigung für Reservierungen']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$reservation_id = $input['reservation_id'] ?? null;

if (!$reservation_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Reservierungs-ID erforderlich']);
    exit;
}

try {
    $stmt = $db->prepare("
        SELECT rr.*, ro.name as room_name
        FROM room_reservations rr
        LEFT JOIN rooms ro ON rr.room_id = ro.id
        WHERE rr.id = ? AND rr.status = 'pending'
    ");
    $stmt->execute([$reservation_id]);
    $reservation = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$reservation) {
        echo json_encode(['success' => false, 'message' => 'Raumreservierung nicht gefunden']);
        exit;
    }

    $conflicts = get_room_conflicts($reservation['room_id'], $reservation['start_datetime'], $reservation['end_datetime'], $reservation_id);

    $formatted_conflicts = [];
    foreach ($conflicts as $conflict) {
        $formatted_conflicts[] = [
            'id' => (int)$conflict['id'],
            'requester_name' => $conflict['requester_name'],
            'room_name' => $conflict['room_name'],
            'start_datetime' => $conflict['start_datetime'],
            'end_datetime' => $conflict['end_datetime'],
            'reason' => $conflict['reason'],
            'start_date' => date('d.m.Y', strtotime($conflict['start_datetime'])),
            'start_time' => date('H:i', strtotime($conflict['start_datetime'])),
            'end_time' => date('H:i', strtotime($conflict['end_datetime'])),
        ];
    }

    echo json_encode([
        'success' => true,
        'has_conflicts' => !empty($conflicts),
        'conflicts' => $formatted_conflicts,
        'conflict_count' => count($conflicts),
    ]);

} catch (Exception $e) {
    error_log("Raum-Konfliktprüfung Fehler: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Fehler bei der Konfliktprüfung: ' . $e->getMessage()]);
}
