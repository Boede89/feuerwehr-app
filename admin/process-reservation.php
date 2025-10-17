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

// Debug-Informationen
error_log("Process Reservation Debug - User ID: " . ($_SESSION['user_id'] ?? 'nicht gesetzt'));
error_log("Process Reservation Debug - is_admin: " . ($is_admin ? 'true' : 'false'));
error_log("Process Reservation Debug - can_reservations: " . ($can_reservations ? 'true' : 'false'));

// Prüfe ob Benutzer Reservierungen verwalten kann (entweder Admin oder can_reservations)
if (!$is_admin && !$can_reservations) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Keine Berechtigung für Reservierungen']);
    exit;
}

// JSON Input lesen
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['action']) || !isset($input['reservation_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Ungültige Parameter']);
    exit;
}

$action = $input['action'];
$reservation_id = (int)$input['reservation_id'];
$reason = $input['reason'] ?? '';

try {
    $db->beginTransaction();
    
    // Reservierung laden
    $stmt = $db->prepare("SELECT * FROM reservations WHERE id = ? AND status = 'pending'");
    $stmt->execute([$reservation_id]);
    $reservation = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$reservation) {
        throw new Exception('Reservierung nicht gefunden oder bereits bearbeitet');
    }
    
    if ($action === 'approve') {
        // Reservierung genehmigen
        $stmt = $db->prepare("UPDATE reservations SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?");
        $stmt->execute([$_SESSION['user_id'], $reservation_id]);
        
        // Google Calendar Event erstellen (falls gewünscht)
        // Hier könnte die Google Calendar Integration hinzugefügt werden
        
        // E-Mail an Antragsteller senden
        $subject = "✅ Reservierung genehmigt - " . $reservation['requester_name'];
        $message = "Hallo " . $reservation['requester_name'] . ",\n\n";
        $message .= "Ihre Reservierung wurde genehmigt.\n\n";
        $message .= "Details:\n";
        $message .= "- Fahrzeug: " . $reservation['vehicle_name'] . "\n";
        $message .= "- Datum: " . date('d.m.Y', strtotime($reservation['start_datetime'])) . "\n";
        $message .= "- Zeit: " . date('H:i', strtotime($reservation['start_datetime'])) . " - " . date('H:i', strtotime($reservation['end_datetime'])) . "\n";
        $message .= "- Grund: " . $reservation['reason'] . "\n\n";
        $message .= "Mit freundlichen Grüßen\nIhre Feuerwehr";
        
        send_email($reservation['requester_email'], $subject, $message);
        
        // Aktivitätslog
        log_activity($_SESSION['user_id'], 'reservation_approved', "Reservierung #$reservation_id genehmigt");
        
        $db->commit();
        echo json_encode(['success' => true, 'message' => 'Reservierung wurde genehmigt']);
        
    } elseif ($action === 'reject') {
        // Reservierung ablehnen
        if (empty($reason)) {
            throw new Exception('Ablehnungsgrund ist erforderlich');
        }
        
        $stmt = $db->prepare("UPDATE reservations SET status = 'rejected', rejection_reason = ?, approved_by = ?, approved_at = NOW() WHERE id = ?");
        $stmt->execute([$reason, $_SESSION['user_id'], $reservation_id]);
        
        // E-Mail an Antragsteller senden
        $subject = "❌ Reservierung abgelehnt - " . $reservation['requester_name'];
        $message = "Hallo " . $reservation['requester_name'] . ",\n\n";
        $message .= "Ihre Reservierung wurde leider abgelehnt.\n\n";
        $message .= "Details:\n";
        $message .= "- Fahrzeug: " . $reservation['vehicle_name'] . "\n";
        $message .= "- Datum: " . date('d.m.Y', strtotime($reservation['start_datetime'])) . "\n";
        $message .= "- Zeit: " . date('H:i', strtotime($reservation['start_datetime'])) . " - " . date('H:i', strtotime($reservation['end_datetime'])) . "\n";
        $message .= "- Grund: " . $reservation['reason'] . "\n\n";
        $message .= "Ablehnungsgrund:\n";
        $message .= $reason . "\n\n";
        $message .= "Mit freundlichen Grüßen\nIhre Feuerwehr";
        
        send_email($reservation['requester_email'], $subject, $message);
        
        // Aktivitätslog
        log_activity($_SESSION['user_id'], 'reservation_rejected', "Reservierung #$reservation_id abgelehnt: $reason");
        
        $db->commit();
        echo json_encode(['success' => true, 'message' => 'Reservierung wurde abgelehnt']);
        
    } else {
        throw new Exception('Ungültige Aktion');
    }
    
} catch (Exception $e) {
    $db->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
