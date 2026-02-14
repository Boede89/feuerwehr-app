<?php
ob_start();
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../config/divera.php';

function output_json($data) {
    if (ob_get_level()) ob_end_clean();
    echo json_encode($data);
}

// Prüfe ob Benutzer eingeloggt ist
if (!is_logged_in()) {
    http_response_code(401);
    output_json(['success' => false, 'message' => 'Nicht angemeldet']);
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
    output_json(['success' => false, 'message' => 'Keine Berechtigung für Reservierungen']);
    exit;
}

// JSON Input lesen
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['action']) || !isset($input['reservation_id'])) {
    http_response_code(400);
    output_json(['success' => false, 'message' => 'Ungültige Parameter']);
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
        // Prüfe auf Konflikte vor der Genehmigung
        $conflicts = checkReservationConflicts($reservation);
        
        if (!empty($conflicts)) {
            // Konflikte gefunden - sende Warnung zurück
            output_json([
                'success' => false, 
                'has_conflicts' => true,
                'conflicts' => $conflicts,
                'message' => 'Konflikte gefunden. Bestätigung erforderlich.'
            ]);
            exit;
        }
        
        // Reservierung genehmigen
        $stmt = $db->prepare("UPDATE reservations SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?");
        $stmt->execute([$_SESSION['user_id'], $reservation_id]);
        
        // Terminübergabe-Einstellungen laden
        $divera_reservation_enabled = true;
        $google_calendar_reservation_enabled = true;
        $stmt_set = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('divera_reservation_enabled', 'google_calendar_reservation_enabled')");
        $stmt_set->execute();
        while ($row = $stmt_set->fetch(PDO::FETCH_ASSOC)) {
            if ($row['setting_key'] === 'divera_reservation_enabled') $divera_reservation_enabled = ($row['setting_value'] ?? '1') === '1';
            if ($row['setting_key'] === 'google_calendar_reservation_enabled') $google_calendar_reservation_enabled = ($row['setting_value'] ?? '1') === '1';
        }
        
        // Termin an Divera 24/7 senden (nur wenn aktiviert)
        $divera_sent = false;
        $needs_divera_key = false;
        $divera_error = null;
        if ($divera_reservation_enabled) {
            try {
                $group_ids_raw = '';
                $stmt_set = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'divera_reservation_group_ids' LIMIT 1");
                $stmt_set->execute();
                $row_set = $stmt_set->fetch(PDO::FETCH_ASSOC);
                if ($row_set) {
                    $group_ids_raw = trim((string) $row_set['setting_value']);
                }
                if ($group_ids_raw !== '') {
                    $reservation['_divera_group_ids'] = array_values(array_filter(array_map('intval', preg_split('/[\s,;]+/', $group_ids_raw))));
                }
                $divera_key = '';
                try {
                    $stmt_key = $db->prepare("SELECT divera_access_key FROM users WHERE id = ?");
                    $stmt_key->execute([$_SESSION['user_id']]);
                    $user_key_row = $stmt_key->fetch(PDO::FETCH_ASSOC);
                    $divera_key = trim((string) ($user_key_row['divera_access_key'] ?? ''));
                    $divera_key = preg_replace('/[\r\n\t\v]+/', '', $divera_key);
                } catch (Exception $e) {
                    if (strpos($e->getMessage(), 'divera_access_key') !== false || strpos($e->getMessage(), 'Unknown column') !== false) {
                        error_log("Divera: Spalte divera_access_key fehlt, nutze Einheits-Key.");
                    }
                }
                if ($divera_key === '') {
                    $divera_key = trim((string) ($divera_config['access_key'] ?? ''));
                    $divera_key = preg_replace('/[\r\n\t\v]+/', '', $divera_key);
                }
                $api_base = rtrim(trim((string) ($divera_config['api_base_url'] ?? '')), '/') ?: 'https://app.divera247.com';
                if ($divera_key !== '') {
                    $divera_event_id = null;
                    $divera_sent = send_reservation_to_divera($reservation, $divera_key, $api_base, $divera_error, $divera_event_id);
                    if ($divera_sent && $divera_event_id > 0) {
                        try {
                            $db->exec("ALTER TABLE reservations ADD COLUMN divera_event_id INT NULL DEFAULT NULL");
                        } catch (Exception $e) {
                            // Spalte existiert bereits
                        }
                        $stmt_upd = $db->prepare("UPDATE reservations SET divera_event_id = ? WHERE id = ?");
                        $stmt_upd->execute([$divera_event_id, $reservation_id]);
                        error_log("Reservierung #$reservation_id an Divera 24/7 übermittelt (Event-ID: $divera_event_id).");
                    } elseif ($divera_sent) {
                        error_log("Reservierung #$reservation_id an Divera 24/7 übermittelt.");
                    }
                } else {
                    $needs_divera_key = true;
                    error_log("Reservierung #$reservation_id: Weder im Profil noch in den Divera-Einstellungen ein Access Key hinterlegt.");
                }
            } catch (Exception $e) {
                error_log("Divera-Übermittlung Fehler: " . $e->getMessage());
            }
        }
        
        // Termin im Google Kalender anlegen (nur wenn aktiviert und konfiguriert)
        if ($google_calendar_reservation_enabled && function_exists('create_or_update_google_calendar_event')) {
            try {
                $stmt_gc = $db->prepare("SELECT setting_value FROM settings WHERE setting_key IN ('google_calendar_service_account_json', 'google_calendar_service_account_file', 'google_calendar_api_key') AND setting_value != '' LIMIT 1");
                $stmt_gc->execute();
                if ($stmt_gc->fetch()) {
                    $vehicle_name = $reservation['vehicle_name'] ?? 'Fahrzeug';
                    $res_reason = $reservation['reason'] ?? 'Reservierung';
                    $location = !empty($reservation['location']) ? $reservation['location'] : null;
                    create_or_update_google_calendar_event($vehicle_name, $res_reason, $reservation['start_datetime'], $reservation['end_datetime'], $reservation_id, $location);
                    error_log("Reservierung #$reservation_id im Google Kalender angelegt.");
                }
            } catch (Throwable $e) {
                error_log("Google Calendar Übermittlung Fehler: " . $e->getMessage());
            }
        }
        
        // E-Mail an Antragsteller senden
        $subject = "✅ Reservierung genehmigt - " . $reservation['requester_name'];
        $message = createApprovalEmailHTML($reservation);
        
        send_email($reservation['requester_email'], $subject, $message, '', true);
        
        // Aktivitätslog
        log_activity($_SESSION['user_id'], 'reservation_approved', "Reservierung #$reservation_id genehmigt");
        
        $db->commit();
        $out = [
            'success'          => true,
            'message'          => 'Reservierung wurde genehmigt',
            'divera_sent'      => $divera_sent,
            'needs_divera_key' => $needs_divera_key,
        ];
        if ($divera_error !== null) {
            $out['divera_error'] = $divera_error;
        }
        output_json($out);
        
    } elseif ($action === 'reject') {
        // Reservierung ablehnen
        if (empty($reason)) {
            throw new Exception('Ablehnungsgrund ist erforderlich');
        }
        
        $stmt = $db->prepare("UPDATE reservations SET status = 'rejected', rejection_reason = ?, approved_by = ?, approved_at = NOW() WHERE id = ?");
        $stmt->execute([$reason, $_SESSION['user_id'], $reservation_id]);
        
        // E-Mail an Antragsteller senden
        $subject = "❌ Reservierung abgelehnt - " . $reservation['requester_name'];
        $message = createRejectionEmailHTML($reservation, $reason);
        
        send_email($reservation['requester_email'], $subject, $message, '', true);
        
        // Aktivitätslog
        log_activity($_SESSION['user_id'], 'reservation_rejected', "Reservierung #$reservation_id abgelehnt: $reason");
        
        $db->commit();
        output_json(['success' => true, 'message' => 'Reservierung wurde abgelehnt']);
        
    } elseif ($action === 'approve_with_conflict_resolution') {
        // Reservierung genehmigen und Konflikte lösen
        $conflict_ids = $input['conflict_ids'] ?? [];
        
        error_log("Conflict Resolution - Reservation ID: " . $reservation_id);
        error_log("Conflict Resolution - Conflict IDs: " . json_encode($conflict_ids));
        
        if (!empty($conflict_ids)) {
            // Storniere konfliktierende Reservierungen
            foreach ($conflict_ids as $conflict_id) {
                // Storniere Reservierung - versuche zuerst 'cancelled', fallback zu 'rejected'
                try {
                    $stmt = $db->prepare("UPDATE reservations SET status = 'cancelled', approved_by = ?, approved_at = NOW() WHERE id = ?");
                    $stmt->execute([$_SESSION['user_id'], $conflict_id]);
                    error_log("Reservierung #$conflict_id auf 'cancelled' gesetzt");
                } catch (Exception $e) {
                    // Fallback zu 'rejected' falls 'cancelled' nicht existiert
                    error_log("Status 'cancelled' nicht verfügbar, verwende 'rejected': " . $e->getMessage());
                    $stmt = $db->prepare("UPDATE reservations SET status = 'rejected', approved_by = ?, approved_at = NOW() WHERE id = ?");
                    $stmt->execute([$_SESSION['user_id'], $conflict_id]);
                    error_log("Reservierung #$conflict_id auf 'rejected' gesetzt (Fallback)");
                }
                
                // Lade stornierte Reservierung für E-Mail
                $stmt = $db->prepare("SELECT r.*, v.name as vehicle_name FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id WHERE r.id = ?");
                $stmt->execute([$conflict_id]);
                $cancelled_reservation = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($cancelled_reservation) {
                    // Sende Stornierungs-E-Mail
                    $subject = "❌ Reservierung storniert - " . $cancelled_reservation['requester_name'];
                    $message = createCancellationEmailHTML($cancelled_reservation);
                    send_email($cancelled_reservation['requester_email'], $subject, $message, '', true);
                    
                    // Terminübergabe-Einstellungen für Löschung
                    $divera_reservation_enabled = true;
                    $google_calendar_reservation_enabled = true;
                    $stmt_set = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('divera_reservation_enabled', 'google_calendar_reservation_enabled')");
                    $stmt_set->execute();
                    while ($row = $stmt_set->fetch(PDO::FETCH_ASSOC)) {
                        if ($row['setting_key'] === 'divera_reservation_enabled') $divera_reservation_enabled = ($row['setting_value'] ?? '1') === '1';
                        if ($row['setting_key'] === 'google_calendar_reservation_enabled') $google_calendar_reservation_enabled = ($row['setting_value'] ?? '1') === '1';
                    }
                    
                    // Divera-Termin löschen (wenn aktiviert)
                    if ($divera_reservation_enabled) {
                        try {
                            $stmt = $db->prepare("SELECT divera_event_id FROM reservations WHERE id = ?");
                            $stmt->execute([$conflict_id]);
                            $row = $stmt->fetch(PDO::FETCH_ASSOC);
                            $divera_event_id = (int) ($row['divera_event_id'] ?? 0);
                            if ($divera_event_id > 0) {
                                require_once __DIR__ . '/../config/divera.php';
                                $divera_key = trim((string) ($divera_config['access_key'] ?? ''));
                                if ($divera_key === '') {
                                    $stmt_u = $db->prepare("SELECT divera_access_key FROM users WHERE id = ?");
                                    $stmt_u->execute([$_SESSION['user_id'] ?? 0]);
                                    $uk = $stmt_u->fetch(PDO::FETCH_ASSOC);
                                    $divera_key = trim((string) ($uk['divera_access_key'] ?? ''));
                                }
                                $api_base = rtrim(trim((string) ($divera_config['api_base_url'] ?? '')), '/') ?: 'https://app.divera247.com';
                                if ($divera_key !== '' && delete_divera_event($divera_event_id, $divera_key, $api_base)) {
                                    error_log("Konflikt-Reservierung #$conflict_id: Divera Event gelöscht: " . $divera_event_id);
                                }
                            }
                        } catch (Exception $e) {
                            error_log("Divera Löschung bei Konflikt: " . $e->getMessage());
                        }
                    }
                    
                    // Google Calendar Event löschen (wenn aktiviert) und lokale Verknüpfung entfernen
                    try {
                        $stmt = $db->prepare("SELECT google_event_id FROM calendar_events WHERE reservation_id = ?");
                        $stmt->execute([$conflict_id]);
                        $calendar_event = $stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($google_calendar_reservation_enabled && $calendar_event && !empty($calendar_event['google_event_id'])) {
                            error_log("Lösche Google Calendar Event: " . $calendar_event['google_event_id']);
                            $delete_result = delete_google_calendar_event($calendar_event['google_event_id']);
                            error_log("Google Calendar Löschung Ergebnis: " . ($delete_result ? 'Erfolg' : 'Fehler'));
                        }
                        
                        // Lokale Verknüpfung immer entfernen
                        $stmt = $db->prepare("DELETE FROM calendar_events WHERE reservation_id = ?");
                        $stmt->execute([$conflict_id]);
                        error_log("Calendar Event Eintrag gelöscht für Reservierung: " . $conflict_id);
                    } catch (Exception $e) {
                        error_log("Google Calendar Löschung Fehler: " . $e->getMessage());
                    }
                    
                    // Logge Aktivität
                    log_activity($_SESSION['user_id'], 'reservation_cancelled', "Reservierung #$conflict_id storniert wegen Konflikt mit #$reservation_id");
                }
            }
        }
        
        // Genehmige die ursprüngliche Reservierung
        $stmt = $db->prepare("UPDATE reservations SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?");
        $stmt->execute([$_SESSION['user_id'], $reservation_id]);
        
        // Terminübergabe-Einstellungen laden
        $divera_reservation_enabled = true;
        $google_calendar_reservation_enabled = true;
        $stmt_set = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('divera_reservation_enabled', 'google_calendar_reservation_enabled')");
        $stmt_set->execute();
        while ($row = $stmt_set->fetch(PDO::FETCH_ASSOC)) {
            if ($row['setting_key'] === 'divera_reservation_enabled') $divera_reservation_enabled = ($row['setting_value'] ?? '1') === '1';
            if ($row['setting_key'] === 'google_calendar_reservation_enabled') $google_calendar_reservation_enabled = ($row['setting_value'] ?? '1') === '1';
        }
        
        // Termin an Divera 24/7 senden (nur wenn aktiviert)
        $divera_sent = false;
        $needs_divera_key = false;
        $divera_error = null;
        if ($divera_reservation_enabled) {
            try {
                $group_ids_raw = '';
                $stmt_set = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'divera_reservation_group_ids' LIMIT 1");
                $stmt_set->execute();
                $row_set = $stmt_set->fetch(PDO::FETCH_ASSOC);
                if ($row_set) {
                    $group_ids_raw = trim((string) $row_set['setting_value']);
                }
                if ($group_ids_raw !== '') {
                    $reservation['_divera_group_ids'] = array_values(array_filter(array_map('intval', preg_split('/[\s,;]+/', $group_ids_raw))));
                }
                $divera_key = '';
                try {
                    $stmt_key = $db->prepare("SELECT divera_access_key FROM users WHERE id = ?");
                    $stmt_key->execute([$_SESSION['user_id']]);
                    $user_key_row = $stmt_key->fetch(PDO::FETCH_ASSOC);
                    $divera_key = trim((string) ($user_key_row['divera_access_key'] ?? ''));
                    $divera_key = preg_replace('/[\r\n\t\v]+/', '', $divera_key);
                } catch (Exception $e) {
                    if (strpos($e->getMessage(), 'divera_access_key') !== false || strpos($e->getMessage(), 'Unknown column') !== false) {
                        error_log("Divera: Spalte divera_access_key fehlt, nutze Einheits-Key.");
                    }
                }
                if ($divera_key === '') {
                    $divera_key = trim((string) ($divera_config['access_key'] ?? ''));
                    $divera_key = preg_replace('/[\r\n\t\v]+/', '', $divera_key);
                }
                $api_base = rtrim(trim((string) ($divera_config['api_base_url'] ?? '')), '/') ?: 'https://app.divera247.com';
                if ($divera_key !== '') {
                    $divera_event_id = null;
                    $divera_sent = send_reservation_to_divera($reservation, $divera_key, $api_base, $divera_error, $divera_event_id);
                    if ($divera_sent && $divera_event_id > 0) {
                        try {
                            $db->exec("ALTER TABLE reservations ADD COLUMN divera_event_id INT NULL DEFAULT NULL");
                        } catch (Exception $e) {
                            // Spalte existiert bereits
                        }
                        $stmt_upd = $db->prepare("UPDATE reservations SET divera_event_id = ? WHERE id = ?");
                        $stmt_upd->execute([$divera_event_id, $reservation_id]);
                        error_log("Reservierung #$reservation_id an Divera 24/7 übermittelt (mit Konfliktlösung, Event-ID: $divera_event_id).");
                    } elseif ($divera_sent) {
                        error_log("Reservierung #$reservation_id an Divera 24/7 übermittelt (mit Konfliktlösung).");
                    }
                } else {
                    $needs_divera_key = true;
                }
            } catch (Exception $e) {
                error_log("Divera-Übermittlung Fehler: " . $e->getMessage());
            }
        }
        
        // Termin im Google Kalender anlegen (nur wenn aktiviert und konfiguriert)
        if ($google_calendar_reservation_enabled && function_exists('create_or_update_google_calendar_event')) {
            try {
                $stmt_gc = $db->prepare("SELECT setting_value FROM settings WHERE setting_key IN ('google_calendar_service_account_json', 'google_calendar_service_account_file', 'google_calendar_api_key') AND setting_value != '' LIMIT 1");
                $stmt_gc->execute();
                if ($stmt_gc->fetch()) {
                    $vehicle_name = $reservation['vehicle_name'] ?? 'Fahrzeug';
                    $res_reason = $reservation['reason'] ?? 'Reservierung';
                    $location = !empty($reservation['location']) ? $reservation['location'] : null;
                    create_or_update_google_calendar_event($vehicle_name, $res_reason, $reservation['start_datetime'], $reservation['end_datetime'], $reservation_id, $location);
                    error_log("Reservierung #$reservation_id im Google Kalender angelegt (mit Konfliktlösung).");
                }
            } catch (Throwable $e) {
                error_log("Google Calendar Übermittlung Fehler: " . $e->getMessage());
            }
        }
        
        // E-Mail an Antragsteller senden
        $subject = "✅ Reservierung genehmigt - " . $reservation['requester_name'];
        $message = createApprovalEmailHTML($reservation);
        send_email($reservation['requester_email'], $subject, $message, '', true);
        
        // Aktivitätslog
        log_activity($_SESSION['user_id'], 'reservation_approved', "Reservierung #$reservation_id genehmigt mit Konfliktlösung");
        
        $db->commit();
        $out = [
            'success'          => true,
            'message'          => 'Reservierung wurde genehmigt und Konflikte gelöst',
            'divera_sent'      => $divera_sent,
            'needs_divera_key' => $needs_divera_key,
        ];
        if ($divera_error !== null) {
            $out['divera_error'] = $divera_error;
        }
        output_json($out);
        
    } else {
        throw new Exception('Ungültige Aktion');
    }
    
} catch (Throwable $e) {
    if (isset($db)) {
        try { $db->rollBack(); } catch (Throwable $rb) {}
    }
    error_log("Process Reservation Error: " . $e->getMessage());
    error_log("Process Reservation Error Trace: " . $e->getTraceAsString());
    http_response_code(500);
    output_json(['success' => false, 'message' => 'Server-Fehler: ' . $e->getMessage()]);
}

// Konfliktprüfung für Reservierung
function checkReservationConflicts($reservation) {
    global $db;
    
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
        $reservation['id'],
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
    
    return $formatted_conflicts;
}

// HTML-E-Mail für Stornierung erstellen
function createCancellationEmailHTML($reservation) {
    $vehicle_name = htmlspecialchars($reservation['vehicle_name'] ?? 'Unbekanntes Fahrzeug');
    $requester_name = htmlspecialchars($reservation['requester_name'] ?? '');
    $reason = htmlspecialchars($reservation['reason'] ?? '');
    $location = htmlspecialchars($reservation['location'] ?? 'Nicht angegeben');
    $start_date = date('d.m.Y', strtotime($reservation['start_datetime']));
    $start_time = date('H:i', strtotime($reservation['start_datetime']));
    $end_time = date('H:i', strtotime($reservation['end_datetime']));
    
    return '
    <!DOCTYPE html>
    <html lang="de">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Reservierung storniert</title>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background-color: #f4f4f4; }
            .container { max-width: 600px; margin: 20px auto; background: white; border-radius: 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); overflow: hidden; }
            .header { background: linear-gradient(135deg, #dc3545, #e74c3c); color: white; padding: 30px; text-align: center; }
            .header h1 { margin: 0; font-size: 28px; }
            .header .icon { font-size: 48px; margin-bottom: 10px; }
            .content { padding: 30px; }
            .cancellation-badge { background: #f8d7da; color: #721c24; padding: 15px; border-radius: 8px; margin-bottom: 25px; text-align: center; font-weight: bold; }
            .details { background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0; }
            .detail-row { display: flex; margin-bottom: 12px; align-items: center; }
            .detail-label { font-weight: bold; color: #495057; width: 120px; flex-shrink: 0; }
            .detail-value { color: #212529; }
            .reason-box { background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; border-radius: 8px; margin: 20px 0; }
            .reason-box h4 { margin-top: 0; color: #856404; }
            .footer { background: #f8f9fa; padding: 20px; text-align: center; color: #6c757d; border-top: 1px solid #dee2e6; }
            .highlight { color: #dc3545; font-weight: bold; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <div class="icon">❌</div>
                <h1>Reservierung storniert</h1>
            </div>
            <div class="content">
                <div class="cancellation-badge">
                    😔 Ihre Reservierung musste leider storniert werden
                </div>
                
                <p>Hallo <strong>' . $requester_name . '</strong>,</p>
                
                <p>wir bedauern, Ihnen mitteilen zu müssen, dass Ihre Reservierung storniert werden musste, da es zu einer Überschneidung mit einer anderen Reservierung gekommen ist.</p>
                
                <div class="details">
                    <h3 style="margin-top: 0; color: #495057;">📋 Stornierte Reservierung</h3>
                    <div class="detail-row">
                        <div class="detail-label">🚗 Fahrzeug:</div>
                        <div class="detail-value highlight">' . $vehicle_name . '</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">📅 Datum:</div>
                        <div class="detail-value">' . $start_date . '</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">🕐 Zeit:</div>
                        <div class="detail-value">' . $start_time . ' - ' . $end_time . ' Uhr</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">📍 Standort:</div>
                        <div class="detail-value">' . $location . '</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">📝 Grund:</div>
                        <div class="detail-value">' . $reason . '</div>
                    </div>
                </div>
                
                <div class="reason-box">
                    <h4>🔄 Stornierungsgrund</h4>
                    <p>Ihre Reservierung musste storniert werden, da es zu einer Überschneidung mit einer anderen Reservierung gekommen ist. Wir entschuldigen uns für die Unannehmlichkeiten.</p>
                </div>
                
                <p>Bitte wenden Sie sich bei Fragen gerne an uns. Wir helfen Ihnen gerne bei der Suche nach alternativen Terminen.</p>
            </div>
            <div class="footer">
                <p><strong>Mit freundlichen Grüßen</strong><br>Ihre Feuerwehr</p>
            </div>
        </div>
    </body>
    </html>';
}

// HTML-E-Mail für Genehmigung erstellen
function createApprovalEmailHTML($reservation) {
    $vehicle_name = htmlspecialchars($reservation['vehicle_name'] ?? 'Unbekanntes Fahrzeug');
    $requester_name = htmlspecialchars($reservation['requester_name'] ?? '');
    $reason = htmlspecialchars($reservation['reason'] ?? '');
    $location = htmlspecialchars($reservation['location'] ?? 'Nicht angegeben');
    $start_date = date('d.m.Y', strtotime($reservation['start_datetime']));
    $start_time = date('H:i', strtotime($reservation['start_datetime']));
    $end_time = date('H:i', strtotime($reservation['end_datetime']));
    
    return '
    <!DOCTYPE html>
    <html lang="de">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Reservierung genehmigt</title>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background-color: #f4f4f4; }
            .container { max-width: 600px; margin: 20px auto; background: white; border-radius: 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); overflow: hidden; }
            .header { background: linear-gradient(135deg, #28a745, #20c997); color: white; padding: 30px; text-align: center; }
            .header h1 { margin: 0; font-size: 28px; }
            .header .icon { font-size: 48px; margin-bottom: 10px; }
            .content { padding: 30px; }
            .success-badge { background: #d4edda; color: #155724; padding: 15px; border-radius: 8px; margin-bottom: 25px; text-align: center; font-weight: bold; }
            .details { background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0; }
            .detail-row { display: flex; margin-bottom: 12px; align-items: center; }
            .detail-label { font-weight: bold; color: #495057; width: 120px; flex-shrink: 0; }
            .detail-value { color: #212529; }
            .footer { background: #f8f9fa; padding: 20px; text-align: center; color: #6c757d; border-top: 1px solid #dee2e6; }
            .highlight { color: #28a745; font-weight: bold; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <div class="icon">✅</div>
                <h1>Reservierung genehmigt!</h1>
            </div>
            <div class="content">
                <div class="success-badge">
                    🎉 Ihre Fahrzeugreservierung wurde erfolgreich genehmigt!
                </div>
                
                <p>Hallo <strong>' . $requester_name . '</strong>,</p>
                
                <p>wir freuen uns, Ihnen mitteilen zu können, dass Ihre Reservierung genehmigt wurde.</p>
                
                <div class="details">
                    <h3 style="margin-top: 0; color: #495057;">📋 Reservierungsdetails</h3>
                    <div class="detail-row">
                        <div class="detail-label">🚗 Fahrzeug:</div>
                        <div class="detail-value highlight">' . $vehicle_name . '</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">📅 Datum:</div>
                        <div class="detail-value">' . $start_date . '</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">🕐 Zeit:</div>
                        <div class="detail-value">' . $start_time . ' - ' . $end_time . ' Uhr</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">📍 Standort:</div>
                        <div class="detail-value">' . $location . '</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">📝 Grund:</div>
                        <div class="detail-value">' . $reason . '</div>
                    </div>
                </div>
                
                <p>Bitte erscheinen Sie pünktlich zum vereinbarten Zeitpunkt. Bei Fragen stehen wir Ihnen gerne zur Verfügung.</p>
            </div>
            <div class="footer">
                <p><strong>Mit freundlichen Grüßen</strong><br>Ihre Feuerwehr</p>
            </div>
        </div>
    </body>
    </html>';
}

// HTML-E-Mail für Ablehnung erstellen
function createRejectionEmailHTML($reservation, $rejection_reason) {
    $vehicle_name = htmlspecialchars($reservation['vehicle_name'] ?? 'Unbekanntes Fahrzeug');
    $requester_name = htmlspecialchars($reservation['requester_name'] ?? '');
    $reason = htmlspecialchars($reservation['reason'] ?? '');
    $location = htmlspecialchars($reservation['location'] ?? 'Nicht angegeben');
    $start_date = date('d.m.Y', strtotime($reservation['start_datetime']));
    $start_time = date('H:i', strtotime($reservation['start_datetime']));
    $end_time = date('H:i', strtotime($reservation['end_datetime']));
    $rejection_reason = htmlspecialchars($rejection_reason);
    
    return '
    <!DOCTYPE html>
    <html lang="de">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Reservierung abgelehnt</title>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background-color: #f4f4f4; }
            .container { max-width: 600px; margin: 20px auto; background: white; border-radius: 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); overflow: hidden; }
            .header { background: linear-gradient(135deg, #dc3545, #e74c3c); color: white; padding: 30px; text-align: center; }
            .header h1 { margin: 0; font-size: 28px; }
            .header .icon { font-size: 48px; margin-bottom: 10px; }
            .content { padding: 30px; }
            .rejection-badge { background: #f8d7da; color: #721c24; padding: 15px; border-radius: 8px; margin-bottom: 25px; text-align: center; font-weight: bold; }
            .details { background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0; }
            .detail-row { display: flex; margin-bottom: 12px; align-items: center; }
            .detail-label { font-weight: bold; color: #495057; width: 120px; flex-shrink: 0; }
            .detail-value { color: #212529; }
            .rejection-reason { background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; border-radius: 8px; margin: 20px 0; }
            .rejection-reason h4 { margin-top: 0; color: #856404; }
            .footer { background: #f8f9fa; padding: 20px; text-align: center; color: #6c757d; border-top: 1px solid #dee2e6; }
            .highlight { color: #dc3545; font-weight: bold; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <div class="icon">❌</div>
                <h1>Reservierung abgelehnt</h1>
            </div>
            <div class="content">
                <div class="rejection-badge">
                    😔 Leider mussten wir Ihre Reservierung ablehnen
                </div>
                
                <p>Hallo <strong>' . $requester_name . '</strong>,</p>
                
                <p>wir bedauern, Ihnen mitteilen zu müssen, dass Ihre Reservierung nicht genehmigt werden konnte.</p>
                
                <div class="details">
                    <h3 style="margin-top: 0; color: #495057;">📋 Reservierungsdetails</h3>
                    <div class="detail-row">
                        <div class="detail-label">🚗 Fahrzeug:</div>
                        <div class="detail-value highlight">' . $vehicle_name . '</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">📅 Datum:</div>
                        <div class="detail-value">' . $start_date . '</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">🕐 Zeit:</div>
                        <div class="detail-value">' . $start_time . ' - ' . $end_time . ' Uhr</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">📍 Standort:</div>
                        <div class="detail-value">' . $location . '</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">📝 Grund:</div>
                        <div class="detail-value">' . $reason . '</div>
                    </div>
                </div>
                
                <div class="rejection-reason">
                    <h4>🚫 Ablehnungsgrund</h4>
                    <p>' . nl2br($rejection_reason) . '</p>
                </div>
                
                <p>Bitte wenden Sie sich bei Fragen gerne an uns. Wir helfen Ihnen gerne bei der Suche nach alternativen Terminen.</p>
            </div>
            <div class="footer">
                <p><strong>Mit freundlichen Grüßen</strong><br>Ihre Feuerwehr</p>
            </div>
        </div>
    </body>
    </html>';
}
?>
