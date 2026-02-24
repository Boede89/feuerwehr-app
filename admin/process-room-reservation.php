<?php
ob_start();
session_start();
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
require_once '../config/database.php';
require_once '../includes/functions.php';

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
        SELECT rr.*, ro.name as room_name
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
        if (check_room_conflict($reservation['room_id'], $reservation['start_datetime'], $reservation['end_datetime'], $reservation_id)) {
            output_json([
                'success' => false,
                'has_conflicts' => true,
                'message' => 'Der Raum ist für diesen Zeitraum bereits genehmigt reserviert.'
            ]);
            exit;
        }
        $stmt = $db->prepare("UPDATE room_reservations SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?");
        $stmt->execute([$_SESSION['user_id'], $reservation_id]);

        $subject = "✅ Raumreservierung genehmigt - " . $reservation['requester_name'];
        $message = createRoomApprovalEmailHTML($reservation);
        send_email($reservation['requester_email'], $subject, $message, '', true);
        log_activity($_SESSION['user_id'], 'room_reservation_approved', "Raumreservierung #$reservation_id genehmigt");

        output_json(['success' => true, 'message' => 'Raumreservierung wurde genehmigt']);

    } elseif ($action === 'reject') {
        if (empty($reason)) {
            output_json(['success' => false, 'message' => 'Ablehnungsgrund ist erforderlich']);
            exit;
        }
        $stmt = $db->prepare("UPDATE room_reservations SET status = 'rejected', rejection_reason = ?, approved_by = ?, approved_at = NOW() WHERE id = ?");
        $stmt->execute([$reason, $_SESSION['user_id'], $reservation_id]);

        $subject = "❌ Raumreservierung abgelehnt - " . $reservation['requester_name'];
        $message = createRoomRejectionEmailHTML($reservation, $reason);
        send_email($reservation['requester_email'], $subject, $message, '', true);
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
