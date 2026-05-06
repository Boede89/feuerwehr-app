<?php
/**
 * Mobile API: Divera Beispieldaten speichern/laden.
 *
 * GET:
 * - Liefert Beispieldaten fuer die Einheit des Tokens (inkl. globaler Daten mit einheit_id=0).
 *
 * POST (JSON):
 * {
 *   "action": "save",
 *   "display_name": "B2 - 2026-05-06 18:30",
 *   "keyword": "B2",
 *   "incident_date": "2026-05-06 18:30",
 *   "raw_data": {
 *     "alarmsJson": "{...}",
 *     "alarmDetailJson": "{...}",
 *     "reachJson": "{...}",
 *     "eventsJson": "{...}"
 *   }
 * }
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/database.php';

if (!$db instanceof PDO) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Datenbankverbindung nicht verfuegbar.']);
    exit;
}

function mobile_ds_bearer_token(): string {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['Authorization'] ?? '';
    if (stripos($header, 'Bearer ') === 0) {
        return trim(substr($header, 7));
    }
    return '';
}

function mobile_ds_request_token(): string {
    $token = $_SERVER['HTTP_X_MOBILE_TOKEN'] ?? '';
    if ($token !== '') {
        return trim($token);
    }
    return mobile_ds_bearer_token();
}

function mobile_ds_server_token(PDO $db): string {
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

function mobile_ds_einsatzapp_tokens(PDO $db): array {
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

function mobile_ds_einheit_id_for_token(PDO $db, string $requestToken): int {
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

function mobile_ds_ensure_table(PDO $db): void {
    $db->exec("
        CREATE TABLE IF NOT EXISTS mobile_divera_samples (
            id INT AUTO_INCREMENT PRIMARY KEY,
            einheit_id INT NOT NULL DEFAULT 0,
            display_name VARCHAR(255) NOT NULL,
            keyword VARCHAR(255) NOT NULL DEFAULT '',
            incident_date VARCHAR(255) NOT NULL DEFAULT '',
            alarms_json LONGTEXT NOT NULL,
            alarm_detail_json LONGTEXT NOT NULL,
            reach_json LONGTEXT NOT NULL,
            events_json LONGTEXT NOT NULL,
            created_by_token VARCHAR(191) NOT NULL DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_einheit_created (einheit_id, created_at),
            KEY idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function mobile_ds_trim(string $value, int $maxLength): string {
    $value = trim($value);
    if ($maxLength <= 0) return $value;
    return mb_substr($value, 0, $maxLength);
}

function mobile_ds_load_samples(PDO $db, int $einheitId): array {
    if ($einheitId > 0) {
        $stmt = $db->prepare("
            SELECT id, einheit_id, display_name, keyword, incident_date, alarms_json, alarm_detail_json, reach_json, events_json, created_at
            FROM mobile_divera_samples
            WHERE einheit_id = ? OR einheit_id = 0
            ORDER BY created_at DESC, id DESC
            LIMIT 50
        ");
        $stmt->execute([$einheitId]);
    } else {
        $stmt = $db->query("
            SELECT id, einheit_id, display_name, keyword, incident_date, alarms_json, alarm_detail_json, reach_json, events_json, created_at
            FROM mobile_divera_samples
            WHERE einheit_id = 0
            ORDER BY created_at DESC, id DESC
            LIMIT 50
        ");
    }
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $samples = [];
    foreach ($rows as $row) {
        $createdAt = strtotime((string)($row['created_at'] ?? '')) ?: 0;
        $samples[] = [
            'id' => (string)($row['id'] ?? ''),
            'einheit_id' => (int)($row['einheit_id'] ?? 0),
            'display_name' => (string)($row['display_name'] ?? ''),
            'keyword' => (string)($row['keyword'] ?? ''),
            'incident_date' => (string)($row['incident_date'] ?? ''),
            'saved_at_epoch_ms' => $createdAt > 0 ? ($createdAt * 1000) : 0,
            'raw_data' => [
                'alarmsJson' => (string)($row['alarms_json'] ?? ''),
                'alarmDetailJson' => (string)($row['alarm_detail_json'] ?? ''),
                'reachJson' => (string)($row['reach_json'] ?? ''),
                'eventsJson' => (string)($row['events_json'] ?? ''),
            ],
        ];
    }
    return $samples;
}

$requestToken = mobile_ds_request_token();
$serverToken = mobile_ds_server_token($db);
$valid = ($serverToken !== '' && hash_equals($serverToken, $requestToken));
if (!$valid) {
    foreach (mobile_ds_einsatzapp_tokens($db) as $token) {
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

$einheitId = mobile_ds_einheit_id_for_token($db, $requestToken);
if ($einheitId <= 0) $einheitId = 0;

try {
    mobile_ds_ensure_table($db);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Beispieldaten-Tabelle konnte nicht vorbereitet werden.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $payload = json_decode((string)$raw, true);
    if (!is_array($payload)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Ungueltiges JSON im Request.']);
        exit;
    }

    $action = trim((string)($payload['action'] ?? ''));
    if ($action !== 'save') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Ungueltige Aktion.']);
        exit;
    }

    $displayName = mobile_ds_trim((string)($payload['display_name'] ?? ''), 255);
    $keyword = mobile_ds_trim((string)($payload['keyword'] ?? ''), 255);
    $incidentDate = mobile_ds_trim((string)($payload['incident_date'] ?? ''), 255);
    $rawData = $payload['raw_data'] ?? null;

    if (!is_array($rawData)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'raw_data ist erforderlich.']);
        exit;
    }

    $alarmsJson = (string)($rawData['alarmsJson'] ?? '');
    $alarmDetailJson = (string)($rawData['alarmDetailJson'] ?? '');
    $reachJson = (string)($rawData['reachJson'] ?? '');
    $eventsJson = (string)($rawData['eventsJson'] ?? '');

    if ($displayName === '') $displayName = 'Beispieldaten';
    if ($keyword === '') $keyword = 'Unbekanntes Stichwort';
    if ($incidentDate === '') $incidentDate = 'Unbekanntes Datum';
    if ($alarmsJson === '' || $eventsJson === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Rohdaten unvollstaendig.']);
        exit;
    }

    try {
        $stmt = $db->prepare("
            INSERT INTO mobile_divera_samples (
                einheit_id, display_name, keyword, incident_date, alarms_json, alarm_detail_json, reach_json, events_json, created_by_token
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $einheitId,
            $displayName,
            $keyword,
            $incidentDate,
            $alarmsJson,
            $alarmDetailJson,
            $reachJson,
            $eventsJson,
            mobile_ds_trim($requestToken, 191),
        ]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Beispieldaten konnten nicht gespeichert werden.']);
        exit;
    }
}

try {
    $samples = mobile_ds_load_samples($db, $einheitId);
    echo json_encode([
        'success' => true,
        'message' => 'OK',
        'data' => [
            'einheit_id' => $einheitId,
            'samples' => $samples,
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Beispieldaten konnten nicht geladen werden.']);
}

