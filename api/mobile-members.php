<?php
/**
 * Mobile API: Mitgliederliste inkl. Divera UCR-ID.
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

function mobile_members_bearer_token(): string {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['Authorization'] ?? '';
    if (stripos($header, 'Bearer ') === 0) {
        return trim(substr($header, 7));
    }
    return '';
}

function mobile_members_request_token(): string {
    $token = $_SERVER['HTTP_X_MOBILE_TOKEN'] ?? '';
    if ($token !== '') {
        return trim($token);
    }
    return mobile_members_bearer_token();
}

function mobile_members_server_token(PDO $db): string {
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

function mobile_members_einsatzapp_tokens(PDO $db): array {
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

function mobile_members_einheit_id_for_token(PDO $db, string $requestToken): int {
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

function mobile_members_status_data(PDO $db, int $einheitId): array {
    $result = ['labels' => [], 'order' => []];
    if ($einheitId <= 0) {
        try {
            $stmt = $db->prepare("SELECT setting_value FROM einheit_settings WHERE setting_key = 'anwesenheitsliste_divera_rueckmeldung_status_presets'");
            $stmt->execute();
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $raw = trim((string)($row['setting_value'] ?? ''));
                if ($raw === '') continue;
                $decoded = json_decode($raw, true);
                if (!is_array($decoded)) continue;
                foreach ($decoded as $entry) {
                    if (!is_array($entry)) continue;
                    $id = (int)($entry['id'] ?? 0);
                    $label = trim((string)($entry['label'] ?? ''));
                    if ($id > 0 && $label !== '' && !isset($result['labels'][(string)$id])) {
                        $result['labels'][(string)$id] = $label;
                        $result['order'][] = $id;
                    }
                }
            }
            return $result;
        } catch (Throwable $e) {
            return $result;
        }
    }
    try {
        $stmt = $db->prepare("SELECT setting_value FROM einheit_settings WHERE einheit_id = ? AND setting_key = 'anwesenheitsliste_divera_rueckmeldung_status_presets' LIMIT 1");
        $stmt->execute([$einheitId]);
        $raw = trim((string)($stmt->fetchColumn() ?: ''));
        if ($raw === '') return $result;
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) return $result;
        foreach ($decoded as $row) {
            if (!is_array($row)) continue;
            $id = (int)($row['id'] ?? 0);
            $label = trim((string)($row['label'] ?? ''));
            if ($id > 0 && $label !== '') {
                $result['labels'][(string)$id] = $label;
                $result['order'][] = $id;
            }
        }
        return $result;
    } catch (Throwable $e) {
        return $result;
    }
}

$requestToken = mobile_members_request_token();
$serverToken = mobile_members_server_token($db);
$valid = ($serverToken !== '' && hash_equals($serverToken, $requestToken));
if (!$valid) {
    foreach (mobile_members_einsatzapp_tokens($db) as $token) {
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

$matchedEinheitId = mobile_members_einheit_id_for_token($db, $requestToken);
$statusData = mobile_members_status_data($db, $matchedEinheitId);

try {
    $stmt = $db->query("
        SELECT id, first_name, last_name, divera_ucr_id
        FROM members
        ORDER BY last_name, first_name
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $members = [];
    foreach ($rows as $row) {
        $members[] = [
            'id' => (int)($row['id'] ?? 0),
            'first_name' => (string)($row['first_name'] ?? ''),
            'last_name' => (string)($row['last_name'] ?? ''),
            'divera_ucr_id' => (int)($row['divera_ucr_id'] ?? 0),
        ];
    }
    echo json_encode([
        'success' => true,
        'message' => 'OK',
        'data' => [
            'members' => $members,
            'status_labels' => $statusData['labels'],
            'status_order' => array_values(array_map('intval', $statusData['order'] ?? [])),
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Fehler beim Laden der Mitglieder.',
    ]);
}
