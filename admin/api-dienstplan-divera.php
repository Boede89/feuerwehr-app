<?php
/**
 * API für Dienstplan <-> Divera: Events abrufen, Import, Export
 */
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/divera.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/dienstplan-typen.php';

if (!isset($_SESSION['user_id']) || !has_permission('forms')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Keine Berechtigung']);
    exit;
}

$divera_key = trim((string) ($divera_config['access_key'] ?? ''));
if ($divera_key === '') {
    $stmt = $db->prepare("SELECT divera_access_key FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $divera_key = trim((string) ($row['divera_access_key'] ?? ''));
}
$api_base = rtrim(trim((string) ($divera_config['api_base_url'] ?? '')), '/') ?: 'https://app.divera247.com';

// GET: Divera-Events abrufen (für Import-Auswahl)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($divera_key === '') {
        echo json_encode(['success' => false, 'message' => 'Kein Divera Access Key konfiguriert', 'events' => []]);
        exit;
    }
    $debug = isset($_GET['debug']) && $_GET['debug'] === '1';
    if ($debug) {
        $url = $api_base . '/api/v2/events?accesskey=' . urlencode($divera_key);
        $raw = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 20]]));
        echo json_encode([
            'debug' => true,
            'url_called' => $api_base . '/api/v2/events?accesskey=***',
            'raw_response' => $raw,
            'parsed' => is_string($raw) ? json_decode($raw, true) : null,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }
    $from = isset($_GET['from']) ? strtotime($_GET['from']) : strtotime('first day of this year');
    $to = isset($_GET['to']) ? strtotime($_GET['to']) : strtotime('last day of next year');
    $divera_error = '';
    $events = fetch_divera_events($divera_key, $api_base, $from, $to, $divera_error);
    if ($divera_error !== '') {
        echo json_encode(['success' => false, 'message' => $divera_error, 'events' => []]);
        exit;
    }
    echo json_encode(['success' => true, 'events' => $events]);
    exit;
}

// POST: Import oder Export
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $input['action'] ?? $_POST['action'] ?? '';

if ($action === 'import') {
    if ($divera_key === '') {
        echo json_encode(['success' => false, 'message' => 'Kein Divera Access Key konfiguriert']);
        exit;
    }
    $event_ids = $input['event_ids'] ?? [];
    if (!is_array($event_ids) || empty($event_ids)) {
        echo json_encode(['success' => false, 'message' => 'Keine Termine ausgewählt']);
        exit;
    }
    $events = fetch_divera_events($divera_key, $api_base);
    $event_map = [];
    foreach ($events as $e) $event_map[$e['id']] = $e;
    $imported = 0;
    $typen = get_dienstplan_typen_auswahl();
    $default_typ = !empty($typen) ? (array_keys($typen)[0] ?? 'uebungsdienst') : 'uebungsdienst';
    foreach ($event_ids as $eid) {
        $eid = (int) $eid;
        if (!isset($event_map[$eid])) continue;
        $e = $event_map[$eid];
        $datum = date('Y-m-d', $e['ts_start']);
        $title = $e['title'];
        if (preg_match('/^([^:]+):\s*(.+)$/', $title, $m)) {
            $bezeichnung = trim($m[2]);
        } else {
            $bezeichnung = $title;
        }
        if ($bezeichnung === '') $bezeichnung = 'Import aus Divera';
        try {
            $stmt = $db->prepare("INSERT INTO dienstplan (datum, bezeichnung, typ) VALUES (?, ?, ?)");
            $stmt->execute([$datum, $bezeichnung, $default_typ]);
            $imported++;
        } catch (Exception $ex) {
            error_log('Dienstplan Import: ' . $ex->getMessage());
        }
    }
    echo json_encode(['success' => true, 'message' => $imported . ' Termin(e) importiert', 'imported' => $imported]);
    exit;
}

if ($action === 'export') {
    if ($divera_key === '') {
        echo json_encode(['success' => false, 'message' => 'Kein Divera Access Key konfiguriert']);
        exit;
    }
    $entry_ids = $input['entry_ids'] ?? [];
    $group_ids = $input['group_ids'] ?? [];
    if (!is_array($entry_ids) || empty($entry_ids)) {
        echo json_encode(['success' => false, 'message' => 'Keine Einträge ausgewählt']);
        exit;
    }
    $group_ids = array_values(array_filter(array_map('intval', $group_ids), function($v) { return $v >= 0; }));
    $placeholders = implode(',', array_fill(0, count($entry_ids), '?'));
    $stmt = $db->prepare("SELECT id, datum, bezeichnung, typ FROM dienstplan WHERE id IN ($placeholders)");
    $stmt->execute($entry_ids);
    $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $exported = 0;
    $errors = [];
    foreach ($entries as $entry) {
        $res = send_dienstplan_to_divera($entry, $divera_key, $api_base, $group_ids);
        if ($res['success']) {
            $exported++;
        } else {
            $errors[] = $entry['bezeichnung'] . ': ' . $res['error'];
        }
    }
    echo json_encode([
        'success' => true,
        'message' => $exported . ' von ' . count($entries) . ' Termin(e) exportiert',
        'exported' => $exported,
        'errors' => $errors,
    ]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unbekannte Aktion']);
