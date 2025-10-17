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
    
    // Reservierung mit Fahrzeugname laden
    $stmt = $db->prepare("
        SELECT r.*, v.name as vehicle_name 
        FROM reservations r 
        LEFT JOIN vehicles v ON r.vehicle_id = v.id 
        WHERE r.id = ? AND r.status = 'pending'
    ");
    $stmt->execute([$reservation_id]);
    $reservation = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$reservation) {
        throw new Exception('Reservierung nicht gefunden oder bereits bearbeitet');
    }
    
    if ($action === 'approve') {
        // Reservierung genehmigen
        $stmt = $db->prepare("UPDATE reservations SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?");
        $stmt->execute([$_SESSION['user_id'], $reservation_id]);
        
        // Google Calendar Event erstellen oder aktualisieren
        try {
            $vehicle_name = $reservation['vehicle_name'] ?? 'Unbekanntes Fahrzeug';
            $location = $reservation['location'] ?? '';
            
            // Prüfe ob bereits ein Event zum selben Zeitpunkt mit demselben Grund existiert
            $stmt = $db->prepare("
                SELECT ce.google_event_id, ce.title, GROUP_CONCAT(v.name ORDER BY v.name SEPARATOR ', ') as vehicles
                FROM calendar_events ce
                JOIN reservations r ON ce.reservation_id = r.id
                JOIN vehicles v ON r.vehicle_id = v.id
                WHERE ce.start_datetime = ? AND ce.end_datetime = ? AND r.reason = ? AND r.status = 'approved'
                GROUP BY ce.google_event_id, ce.title
                LIMIT 1
            ");
            $stmt->execute([$reservation['start_datetime'], $reservation['end_datetime'], $reservation['reason']]);
            $existing_event = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing_event) {
                // Event existiert bereits - Fahrzeug hinzufügen
                $existing_vehicles = $existing_event['vehicles'];
                $all_vehicles = $existing_vehicles . ', ' . $vehicle_name;
                $title = $all_vehicles . ' - ' . $reservation['reason'];
                
                // Bestehendes Event aktualisieren
                $google_event_id = update_google_calendar_event_title(
                    $existing_event['google_event_id'],
                    $title
                );
                
                if ($google_event_id) {
                    // Titel in der Datenbank aktualisieren
                    $stmt = $db->prepare("UPDATE calendar_events SET title = ? WHERE google_event_id = ?");
                    $stmt->execute([$title, $existing_event['google_event_id']]);
                    
                    // Neue Reservierung mit bestehendem Event verknüpfen
                    $stmt = $db->prepare("INSERT INTO calendar_events (reservation_id, google_event_id, title, start_datetime, end_datetime) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$reservation_id, $existing_event['google_event_id'], $title, $reservation['start_datetime'], $reservation['end_datetime']]);
                    
                    error_log("Google Calendar Event aktualisiert: " . $google_event_id . " - Titel: " . $title);
                }
            } else {
                // Neues Event erstellen
                $title = $vehicle_name . ' - ' . $reservation['reason'];
                
                $google_event_id = create_google_calendar_event(
                    $title,
                    $reservation['reason'],
                    $reservation['start_datetime'],
                    $reservation['end_datetime'],
                    $reservation_id,
                    $location
                );
                
                if ($google_event_id) {
                    error_log("Google Calendar Event erstellt: " . $google_event_id . " - Titel: " . $title);
                } else {
                    error_log("Google Calendar Event konnte nicht erstellt werden");
                }
            }
        } catch (Exception $e) {
            error_log("Google Calendar Fehler: " . $e->getMessage());
            // Fehler ignorieren, da Reservierung trotzdem genehmigt werden soll
        }
        
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
