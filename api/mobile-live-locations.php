<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/einheit-settings-helper.php';
require_once __DIR__ . '/../includes/einsatz-sync-helper.php';

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
            source_token_hash VARCHAR(64) NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_unit_vehicle (einheit_id, vehicle_id),
            KEY idx_unit_updated (einheit_id, updated_at),
            KEY idx_token_hash (source_token_hash)
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
    try {
        $db->exec("ALTER TABLE mobile_vehicle_locations ADD COLUMN source_token_hash VARCHAR(64) NULL AFTER heading_deg");
    } catch (Throwable $e) {
    }
    try {
        $db->exec("ALTER TABLE mobile_vehicle_locations ADD INDEX idx_token_hash (source_token_hash)");
    } catch (Throwable $e) {
    }
}

function mll_reverse_geocode_label(float $lat, float $lon): string {
    $url = 'https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=' . rawurlencode((string)$lat) . '&lon=' . rawurlencode((string)$lon);
    $opts = [
        'http' => [
            'method' => 'GET',
            'timeout' => 3,
            'header' => "Accept: application/json\r\nUser-Agent: FeuerwehrApp-LiveLocation/1.0\r\n",
        ],
    ];
    $ctx = stream_context_create($opts);
    $raw = @file_get_contents($url, false, $ctx);
    if (!is_string($raw) || $raw === '') return '';
    $json = json_decode($raw, true);
    if (!is_array($json)) return '';
    $addr = $json['address'] ?? null;
    if (is_array($addr)) {
        $road = trim((string)($addr['road'] ?? $addr['pedestrian'] ?? $addr['footway'] ?? $addr['path'] ?? ''));
        $num = trim((string)($addr['house_number'] ?? ''));
        $city = trim((string)($addr['city'] ?? $addr['town'] ?? $addr['village'] ?? $addr['municipality'] ?? ''));
        $line1 = trim($road . ($num !== '' ? ' ' . $num : ''));
        $parts = [];
        if ($line1 !== '') $parts[] = $line1;
        if ($city !== '') $parts[] = $city;
        $label = trim(implode(', ', $parts));
        if ($label !== '') return $label;
    }
    $display = trim((string)($json['display_name'] ?? ''));
    if ($display !== '') {
        $pieces = array_slice(array_map('trim', explode(',', $display)), 0, 2);
        return trim(implode(', ', array_filter($pieces, static fn($v) => $v !== '')));
    }
    return '';
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
$latitude = isset($payload['latitude']) ? (float)$payload['latitude'] : null;
$longitude = isset($payload['longitude']) ? (float)$payload['longitude'] : null;
$accuracy = isset($payload['accuracy_m']) ? (float)$payload['accuracy_m'] : null;
$speed = isset($payload['speed_mps']) ? (float)$payload['speed_mps'] : null;
$heading = isset($payload['heading_deg']) ? (float)$payload['heading_deg'] : null;

$incidentLat = isset($payload['incident_latitude']) ? (float)$payload['incident_latitude'] : null;
$incidentLon = isset($payload['incident_longitude']) ? (float)$payload['incident_longitude'] : null;
$incidentLabel = trim((string)($payload['incident_label'] ?? 'Einsatzstelle'));
$updateIncidentOnly = !empty($payload['update_incident_only']);
$replaceVehicleForToken = !empty($payload['replace_vehicle_for_token']);
$clearIncident = !empty($payload['clear_incident']);
$tokenHash = hash('sha256', $requestToken);

$hasVehiclePayload = $vehicleId > 0 && $vehicleName !== '' && $latitude !== null && $longitude !== null;
$hasIncidentPayload = $incidentLat !== null && $incidentLon !== null &&
    $incidentLat >= -90 && $incidentLat <= 90 && $incidentLon >= -180 && $incidentLon <= 180;

if (!$hasVehiclePayload && !$hasIncidentPayload) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Weder gueltige Fahrzeug- noch Einsatzkoordinaten uebergeben.']);
    exit;
}
if ($hasVehiclePayload && ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Ungueltige Fahrzeugkoordinaten.']);
    exit;
}

try {
    if ($hasVehiclePayload && !$updateIncidentOnly) {
        if ($replaceVehicleForToken && $tokenHash !== '') {
            $cleanup = $db->prepare("
                DELETE FROM mobile_vehicle_locations
                WHERE einheit_id = ?
                  AND source_token_hash = ?
                  AND vehicle_id <> ?
            ");
            $cleanup->execute([$einheitId, $tokenHash, $vehicleId]);
        }
        $stmt = $db->prepare("
            INSERT INTO mobile_vehicle_locations (einheit_id, vehicle_id, vehicle_name, latitude, longitude, accuracy_m, speed_mps, heading_deg, source_token_hash)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                vehicle_name = VALUES(vehicle_name),
                latitude = VALUES(latitude),
                longitude = VALUES(longitude),
                accuracy_m = VALUES(accuracy_m),
                speed_mps = VALUES(speed_mps),
                heading_deg = VALUES(heading_deg),
                source_token_hash = VALUES(source_token_hash),
                updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([$einheitId, $vehicleId, $vehicleName, $latitude, $longitude, $accuracy, $speed, $heading, $tokenHash]);
    }

    if ($clearIncident) {
        $clearStmt = $db->prepare("UPDATE einsatz_data SET is_active = 0 WHERE einheit_id = ? AND is_active = 1");
        $clearStmt->execute([$einheitId]);
    } else if ($hasIncidentPayload) {
        if ($incidentLabel === '' || strcasecmp($incidentLabel, 'Einsatzstelle') === 0) {
            $geoLabel = mll_reverse_geocode_label($incidentLat, $incidentLon);
            if ($geoLabel !== '') $incidentLabel = $geoLabel;
        }
        $incidentStmt = $db->prepare("
            UPDATE einsatz_data
            SET address = ?, latitude = ?, longitude = ?, last_synced_at = CURRENT_TIMESTAMP
            WHERE einheit_id = ? AND is_active = 1
        ");
        $incidentStmt->execute([$incidentLabel, $incidentLat, $incidentLon, $einheitId]);
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
