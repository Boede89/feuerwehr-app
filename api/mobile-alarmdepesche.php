<?php
/**
 * Mobile API: Alarmdepeschen (Fax-PDF) fuer Einsatzinfos.
 *
 * Query:
 * - alarm_ts (optional): Unix-Timestamp des Einsatzes (Sekunden oder Millisekunden)
 *
 * Antwort:
 * - has_pdf: bool
 * - has_multiple: bool
 * - pdf_url: string|null
 * - candidates: Liste moeglicher Treffer
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/database.php';

if (!$db instanceof PDO) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Datenbankverbindung nicht verfuegbar.']);
    exit;
}

function mad_bearer_token(): string {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['Authorization'] ?? '';
    if (stripos($header, 'Bearer ') === 0) return trim(substr($header, 7));
    return '';
}

function mad_request_token(): string {
    $token = $_SERVER['HTTP_X_MOBILE_TOKEN'] ?? '';
    if ($token !== '') return trim($token);
    return mad_bearer_token();
}

function mad_server_token(PDO $db): string {
    try {
        $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'mobile_api_token' LIMIT 1");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $value = trim((string)($row['setting_value'] ?? ''));
        if ($value !== '') return $value;
    } catch (Throwable $e) {}
    return trim((string)(getenv('MOBILE_API_TOKEN') ?: ''));
}

function mad_einsatzapp_tokens(PDO $db): array {
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
    } catch (Throwable $e) {}
    return array_values(array_unique($tokens));
}

function mad_einheit_id_for_token(PDO $db, string $requestToken): int {
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
    } catch (Throwable $e) {}
    return 0;
}

function mad_ensure_table(PDO $db): void {
    $db->exec("
        CREATE TABLE IF NOT EXISTS alarmdepesche_inbox (
            id INT AUTO_INCREMENT PRIMARY KEY,
            einheit_id INT NOT NULL DEFAULT 0,
            message_uid VARCHAR(191) NULL,
            subject VARCHAR(255) NOT NULL DEFAULT '',
            sender VARCHAR(255) NOT NULL DEFAULT '',
            received_at_utc DATETIME NOT NULL,
            filename_original VARCHAR(255) NOT NULL,
            storage_path VARCHAR(512) NOT NULL,
            sha256 VARCHAR(64) NULL,
            file_size_bytes BIGINT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_uid (message_uid),
            KEY idx_einheit_received (einheit_id, received_at_utc),
            KEY idx_received (received_at_utc)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function mad_parse_alarm_ts(?string $raw): ?int {
    if ($raw === null) return null;
    $val = trim($raw);
    if ($val === '') return null;
    if (preg_match('/^\d+$/', $val)) {
        $n = (int)$val;
        if ($n > 20000000000) $n = (int)floor($n / 1000); // ms -> s
        return $n > 0 ? $n : null;
    }
    $parsed = strtotime($val);
    return $parsed !== false ? (int)$parsed : null;
}

function mad_base_url(): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = $_SERVER['SCRIPT_NAME'] ?? '/api/mobile-alarmdepesche.php';
    $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
    return $scheme . '://' . $host . $dir;
}

$requestToken = mad_request_token();
$serverToken = mad_server_token($db);
$valid = ($serverToken !== '' && hash_equals($serverToken, $requestToken));
if (!$valid) {
    foreach (mad_einsatzapp_tokens($db) as $token) {
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
    mad_ensure_table($db);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Depeschen-Tabelle konnte nicht erstellt werden.']);
    exit;
}

$einheitId = mad_einheit_id_for_token($db, $requestToken);
$alarmTs = mad_parse_alarm_ts($_GET['alarm_ts'] ?? null);
$baseUrl = mad_base_url();

try {
    $rows = [];
    if ($alarmTs !== null) {
        $from = gmdate('Y-m-d H:i:s', $alarmTs - 120);   // -2 min
        $to = gmdate('Y-m-d H:i:s', $alarmTs + 1800);    // +30 min
        $stmt = $db->prepare("
            SELECT id, received_at_utc, filename_original
            FROM alarmdepesche_inbox
            WHERE (einheit_id = ? OR einheit_id = 0)
              AND received_at_utc BETWEEN ? AND ?
            ORDER BY received_at_utc ASC
            LIMIT 20
        ");
        $stmt->execute([$einheitId, $from, $to]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (empty($rows)) {
            // Fallback: gleiche Zeitlogik ohne Einheitenfilter (hilft bei abweichender Token->Einheit Zuordnung)
            $stmt = $db->prepare("
                SELECT id, received_at_utc, filename_original
                FROM alarmdepesche_inbox
                WHERE received_at_utc BETWEEN ? AND ?
                ORDER BY received_at_utc ASC
                LIMIT 20
            ");
            $stmt->execute([$from, $to]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    } else {
        $stmt = $db->prepare("
            SELECT id, received_at_utc, filename_original
            FROM alarmdepesche_inbox
            WHERE (einheit_id = ? OR einheit_id = 0)
              AND received_at_utc >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 12 HOUR)
            ORDER BY received_at_utc DESC
            LIMIT 20
        ");
        $stmt->execute([$einheitId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (empty($rows)) {
            // Fallback: neueste Depeschen ohne Einheitenfilter
            $stmt = $db->prepare("
                SELECT id, received_at_utc, filename_original
                FROM alarmdepesche_inbox
                WHERE received_at_utc >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)
                ORDER BY received_at_utc DESC
                LIMIT 20
            ");
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    }

    $candidates = [];
    foreach ($rows as $row) {
        $id = (int)($row['id'] ?? 0);
        if ($id <= 0) continue;
        $receivedUtc = (string)($row['received_at_utc'] ?? '');
        $receivedTs = strtotime($receivedUtc . ' UTC');
        $delta = null;
        if ($alarmTs !== null && $receivedTs !== false) {
            $delta = $receivedTs - $alarmTs;
        }
        $candidates[] = [
            'id' => $id,
            'received_at_utc' => $receivedUtc,
            'filename' => (string)($row['filename_original'] ?? ''),
            'delta_seconds' => $delta,
            'pdf_url' => $baseUrl . '/mobile-alarmdepesche-download.php?id=' . $id . '&mobile_token=' . urlencode($requestToken),
        ];
    }

    if (!empty($candidates) && $alarmTs !== null) {
        usort($candidates, static function(array $a, array $b): int {
            $ad = (int)($a['delta_seconds'] ?? 99999999);
            $bd = (int)($b['delta_seconds'] ?? 99999999);
            $aPenalty = $ad < 0 ? 1000000 + abs($ad) : $ad;
            $bPenalty = $bd < 0 ? 1000000 + abs($bd) : $bd;
            return $aPenalty <=> $bPenalty;
        });
    }

    $best = $candidates[0] ?? null;
    echo json_encode([
        'success' => true,
        'message' => 'OK',
        'data' => [
            'has_pdf' => $best !== null,
            'has_multiple' => count($candidates) > 1,
            'pdf_url' => $best['pdf_url'] ?? null,
            'candidate_count' => count($candidates),
            'candidates' => array_slice($candidates, 0, 8),
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Depeschen konnten nicht geladen werden.']);
}

