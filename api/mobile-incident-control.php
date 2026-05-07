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

function mic_request_token(): string {
    $token = $_SERVER['HTTP_X_MOBILE_TOKEN'] ?? '';
    if ($token !== '') return trim($token);
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['Authorization'] ?? '';
    if (stripos($auth, 'Bearer ') === 0) return trim(substr($auth, 7));
    return '';
}

function mic_server_token(PDO $db): string {
    try {
        $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'mobile_api_token' LIMIT 1");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $v = trim((string)($row['setting_value'] ?? ''));
        if ($v !== '') return $v;
    } catch (Throwable $e) {}
    return trim((string)(getenv('MOBILE_API_TOKEN') ?: ''));
}

function mic_einsatzapp_tokens(PDO $db): array {
    $tokens = [];
    try {
        $stmt = $db->prepare("SELECT setting_value FROM einheit_settings WHERE setting_key = 'einsatzapp_api_tokens'");
        $stmt->execute();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $decoded = json_decode(trim((string)($row['setting_value'] ?? '')), true);
            if (!is_array($decoded)) continue;
            foreach ($decoded as $entry) {
                if (!is_array($entry)) continue;
                $token = trim((string)($entry['token'] ?? ''));
                if ($token !== '') $tokens[] = $token;
            }
        }
    } catch (Throwable $e) {}
    return array_values(array_unique($tokens));
}

function mic_einheit_id_for_token(PDO $db, string $requestToken): int {
    if ($requestToken === '') return 0;
    try {
        $stmt = $db->prepare("SELECT einheit_id, setting_value FROM einheit_settings WHERE setting_key = 'einsatzapp_api_tokens'");
        $stmt->execute();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $eid = (int)($row['einheit_id'] ?? 0);
            if ($eid <= 0) continue;
            $decoded = json_decode(trim((string)($row['setting_value'] ?? '')), true);
            if (!is_array($decoded)) continue;
            foreach ($decoded as $entry) {
                $token = trim((string)($entry['token'] ?? ''));
                if ($token !== '' && hash_equals($token, $requestToken)) return $eid;
            }
        }
    } catch (Throwable $e) {}
    return 0;
}

function mic_extract_sample_incident(array $raw): array {
    $alarmsJson = (string)($raw['alarmsJson'] ?? '');
    $detailJson = (string)($raw['alarmDetailJson'] ?? '');
    $alarmsRoot = json_decode($alarmsJson, true);
    $detailRoot = json_decode($detailJson, true);
    $data = is_array($alarmsRoot['data'] ?? null) ? $alarmsRoot['data'] : [];
    $items = $data['items'] ?? [];
    $first = null;
    if (is_array($items)) {
        if (array_keys($items) === range(0, count($items) - 1)) {
            $first = is_array($items[0] ?? null) ? $items[0] : null;
        } else {
            foreach ($items as $row) {
                if (is_array($row)) { $first = $row; break; }
            }
        }
    }
    $detailData = is_array($detailRoot['data'] ?? null) ? $detailRoot['data'] : $detailRoot;
    if (!is_array($detailData)) $detailData = [];
    $pick = static function(array $a, array $b, array $keys, string $fallback = ''): string {
        foreach ($keys as $k) {
            $v = trim((string)($a[$k] ?? ''));
            if ($v !== '') return $v;
        }
        foreach ($keys as $k) {
            $v = trim((string)($b[$k] ?? ''));
            if ($v !== '') return $v;
        }
        return $fallback;
    };
    $coord = static function(array $a, array $b, array $keys): ?float {
        foreach ([$a, $b] as $src) {
            foreach ($keys as $k) {
                if (!array_key_exists($k, $src)) continue;
                $raw = $src[$k];
                if (is_numeric($raw)) return (float)$raw;
                $txt = str_replace(',', '.', trim((string)$raw));
                if ($txt !== '' && is_numeric($txt)) return (float)$txt;
            }
        }
        return null;
    };
    $alarmTs = (int)($first['date'] ?? $first['ts_create'] ?? 0);
    if ($alarmTs > 20_000_000_000) $alarmTs = (int)floor($alarmTs / 1000);
    if ($alarmTs <= 0) $alarmTs = time();
    return [
        'title' => $pick($first ?: [], $detailData, ['title', 'keyword', 'stichwort'], 'Beispieleinsatz'),
        'keyword' => $pick($first ?: [], $detailData, ['keyword', 'stichwort', 'title'], 'Beispiel'),
        'address' => $pick($first ?: [], $detailData, ['address', 'adresse', 'location'], ''),
        'text' => $pick($first ?: [], $detailData, ['text', 'note', 'description', 'message'], ''),
        'alarm_ts' => $alarmTs,
        'latitude' => $coord($first ?: [], $detailData, ['lat', 'latitude']),
        'longitude' => $coord($first ?: [], $detailData, ['lng', 'lon', 'longitude']),
        'answered_json' => json_encode(is_array($detailData['ucr_answered'] ?? null) ? $detailData['ucr_answered'] : [], JSON_UNESCAPED_UNICODE),
        'alarm_json' => json_encode($first ?: new stdClass(), JSON_UNESCAPED_UNICODE),
        'detail_json' => json_encode($detailData, JSON_UNESCAPED_UNICODE),
        'reach_json' => json_encode(json_decode((string)($raw['reachJson'] ?? ''), true) ?: new stdClass(), JSON_UNESCAPED_UNICODE),
    ];
}

$token = mic_request_token();
$serverToken = mic_server_token($db);
$valid = ($serverToken !== '' && hash_equals($serverToken, $token));
if (!$valid) {
    foreach (mic_einsatzapp_tokens($db) as $t) {
        if (hash_equals($t, $token)) { $valid = true; break; }
    }
}
if (!$valid) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Nicht autorisiert.']);
    exit;
}

$einheitId = mic_einheit_id_for_token($db, $token);
if ($einheitId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Keine Einheit fuer Token gefunden.']);
    exit;
}

einsatz_ensure_table($db);
$raw = file_get_contents('php://input');
$payload = json_decode((string)$raw, true);
if (!is_array($payload)) $payload = $_POST ?: [];
$action = trim((string)($payload['action'] ?? ''));

try {
    if ($action === 'close_active') {
        $db->beginTransaction();
        $stmt = $db->prepare("SELECT id, is_sample FROM einsatz_data WHERE einheit_id = ? AND is_active = 1 ORDER BY last_synced_at DESC LIMIT 1");
        $stmt->execute([$einheitId]);
        $active = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($active) {
            $id = (int)$active['id'];
            $isSample = (int)($active['is_sample'] ?? 0) === 1;
            if ($isSample) {
                $del = $db->prepare("DELETE FROM einsatz_data WHERE id = ?");
                $del->execute([$id]);
            } else {
                $upd = $db->prepare("UPDATE einsatz_data SET is_active = 0, last_synced_at = CURRENT_TIMESTAMP WHERE id = ?");
                $upd->execute([$id]);
            }
        }
        // Beispieleinsaetze immer bereinigen, sobald der Einsatzabschluss aus der App ausgeloest wird.
        $db->prepare("DELETE FROM einsatz_data WHERE einheit_id = ? AND is_sample = 1")->execute([$einheitId]);
        $db->commit();
        $db->prepare("DELETE FROM mobile_vehicle_locations WHERE einheit_id = ?")->execute([$einheitId]);
        $db->prepare("DELETE FROM mobile_incident_locations WHERE einheit_id = ?")->execute([$einheitId]);
        echo json_encode(['success' => true, 'message' => 'Einsatz abgeschlossen.']);
        exit;
    }

    if ($action === 'activate_sample') {
        $sampleId = trim((string)($payload['sample_id'] ?? ''));
        $sampleName = trim((string)($payload['sample_name'] ?? 'Beispieleinsatz'));
        $rawData = $payload['raw_data'] ?? null;
        if ($sampleId === '' || !is_array($rawData)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'sample_id/raw_data fehlen.']);
            exit;
        }
        $incident = mic_extract_sample_incident($rawData);
        $sampleAlarmId = -abs((int)sprintf('%u', crc32($sampleId)));
        $db->prepare("UPDATE einsatz_data SET is_active = 0 WHERE einheit_id = ?")->execute([$einheitId]);
        $upsert = $db->prepare("
            INSERT INTO einsatz_data (
                einheit_id, divera_alarm_id, keyword, title, address, text, alarm_ts, latitude, longitude,
                answered_by_status_json, alarm_json, alarm_detail_json, reach_json, is_active, is_sample
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1)
            ON DUPLICATE KEY UPDATE
                keyword = VALUES(keyword),
                title = VALUES(title),
                address = VALUES(address),
                text = VALUES(text),
                alarm_ts = VALUES(alarm_ts),
                latitude = VALUES(latitude),
                longitude = VALUES(longitude),
                answered_by_status_json = VALUES(answered_by_status_json),
                alarm_json = VALUES(alarm_json),
                alarm_detail_json = VALUES(alarm_detail_json),
                reach_json = VALUES(reach_json),
                is_active = 1,
                is_sample = 1,
                last_synced_at = CURRENT_TIMESTAMP
        ");
        $upsert->execute([
            $einheitId, $sampleAlarmId, $incident['keyword'], $sampleName, $incident['address'], $incident['text'],
            $incident['alarm_ts'], $incident['latitude'], $incident['longitude'],
            $incident['answered_json'], $incident['alarm_json'], $incident['detail_json'], $incident['reach_json']
        ]);
        echo json_encode(['success' => true, 'message' => 'Beispieleinsatz aktiviert.']);
        exit;
    }

    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Unbekannte action.']);
} catch (Throwable $e) {
    if ($db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Einsatzsteuerung fehlgeschlagen.']);
}
