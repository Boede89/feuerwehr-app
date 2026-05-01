<?php
/**
 * Mobile API: Fahrzeugliste aus der Feuerwehr-Datenbank.
 *
 * Auth:
 * - Header "X-Mobile-Token: <token>" oder
 * - Header "Authorization: Bearer <token>"
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/database.php';

if (!$db instanceof PDO) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Datenbankverbindung nicht verfuegbar.']);
    exit;
}

function mobile_vehicles_bearer_token(): string {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['Authorization'] ?? '';
    if (stripos($header, 'Bearer ') === 0) {
        return trim(substr($header, 7));
    }
    return '';
}

function mobile_vehicles_request_token(): string {
    $token = $_SERVER['HTTP_X_MOBILE_TOKEN'] ?? '';
    if ($token !== '') {
        return trim($token);
    }
    return mobile_vehicles_bearer_token();
}

function mobile_vehicles_server_token(PDO $db): string {
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

function mobile_vehicles_einsatzapp_tokens(PDO $db): array {
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

function mobile_vehicles_einheit_id_for_token(PDO $db, string $requestToken): int {
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
                if ($token !== '' && hash_equals($token, $requestToken)) {
                    return $einheitId;
                }
            }
        }
    } catch (Throwable $e) {
    }
    return 0;
}

function mobile_vehicles_table_exists(PDO $db, string $table): bool {
    try {
        $stmt = $db->prepare('SHOW TABLES LIKE ?');
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function mobile_vehicles_pick(array $row, array $keys): string {
    foreach ($keys as $key) {
        if (!array_key_exists($key, $row)) continue;
        $value = trim((string)($row[$key] ?? ''));
        if ($value !== '') return $value;
    }
    return '';
}

$requestToken = mobile_vehicles_request_token();
$serverToken = mobile_vehicles_server_token($db);
$valid = ($serverToken !== '' && hash_equals($serverToken, $requestToken));
if (!$valid) {
    foreach (mobile_vehicles_einsatzapp_tokens($db) as $token) {
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

if (!mobile_vehicles_table_exists($db, 'vehicles')) {
    echo json_encode([
        'success' => true,
        'message' => 'OK',
        'data' => [
            'vehicles' => [],
            'count' => 0,
            'matched_einheit_id' => 0,
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$matchedEinheitId = mobile_vehicles_einheit_id_for_token($db, $requestToken);

try {
    if ($matchedEinheitId > 0) {
        $sql = "SELECT * FROM vehicles WHERE einheit_id = ? OR einheit_id IS NULL ORDER BY sort_order ASC, name ASC";
        $stmt = $db->prepare($sql);
        $stmt->execute([$matchedEinheitId]);
    } else {
        $sql = "SELECT * FROM vehicles ORDER BY sort_order ASC, name ASC";
        $stmt = $db->query($sql);
    }

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $vehicles = [];
    foreach ($rows as $row) {
        $id = (int)($row['id'] ?? 0);
        $name = mobile_vehicles_pick($row, ['name', 'title', 'bezeichnung', 'vehicle_name']);
        $callsign = mobile_vehicles_pick($row, ['callsign', 'funkrufname', 'radio', 'rufname', 'vehicle_code', 'kennzeichen', 'plate']);
        $status = mobile_vehicles_pick($row, ['status', 'state', 'fms', 'vehicle_status']);
        if ($name === '' && $callsign === '' && $id <= 0) {
            continue;
        }
        $vehicles[] = [
            'id' => $id,
            'name' => $name !== '' ? $name : ('Fahrzeug #' . $id),
            'callsign' => $callsign,
            'status' => $status !== '' ? $status : '-',
            'is_active' => isset($row['is_active']) ? (int)$row['is_active'] : 1,
            'sort_order' => isset($row['sort_order']) ? (int)$row['sort_order'] : 0,
            'einheit_id' => isset($row['einheit_id']) ? (int)$row['einheit_id'] : null,
        ];
    }

    echo json_encode([
        'success' => true,
        'message' => 'OK',
        'data' => [
            'vehicles' => $vehicles,
            'count' => count($vehicles),
            'matched_einheit_id' => $matchedEinheitId,
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Fehler beim Laden der Fahrzeuge.',
    ]);
}

