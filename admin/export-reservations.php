<?php
/**
 * Export genehmigter Fahrzeug- und Raumreservierungen als JSON für Feuerwehr-Manager.
 * Kein Divera-/Google-Kalender-Export — nur Stammdaten der Reservierung.
 */

session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once __DIR__ . '/../includes/einheiten-setup.php';
require_once __DIR__ . '/../includes/rooms-setup.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('Location: ../login.php');
    exit;
}

if (!has_permission('reservations') && !can_approve_reservations()) {
    header('Location: ../login.php?error=access_denied');
    exit;
}

if (!has_permission_write('reservations')) {
    header('Location: reservations.php?err=' . urlencode('Keine Berechtigung für den Export.'));
    exit;
}

function export_to_iso8601(?string $datetime): ?string
{
    if ($datetime === null || trim($datetime) === '') {
        return null;
    }
    try {
        $dt = new DateTime($datetime, new DateTimeZone('Europe/Berlin'));
        return $dt->format('c');
    } catch (Exception $e) {
        return null;
    }
}

function export_nullable_string($value): ?string
{
    if ($value === null) {
        return null;
    }
    $trimmed = trim((string) $value);
    return $trimmed === '' ? null : $trimmed;
}

$einheit_filter = get_admin_einheit_filter();
$items = [];

try {
    $sql = "
        SELECT r.id, r.requester_name, r.requester_email, r.reason, r.location,
               r.start_datetime, r.end_datetime, r.status, r.approved_at,
               v.name AS resource_name
        FROM reservations r
        JOIN vehicles v ON r.vehicle_id = v.id
        WHERE r.status = 'approved'
          AND r.end_datetime >= NOW()
    ";
    $params = [];
    if ($einheit_filter) {
        $sql .= " AND (r.einheit_id = ? OR v.einheit_id = ? OR (r.einheit_id IS NULL AND v.einheit_id IS NULL))";
        $params[] = $einheit_filter;
        $params[] = $einheit_filter;
    }
    $sql .= " ORDER BY r.start_datetime ASC, r.id ASC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $items[] = [
            'legacyId' => (int) $row['id'],
            'kind' => 'vehicle',
            'resourceName' => export_nullable_string($row['resource_name']),
            'requesterName' => export_nullable_string($row['requester_name']),
            'requesterEmail' => export_nullable_string($row['requester_email']),
            'reason' => export_nullable_string($row['reason']),
            'location' => export_nullable_string($row['location']),
            'startAt' => export_to_iso8601($row['start_datetime']),
            'endAt' => export_to_iso8601($row['end_datetime']),
            'status' => 'approved',
            'approvedAt' => export_to_iso8601($row['approved_at']),
        ];
    }

    $roomSql = "
        SELECT rr.id, rr.requester_name, rr.requester_email, rr.reason, rr.location,
               rr.start_datetime, rr.end_datetime, rr.status, rr.approved_at,
               ro.name AS resource_name
        FROM room_reservations rr
        JOIN rooms ro ON rr.room_id = ro.id
        WHERE rr.status = 'approved'
          AND rr.end_datetime >= NOW()
    ";
    $roomParams = [];
    if ($einheit_filter) {
        $roomSql .= " AND (rr.einheit_id = ? OR ro.einheit_id = ? OR (rr.einheit_id IS NULL AND ro.einheit_id IS NULL))";
        $roomParams[] = $einheit_filter;
        $roomParams[] = $einheit_filter;
    }
    $roomSql .= " ORDER BY rr.start_datetime ASC, rr.id ASC";
    $roomStmt = $db->prepare($roomSql);
    $roomStmt->execute($roomParams);
    while ($row = $roomStmt->fetch(PDO::FETCH_ASSOC)) {
        $items[] = [
            'legacyId' => (int) $row['id'],
            'kind' => 'room',
            'resourceName' => export_nullable_string($row['resource_name']),
            'requesterName' => export_nullable_string($row['requester_name']),
            'requesterEmail' => export_nullable_string($row['requester_email']),
            'reason' => export_nullable_string($row['reason']),
            'location' => export_nullable_string($row['location']),
            'startAt' => export_to_iso8601($row['start_datetime']),
            'endAt' => export_to_iso8601($row['end_datetime']),
            'status' => 'approved',
            'approvedAt' => export_to_iso8601($row['approved_at']),
        ];
    }
} catch (Exception $e) {
    header('Location: reservations.php?err=' . urlencode('Export fehlgeschlagen: ' . $e->getMessage()));
    exit;
}

$export = [
    'formatVersion' => 1,
    'source' => 'feuerwehr-app',
    'exportedAt' => (new DateTime('now', new DateTimeZone('Europe/Berlin')))->format('c'),
    'reservationCount' => count($items),
    'reservations' => $items,
];

$filename = 'reservierungen-export-' . date('Y-m-d-His') . '.json';
header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store, no-cache, must-revalidate');
echo json_encode($export, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
exit;
