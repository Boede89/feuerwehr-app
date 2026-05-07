<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/database.php';

if (!$db instanceof PDO) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Datenbankverbindung nicht verfuegbar.']);
    exit;
}

function mll_bearer_token(): string {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['Authorization'] ?? '';
    if (stripos($header, 'Bearer ') === 0) return trim(substr($header, 7));
    return '';
}

function mll_request_token(): string {
    $token = $_SERVER['HTTP_X_MOBILE_TOKEN'] ?? '';
    if ($token !== '') return trim($token);
    return mll_bearer_token();
}

function mll_server_token(PDO $db): string {
    try {
        $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'mobile_api_token' LIMIT 1");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $value = trim((string)($row['setting_value'] ?? ''));
        if ($value !== '') return $value;
    } catch (Throwable $e) {
    }
    return trim((string)(getenv('MOBILE_API_TOKEN') ?: ''));
}

function mll_einsatzapp_tokens(PDO $db): array {
    $tokens = [];
    try {
        $stmt = $db->prepare("SELECT setting_value FROM einheit_settings WHERE setting_key = 'einsatzapp_api_tokens'");
        $stmt->execute();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $raw = trim((string)($row['setting_value'] ?? ''));
            if ($raw === '') continue;
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) continue;
            foreach ($decoded as $entry) {
                if (!is_array($entry)) continue;
                $token = trim((string)($entry['token'] ?? ''));
                if ($token !== '') $tokens[] = $token;
            }
        }
    } catch (Throwable $e) {
    }
    return array_values(array_unique($tokens));
}

function mll_einheit_id_for_token(PDO $db, string $requestToken): int {
    if ($requestToken === '') return 0;
    try {
        $stmt = $db->prepare("SELECT einheit_id, setting_value FROM einheit_settings WHERE setting_key = 'einsatzapp_api_tokens'");
        $stmt->execute();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $einheitId = (int)($row['einheit_id'] ?? 0);
            if ($einheitId <= 0) continue;
            $raw = trim((string)($row['setting_value'] ?? ''));
            if ($raw === '') continue;
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) continue;
            foreach ($decoded as $entry) {
                if (!is_array($entry)) continue;
                $token = trim((string)($entry['token'] ?? ''));
                if ($token !== '' && hash_equals($token, $requestToken)) return $einheitId;
            }
        }
    } catch (Throwable $e) {
    }
    return 0;
}

function mll_ensure_tables(PDO $db): void {
    $db->exec("
        CREATE TABLE IF NOT EXISTS mobile_vehicle_locations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            einheit_id INT NOT NULL,
            vehicle_id INT NOT NULL,
            vehicle_name VARCHAR(255) NOT NULL DEFAULT '',
            latitude DOUBLE NOT NULL,
            longitude DOUBLE NOT NULL,
            accuracy_m DOUBLE NULL,
            speed_mps DOUBLE NULL,
            heading_deg DOUBLE NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_unit_vehicle (einheit_id, vehicle_id),
            KEY idx_unit_updated (einheit_id, updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $db->exec("
        CREATE TABLE IF NOT EXISTS mobile_incident_locations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            einheit_id INT NOT NULL,
            label VARCHAR(255) NOT NULL DEFAULT '',
            latitude DOUBLE NOT NULL,
            longitude DOUBLE NOT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_unit_incident (einheit_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

$requestToken = mll_request_token();
$serverToken = mll_server_token($db);
$valid = ($serverToken !== '' && hash_equals($serverToken, $requestToken));
if (!$valid) {
    foreach (mll_einsatzapp_tokens($db) as $token) {
        if (hash_equals($token, $requestToken)) {
            $valid = true;
            break;
        }
    }
}
if (!$valid) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Nicht autorisiert (ungueltiger Mobile-Token).']);
    exit;
}

try {
    mll_ensure_tables($db);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Tabellen fuer Live-Standorte konnten nicht erstellt werden.']);
    exit;
}

$einheitId = mll_einheit_id_for_token($db, $requestToken);
if ($einheitId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Keine Einheit fuer diesen Token gefunden.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $incident = null;
        $incidentStmt = $db->prepare("
            SELECT label, latitude, longitude, updated_at
            FROM mobile_incident_locations
            WHERE einheit_id = ?
            LIMIT 1
        ");
        $incidentStmt->execute([$einheitId]);
        $incidentRow = $incidentStmt->fetch(PDO::FETCH_ASSOC);
        if ($incidentRow) {
            $incident = [
                'label' => (string)($incidentRow['label'] ?? ''),
                'latitude' => (float)$incidentRow['latitude'],
                'longitude' => (float)$incidentRow['longitude'],
                'updated_at' => (string)($incidentRow['updated_at'] ?? ''),
            ];
        }

        $vehiclesStmt = $db->prepare("
            SELECT vehicle_id, vehicle_name, latitude, longitude, accuracy_m, speed_mps, heading_deg, updated_at
            FROM mobile_vehicle_locations
            WHERE einheit_id = ?
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
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Live-Standorte konnten nicht geladen werden.']);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Methode nicht erlaubt.']);
    exit;
}

$raw = file_get_contents('php://input');
$payload = json_decode((string)$raw, true);
if (!is_array($payload)) {
    $payload = $_POST ?: [];
}

$vehicleId = (int)($payload['vehicle_id'] ?? 0);
$vehicleName = trim((string)($payload['vehicle_name'] ?? ''));
$latitude = isset($payload['latitude']) ? (float)$payload['latitude'] : 0.0;
$longitude = isset($payload['longitude']) ? (float)$payload['longitude'] : 0.0;
$accuracy = isset($payload['accuracy_m']) ? (float)$payload['accuracy_m'] : null;
$speed = isset($payload['speed_mps']) ? (float)$payload['speed_mps'] : null;
$heading = isset($payload['heading_deg']) ? (float)$payload['heading_deg'] : null;

if ($vehicleId <= 0 || $vehicleName === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'vehicle_id und vehicle_name sind erforderlich.']);
    exit;
}
if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Ungueltige Koordinaten.']);
    exit;
}

$incidentLat = isset($payload['incident_latitude']) ? (float)$payload['incident_latitude'] : null;
$incidentLon = isset($payload['incident_longitude']) ? (float)$payload['incident_longitude'] : null;
$incidentLabel = trim((string)($payload['incident_label'] ?? 'Einsatzstelle'));

try {
    $stmt = $db->prepare("
        INSERT INTO mobile_vehicle_locations (einheit_id, vehicle_id, vehicle_name, latitude, longitude, accuracy_m, speed_mps, heading_deg)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            vehicle_name = VALUES(vehicle_name),
            latitude = VALUES(latitude),
            longitude = VALUES(longitude),
            accuracy_m = VALUES(accuracy_m),
            speed_mps = VALUES(speed_mps),
            heading_deg = VALUES(heading_deg),
            updated_at = CURRENT_TIMESTAMP
    ");
    $stmt->execute([$einheitId, $vehicleId, $vehicleName, $latitude, $longitude, $accuracy, $speed, $heading]);

    if ($incidentLat !== null && $incidentLon !== null &&
        $incidentLat >= -90 && $incidentLat <= 90 && $incidentLon >= -180 && $incidentLon <= 180) {
        $incidentStmt = $db->prepare("
            INSERT INTO mobile_incident_locations (einheit_id, label, latitude, longitude)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                label = VALUES(label),
                latitude = VALUES(latitude),
                longitude = VALUES(longitude),
                updated_at = CURRENT_TIMESTAMP
        ");
        $incidentStmt->execute([$einheitId, $incidentLabel, $incidentLat, $incidentLon]);
    }

    echo json_encode([
        'success' => true,
        'message' => 'Live-Standort gespeichert.',
        'data' => ['einheit_id' => $einheitId],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Live-Standort konnte nicht gespeichert werden.']);
}
