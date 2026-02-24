<?php
ob_start();
session_start();
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once __DIR__ . '/../includes/einheit-settings-helper.php';

function output_json($data) {
    while (ob_get_level()) ob_end_clean();
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!is_logged_in()) {
    http_response_code(401);
    output_json(['success' => false, 'message' => 'Nicht angemeldet']);
    exit;
}

$is_admin = hasAdminPermission();
$can_reservations = has_permission('reservations');
if (!$is_admin && !$can_reservations) {
    http_response_code(403);
    output_json(['success' => false, 'message' => 'Keine Berechtigung für Reservierungen']);
    exit;
}

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
    $stmt = $db->prepare("
        SELECT rr.*, ro.name as room_name, COALESCE(rr.einheit_id, ro.einheit_id) as einheit_id
        FROM room_reservations rr
        JOIN rooms ro ON rr.room_id = ro.id
        WHERE rr.id = ? AND rr.status = 'pending'
    ");
    $stmt->execute([$reservation_id]);
    $reservation = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$reservation) {
        output_json(['success' => false, 'message' => 'Raumreservierung nicht gefunden oder bereits bearbeitet']);
        exit;
    }

    if ($action === 'approve') {
        $conflicts = get_room_conflicts($reservation['room_id'], $reservation['start_datetime'], $reservation['end_datetime'], $reservation_id);
        if (!empty($conflicts)) {
            $formatted = [];
            foreach ($conflicts as $c) {
                $formatted[] = [
                    'id' => (int)$c['id'],
                    'requester_name' => $c['requester_name'],
                    'room_name' => $c['room_name'],
                    'start_datetime' => $c['start_datetime'],
                    'end_datetime' => $c['end_datetime'],
                    'reason' => $c['reason'],
                    'start_date' => date('d.m.Y', strtotime($c['start_datetime'])),
                    'start_time' => date('H:i', strtotime($c['start_datetime'])),
                    'end_time' => date('H:i', strtotime($c['end_datetime'])),
                ];
            }
            output_json([
                'success' => false,
                'has_conflicts' => true,
                'conflicts' => $formatted,
                'message' => 'Der Raum ist für diesen Zeitraum bereits genehmigt reserviert.'
            ]);
            exit;
        }
        $stmt = $db->prepare("UPDATE room_reservations SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?");
        $stmt->execute([$_SESSION['user_id'], $reservation_id]);

        $calendar_result = apply_room_calendar_settings($db, $reservation, $reservation_id);

        $subject = "✅ Raumreservierung genehmigt - " . $reservation['requester_name'];
        $message = createRoomApprovalEmailHTML($reservation);
        $einheit_id = (int)($reservation['einheit_id'] ?? 0);
        if ($einheit_id > 0) {
            send_email_for_einheit($reservation['requester_email'], $subject, $message, $einheit_id, true);
        }
        log_activity($_SESSION['user_id'], 'room_reservation_approved', "Raumreservierung #$reservation_id genehmigt");

        output_json([
            'success' => true,
            'message' => 'Raumreservierung wurde genehmigt',
            'divera_sent' => $calendar_result['divera_sent'],
            'needs_divera_key' => $calendar_result['needs_divera_key'],
            'divera_error' => $calendar_result['divera_error'],
        ]);

    } elseif ($action === 'approve_with_conflict_resolution') {
        $conflict_ids = $input['conflict_ids'] ?? [];
        if (!empty($conflict_ids)) {
            foreach ($conflict_ids as $conflict_id) {
                $conflict_id = (int)$conflict_id;
                if ($conflict_id <= 0) continue;
                $stmt = $db->prepare("UPDATE room_reservations SET status = 'rejected', rejection_reason = ?, approved_by = ?, approved_at = NOW() WHERE id = ?");
                $stmt->execute(['Storniert wegen Konflikt mit neuer Reservierung', $_SESSION['user_id'], $conflict_id]);

                $stmt = $db->prepare("SELECT rr.*, ro.name as room_name, COALESCE(rr.einheit_id, ro.einheit_id) as einheit_id FROM room_reservations rr JOIN rooms ro ON rr.room_id = ro.id WHERE rr.id = ?");
                $stmt->execute([$conflict_id]);
                $cancelled = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($cancelled) {
                    $subject = "❌ Raumreservierung storniert - " . $cancelled['requester_name'];
                    $message = createRoomRejectionEmailHTML($cancelled, 'Storniert wegen Konflikt mit neuer Reservierung');
                    $cancelled_einheit = (int)($cancelled['einheit_id'] ?? 0);
                    if ($cancelled_einheit > 0) {
                        send_email_for_einheit($cancelled['requester_email'], $subject, $message, $cancelled_einheit, true);
                    }
                    log_activity($_SESSION['user_id'], 'room_reservation_rejected', "Raumreservierung #$conflict_id storniert wegen Konflikt mit #$reservation_id");
                }
            }
        }
        $stmt = $db->prepare("UPDATE room_reservations SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?");
        $stmt->execute([$_SESSION['user_id'], $reservation_id]);

        $calendar_result = apply_room_calendar_settings($db, $reservation, $reservation_id);

        $subject = "✅ Raumreservierung genehmigt - " . $reservation['requester_name'];
        $message = createRoomApprovalEmailHTML($reservation);
        $einheit_id = (int)($reservation['einheit_id'] ?? 0);
        if ($einheit_id > 0) {
            send_email_for_einheit($reservation['requester_email'], $subject, $message, $einheit_id, true);
        }
        log_activity($_SESSION['user_id'], 'room_reservation_approved', "Raumreservierung #$reservation_id genehmigt mit Konfliktlösung");

        output_json([
            'success' => true,
            'message' => 'Raumreservierung wurde genehmigt und Konflikte gelöst',
            'divera_sent' => $calendar_result['divera_sent'],
            'needs_divera_key' => $calendar_result['needs_divera_key'],
            'divera_error' => $calendar_result['divera_error'],
        ]);

    } elseif ($action === 'reject') {
        if (empty($reason)) {
            output_json(['success' => false, 'message' => 'Ablehnungsgrund ist erforderlich']);
            exit;
        }
        $stmt = $db->prepare("UPDATE room_reservations SET status = 'rejected', rejection_reason = ?, approved_by = ?, approved_at = NOW() WHERE id = ?");
        $stmt->execute([$reason, $_SESSION['user_id'], $reservation_id]);

        $subject = "❌ Raumreservierung abgelehnt - " . $reservation['requester_name'];
        $message = createRoomRejectionEmailHTML($reservation, $reason);
        $einheit_id = (int)($reservation['einheit_id'] ?? 0);
        if ($einheit_id > 0) {
            send_email_for_einheit($reservation['requester_email'], $subject, $message, $einheit_id, true);
        }
        log_activity($_SESSION['user_id'], 'room_reservation_rejected', "Raumreservierung #$reservation_id abgelehnt: $reason");

        output_json(['success' => true, 'message' => 'Raumreservierung wurde abgelehnt']);
    } else {
        output_json(['success' => false, 'message' => 'Ungültige Aktion']);
    }
} catch (Throwable $e) {
    error_log("Process Room Reservation Error: " . $e->getMessage());
    http_response_code(500);
    output_json(['success' => false, 'message' => 'Server-Fehler: ' . $e->getMessage()]);
}

function createRoomApprovalEmailHTML($reservation) {
    $room_name = htmlspecialchars($reservation['room_name'] ?? 'Unbekannt');
    $requester_name = htmlspecialchars($reservation['requester_name'] ?? '');
    $reason = htmlspecialchars($reservation['reason'] ?? '');
    $location = htmlspecialchars($reservation['location'] ?? 'Nicht angegeben');
    $start_date = date('d.m.Y', strtotime($reservation['start_datetime']));
    $start_time = date('H:i', strtotime($reservation['start_datetime']));
    $end_time = date('H:i', strtotime($reservation['end_datetime']));
    return '
    <!DOCTYPE html><html lang="de"><head><meta charset="UTF-8"><title>Raumreservierung genehmigt</title></head><body>
    <div style="font-family: Arial, sans-serif; max-width: 600px;">
        <h2>✅ Raumreservierung genehmigt!</h2>
        <p>Hallo <strong>' . $requester_name . '</strong>,</p>
        <p>Ihre Raumreservierung wurde genehmigt.</p>
        <div style="background: #f8f9fa; padding: 15px; border-radius: 8px;">
            <p><strong>Raum:</strong> ' . $room_name . '</p>
            <p><strong>Datum:</strong> ' . $start_date . '</p>
            <p><strong>Zeit:</strong> ' . $start_time . ' - ' . $end_time . ' Uhr</p>
            <p><strong>Ort:</strong> ' . $location . '</p>
            <p><strong>Grund:</strong> ' . $reason . '</p>
        </div>
        <p>Mit freundlichen Grüßen,<br>Ihre Feuerwehr</p>
    </div></body></html>';
}

/**
 * Wendet Divera- und Google-Kalender-Einstellungen für Raumreservierungen an (nur wenn aktiviert).
 * @return array ['divera_sent' => bool, 'needs_divera_key' => bool, 'divera_error' => array|null]
 */
function apply_room_calendar_settings($db, $reservation, $reservation_id) {
    $result = ['divera_sent' => false, 'needs_divera_key' => false, 'divera_error' => null];
    $einheit_id = (int)($reservation['einheit_id'] ?? 0);
    if ($einheit_id <= 0 && !empty($reservation['room_id'])) {
        $stmt_ro = $db->prepare("SELECT einheit_id FROM rooms WHERE id = ?");
        $stmt_ro->execute([$reservation['room_id']]);
        $einheit_id = (int)($stmt_ro->fetchColumn() ?: 0);
    }
    $settings = load_settings_for_einheit($db, $einheit_id > 0 ? $einheit_id : null);
    // Kein Fallback auf globale Divera-Einstellungen – nur Einheitseinstellungen.

    $room_divera_enabled = ($settings['room_divera_reservation_enabled'] ?? '0') === '1';
    $room_google_enabled = ($settings['room_google_calendar_reservation_enabled'] ?? '0') === '1';

    if ($room_divera_enabled) {
        try {
            require_once __DIR__ . '/../config/divera.php';
            global $divera_config;
            if ($einheit_id > 0) {
                apply_divera_config_for_einheit($db, $einheit_id);
            } else {
                $stmt_dv = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('divera_access_key', 'divera_api_base_url')");
                $stmt_dv->execute();
                while ($row = $stmt_dv->fetch(PDO::FETCH_ASSOC)) {
                    if ($row['setting_key'] === 'divera_access_key' && trim((string)$row['setting_value']) !== '') {
                        $divera_config['access_key'] = trim((string)$row['setting_value']);
                    }
                    if ($row['setting_key'] === 'divera_api_base_url' && trim((string)$row['setting_value']) !== '') {
                        $divera_config['api_base_url'] = rtrim(trim((string)$row['setting_value']), '/');
                    }
                }
            }
            // Gleiche Reihenfolge wie bei Fahrzeugreservierungen: zuerst User-Key (Profil), dann Einheits-/Global-Key
            $divera_key = '';
            try {
                if (isset($_SESSION['user_id'])) {
                    $stmt = $db->prepare("SELECT divera_access_key FROM users WHERE id = ?");
                    $stmt->execute([$_SESSION['user_id']]);
                    $uk = $stmt->fetch(PDO::FETCH_ASSOC);
                    $divera_key = trim(preg_replace('/[\r\n\t\v]+/', '', (string)($uk['divera_access_key'] ?? '')));
                }
            } catch (Exception $e) {}
            if ($divera_key === '') {
                $divera_key = trim(preg_replace('/[\r\n\t\v]+/', '', (string)($divera_config['access_key'] ?? '')));
            }
            if ($divera_key === '') {
                $stmt_dv = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'divera_access_key' AND setting_value != '' LIMIT 1");
                $stmt_dv->execute();
                $divera_key = trim(preg_replace('/[\r\n\t\v]+/', '', (string)($stmt_dv->fetchColumn() ?: '')));
            }
            $api_base = rtrim(trim((string)($divera_config['api_base_url'] ?? '')), '/') ?: 'https://app.divera247.com';
            if ($divera_key !== '') {
                $res_for_divera = $reservation;
                $res_for_divera['vehicle_name'] = $reservation['room_name'] ?? 'Raum';
                $group_ids = [];
                $default_id = (int)trim((string)($settings['room_divera_reservation_default_group_id'] ?? ''));
                if ($default_id > 0) $group_ids = [$default_id];
                if (empty($group_ids) && !empty($settings['divera_reservation_groups'])) {
                    $groups = json_decode($settings['divera_reservation_groups'], true);
                    if (is_array($groups) && !empty($groups)) {
                        $first_id = (int)($groups[0]['id'] ?? 0);
                        if ($first_id > 0) $group_ids = [$first_id];
                    }
                }
                if (!empty($group_ids)) $res_for_divera['_divera_group_ids'] = $group_ids;
                $divera_error = null;
                $divera_event_id = null;
                $result['divera_sent'] = send_reservation_to_divera($res_for_divera, $divera_key, $api_base, $divera_error, $divera_event_id, true);
                $result['divera_error'] = $divera_error;
                if ($result['divera_sent'] && $divera_event_id > 0) {
                    try {
                        $db->exec("ALTER TABLE room_reservations ADD COLUMN divera_event_id INT NULL");
                    } catch (Exception $e) {}
                    $stmt = $db->prepare("UPDATE room_reservations SET divera_event_id = ? WHERE id = ?");
                    $stmt->execute([$divera_event_id, $reservation_id]);
                }
            } else {
                $result['needs_divera_key'] = true;
            }
        } catch (Exception $e) {
            error_log("Raum Divera: " . $e->getMessage());
        }
    }

    if ($room_google_enabled && function_exists('create_google_calendar_event')) {
        try {
            $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key IN ('google_calendar_service_account_json', 'google_calendar_service_account_file', 'google_calendar_api_key') AND setting_value != '' LIMIT 1");
            $stmt->execute();
            if ($stmt->fetch()) {
                $room_name = $reservation['room_name'] ?? 'Raum';
                $title = $room_name . ' - ' . ($reservation['reason'] ?? 'Raumreservierung');
                $location = !empty($reservation['location']) ? $reservation['location'] : null;
                $event_id = create_google_calendar_event($title, $reservation['reason'] ?? '', $reservation['start_datetime'], $reservation['end_datetime'], null, $location);
                if ($event_id) {
                    try {
                        $db->exec("CREATE TABLE IF NOT EXISTS calendar_events_room (
                            id INT AUTO_INCREMENT PRIMARY KEY,
                            room_reservation_id INT NOT NULL,
                            google_event_id VARCHAR(255) NOT NULL,
                            title VARCHAR(255) NULL,
                            start_datetime DATETIME NULL,
                            end_datetime DATETIME NULL,
                            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                            KEY idx_room_reservation (room_reservation_id),
                            KEY idx_google_event (google_event_id)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                    } catch (Exception $e) {}
                    $stmt = $db->prepare("INSERT INTO calendar_events_room (room_reservation_id, google_event_id, title, start_datetime, end_datetime) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$reservation_id, $event_id, $title, $reservation['start_datetime'], $reservation['end_datetime']]);
                }
            }
        } catch (Exception $e) {
            error_log("Raum Google Calendar: " . $e->getMessage());
        }
    }
    return $result;
}

function createRoomRejectionEmailHTML($reservation, $rejection_reason) {
    $room_name = htmlspecialchars($reservation['room_name'] ?? 'Unbekannt');
    $requester_name = htmlspecialchars($reservation['requester_name'] ?? '');
    $reason = htmlspecialchars($reservation['reason'] ?? '');
    $location = htmlspecialchars($reservation['location'] ?? 'Nicht angegeben');
    $start_date = date('d.m.Y', strtotime($reservation['start_datetime']));
    $start_time = date('H:i', strtotime($reservation['start_datetime']));
    $end_time = date('H:i', strtotime($reservation['end_datetime']));
    $rejection_reason = htmlspecialchars($rejection_reason);
    return '
    <!DOCTYPE html><html lang="de"><head><meta charset="UTF-8"><title>Raumreservierung abgelehnt</title></head><body>
    <div style="font-family: Arial, sans-serif; max-width: 600px;">
        <h2>❌ Raumreservierung abgelehnt</h2>
        <p>Hallo <strong>' . $requester_name . '</strong>,</p>
        <p>Leider mussten wir Ihre Raumreservierung ablehnen.</p>
        <div style="background: #f8f9fa; padding: 15px; border-radius: 8px;">
            <p><strong>Raum:</strong> ' . $room_name . '</p>
            <p><strong>Datum:</strong> ' . $start_date . '</p>
            <p><strong>Zeit:</strong> ' . $start_time . ' - ' . $end_time . ' Uhr</p>
        </div>
        <div style="background: #fff3cd; padding: 15px; border-radius: 8px; margin-top: 15px;">
            <h4>Ablehnungsgrund</h4>
            <p>' . nl2br($rejection_reason) . '</p>
        </div>
        <p>Mit freundlichen Grüßen,<br>Ihre Feuerwehr</p>
    </div></body></html>';
}
