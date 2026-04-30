<?php
/**
 * Mobile API: Basisdaten aus der Feuerwehr-Datenbank.
 *
 * Auth:
 * - Header "X-Mobile-Token: <token>" oder
 * - Header "Authorization: Bearer <token>"
 *
 * Token-Quelle:
 * - settings.setting_key = "mobile_api_token"
 * - Fallback: ENV MOBILE_API_TOKEN
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/database.php';

if (!$db instanceof PDO) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Datenbankverbindung nicht verfuegbar.']);
    exit;
}

function mobile_read_bearer_token(): string {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['Authorization'] ?? '';
    if (stripos($header, 'Bearer ') === 0) {
        return trim(substr($header, 7));
    }
    return '';
}

function mobile_read_token_from_request(): string {
    $token = $_SERVER['HTTP_X_MOBILE_TOKEN'] ?? '';
    if ($token !== '') {
        return trim($token);
    }
    return mobile_read_bearer_token();
}

function mobile_load_server_token(PDO $db): string {
    try {
        $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'mobile_api_token' LIMIT 1");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $value = trim((string)($row['setting_value'] ?? ''));
        if ($value !== '') return $value;
    } catch (Throwable $e) {
        // ignore and try ENV fallback
    }
    return trim((string)(getenv('MOBILE_API_TOKEN') ?: ''));
}

function mobile_load_einsatzapp_tokens(PDO $db): array {
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

function mobile_table_exists(PDO $db, string $table): bool {
    try {
        $stmt = $db->prepare('SHOW TABLES LIKE ?');
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function mobile_count(PDO $db, string $table): int {
    try {
        $sql = sprintf('SELECT COUNT(*) FROM `%s`', str_replace('`', '', $table));
        $stmt = $db->query($sql);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

$requestToken = mobile_read_token_from_request();
$serverToken = mobile_load_server_token($db);
$valid = ($serverToken !== '' && hash_equals($serverToken, $requestToken));
if (!$valid) {
    foreach (mobile_load_einsatzapp_tokens($db) as $token) {
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

$membersCount = mobile_table_exists($db, 'members') ? mobile_count($db, 'members') : 0;
$vehiclesCount = mobile_table_exists($db, 'vehicles') ? mobile_count($db, 'vehicles') : 0;
$reservationsCount = mobile_table_exists($db, 'reservations') ? mobile_count($db, 'reservations') : 0;

$recentReservations = [];
if (mobile_table_exists($db, 'reservations')) {
    try {
        $sql = "SELECT r.id, r.reason, r.start_datetime, r.end_datetime, v.name AS vehicle_name
                FROM reservations r
                LEFT JOIN vehicles v ON v.id = r.vehicle_id
                ORDER BY r.start_datetime DESC
                LIMIT 5";
        $stmt = $db->query($sql);
        $recentReservations = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $recentReservations = [];
    }
}

echo json_encode([
    'success' => true,
    'message' => 'OK',
    'data' => [
        'server_time' => date('c'),
        'stats' => [
            'members_count' => $membersCount,
            'vehicles_count' => $vehiclesCount,
            'reservations_count' => $reservationsCount,
        ],
        'recent_reservations' => $recentReservations,
    ],
], JSON_UNESCAPED_UNICODE);
