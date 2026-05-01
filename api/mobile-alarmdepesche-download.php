<?php
/**
 * Download-Endpunkt fuer Alarmdepesche-PDF.
 * Zugriff via mobile_token Query-Parameter oder Header X-Mobile-Token.
 */
require_once __DIR__ . '/../config/database.php';

if (!$db instanceof PDO) {
    http_response_code(500);
    echo 'Datenbankverbindung nicht verfuegbar.';
    exit;
}

function madd_bearer_token(): string {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['Authorization'] ?? '';
    if (stripos($header, 'Bearer ') === 0) return trim(substr($header, 7));
    return '';
}

function madd_request_token(): string {
    $query = trim((string)($_GET['mobile_token'] ?? ''));
    if ($query !== '') return $query;
    $token = trim((string)($_SERVER['HTTP_X_MOBILE_TOKEN'] ?? ''));
    if ($token !== '') return $token;
    return madd_bearer_token();
}

function madd_server_token(PDO $db): string {
    try {
        $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'mobile_api_token' LIMIT 1");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $value = trim((string)($row['setting_value'] ?? ''));
        if ($value !== '') return $value;
    } catch (Throwable $e) {}
    return trim((string)(getenv('MOBILE_API_TOKEN') ?: ''));
}

function madd_einsatzapp_tokens(PDO $db): array {
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

$requestToken = madd_request_token();
$serverToken = madd_server_token($db);
$valid = ($serverToken !== '' && hash_equals($serverToken, $requestToken));
if (!$valid) {
    foreach (madd_einsatzapp_tokens($db) as $token) {
        if (hash_equals($token, $requestToken)) {
            $valid = true;
            break;
        }
    }
}
if (!$valid) {
    http_response_code(401);
    echo 'Nicht autorisiert.';
    exit;
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo 'Ungueltige ID.';
    exit;
}

try {
    $stmt = $db->prepare("SELECT filename_original, storage_path FROM alarmdepesche_inbox WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        http_response_code(404);
        echo 'Datei nicht gefunden.';
        exit;
    }
    $path = (string)($row['storage_path'] ?? '');
    $filename = trim((string)($row['filename_original'] ?? 'alarmdepesche.pdf'));
    if ($path === '' || !is_file($path)) {
        http_response_code(404);
        echo 'Datei nicht verfuegbar.';
        exit;
    }
    header('Content-Type: application/pdf');
    header('Content-Length: ' . (string)filesize($path));
    header('Content-Disposition: inline; filename="' . str_replace('"', '', basename($filename)) . '"');
    readfile($path);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Fehler beim Dateizugriff.';
}

