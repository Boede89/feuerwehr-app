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

$requestToken = mobile_members_request_token();
$serverToken = mobile_members_server_token($db);
if ($serverToken === '' || !hash_equals($serverToken, $requestToken)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Nicht autorisiert (ungueltiger Mobile-Token).']);
    exit;
}

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
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Fehler beim Laden der Mitglieder.',
    ]);
}
