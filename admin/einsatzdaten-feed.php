<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/einheiten-setup.php';
require_once __DIR__ . '/../includes/einheit-settings-helper.php';
require_once __DIR__ . '/../includes/einsatz-sync-helper.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || !hasAdminPermission()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Nicht autorisiert.']);
    exit;
}
if (!$db instanceof PDO) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Datenbankverbindung nicht verfuegbar.']);
    exit;
}

$einheitId = function_exists('get_current_einheit_id') ? (int)get_current_einheit_id() : 0;
if ($einheitId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Keine aktive Einheit.']);
    exit;
}

try {
    $sync = einsatz_sync_from_divera($db, $einheitId);
    $activeIncident = $sync['active'] ?? einsatz_get_active($db, $einheitId);
    $incident = null;
    if ($activeIncident && $activeIncident['latitude'] !== null && $activeIncident['longitude'] !== null) {
        $incident = [
            'label' => (string)($activeIncident['address'] ?? $activeIncident['title'] ?? 'Einsatzstelle'),
            'latitude' => isset($activeIncident['latitude']) ? (float)$activeIncident['latitude'] : null,
            'longitude' => isset($activeIncident['longitude']) ? (float)$activeIncident['longitude'] : null,
            'updated_at' => (string)($activeIncident['last_synced_at'] ?? ''),
            'alarm_id' => (int)($activeIncident['divera_alarm_id'] ?? 0),
            'alarm_ts' => isset($activeIncident['alarm_ts']) ? (int)$activeIncident['alarm_ts'] : null,
        ];
    }

    $vehiclesStmt = $db->prepare("
        SELECT vehicle_id, vehicle_name, latitude, longitude, accuracy_m, speed_mps, heading_deg, updated_at
        FROM mobile_vehicle_locations
        WHERE einheit_id = ?
          AND updated_at >= DATE_SUB(NOW(), INTERVAL 2 MINUTE)
        ORDER BY vehicle_name ASC, vehicle_id ASC
    ");
    $vehiclesStmt->execute([$einheitId]);
    $rows = $vehiclesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $vehicles = array_map(static function(array $row): array {
        return [
            'vehicle_id' => (int)$row['vehicle_id'],
            'vehicle_name' => (string)($row['vehicle_name'] ?? ''),
            'latitude' => (float)$row['latitude'],
            'longitude' => (float)$row['longitude'],
            'accuracy_m' => isset($row['accuracy_m']) ? (float)$row['accuracy_m'] : null,
            'speed_mps' => isset($row['speed_mps']) ? (float)$row['speed_mps'] : null,
            'heading_deg' => isset($row['heading_deg']) ? (float)$row['heading_deg'] : null,
            'updated_at' => (string)($row['updated_at'] ?? ''),
        ];
    }, $rows);

    echo json_encode([
        'success' => true,
        'message' => 'OK',
        'data' => [
            'einheit_id' => $einheitId,
            'incident' => $incident,
            'vehicles' => $vehicles,
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Einsatzdaten konnten nicht geladen werden.']);
}
