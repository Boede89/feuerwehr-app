<?php
/**
 * API: Aktiven Divera-Einsatz abrufen (für Anwesenheitsliste-Vorschlag).
 * Einheit VOR Divera setzen, damit einheitsspezifischer Key geladen wird.
 */
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/einheiten-setup.php';
$einheit_id = isset($_GET['einheit_id']) ? (int)$_GET['einheit_id'] : (isset($_SESSION['current_einheit_id']) ? (int)$_SESSION['current_einheit_id'] : 0);
if ($einheit_id <= 0 && isset($_SESSION['user_id'])) {
    try {
        $stmt = $db->prepare("SELECT einheit_id FROM users WHERE id = ?");
        $stmt->execute([(int)$_SESSION['user_id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $einheit_id = $row ? (int)($row['einheit_id'] ?? 0) : 0;
    } catch (Exception $e) {}
}
if ($einheit_id > 0) $_SESSION['current_einheit_id'] = $einheit_id;
require_once __DIR__ . '/../config/divera.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Nicht angemeldet', 'alarm' => null]);
    exit;
}

$divera_key = trim((string) ($divera_config['access_key'] ?? ''));
// Bei Einheitsauswahl (einheit_id > 0) KEIN Fallback auf Benutzer-Key – nur Einheits-Key nutzen
if ($divera_key === '' && $einheit_id <= 0) {
    try {
        $stmt = $db->prepare("SELECT divera_access_key FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $divera_key = trim((string) ($row['divera_access_key'] ?? ''));
    } catch (Exception $e) { /* ignore */ }
}
$api_base = rtrim(trim((string) ($divera_config['api_base_url'] ?? '')), '/') ?: 'https://app.divera247.com';

$error = '';
$alarms = fetch_divera_alarms($divera_key, $api_base, $error);
$alarm = null;
if (!empty($alarms)) {
    $alarm = $alarms[0];
    $alarm['datum'] = $alarm['date'] > 0 ? date('Y-m-d', $alarm['date']) : date('Y-m-d');
    $alarm['uhrzeit'] = $alarm['date'] > 0 ? date('H:i', $alarm['date']) : date('H:i');
}

echo json_encode([
    'success' => $error === '',
    'message' => $error ?: 'OK',
    'alarm' => $alarm,
]);
