<?php
/**
 * Drucker verwalten: hinzufügen, bearbeiten, löschen, als Standard setzen.
 */
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/einheit-settings-helper.php';
require_once __DIR__ . '/print-helper.inc.php';

header('Content-Type: application/json; charset=UTF-8');

if (!isset($_SESSION['user_id']) || !hasAdminPermission()) {
    echo json_encode(['success' => false, 'message' => 'Zugriff verweigert']);
    exit;
}

$einheit_id = isset($_POST['einheit_id']) ? (int)$_POST['einheit_id'] : (isset($_GET['einheit_id']) ? (int)$_GET['einheit_id'] : 0);
if ($einheit_id <= 0 || !user_has_einheit_access($_SESSION['user_id'], $einheit_id)) {
    echo json_encode(['success' => false, 'message' => 'Ungültige Einheit.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET' && !validate_csrf_token($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Ungültiger Sicherheitstoken.']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$settings = load_settings_for_einheit($db, $einheit_id);
$cups_server = trim($_POST['printer_cups_server'] ?? $settings['printer_cups_server'] ?? '') ?: (getenv('CUPS_SERVER') ?: 'host.docker.internal:631');

$list = print_get_printer_list($db, $einheit_id);
$cups_server_saved = trim($settings['printer_cups_server'] ?? '') ?: 'host.docker.internal:631';

if ($action === 'list' || $action === '') {
    echo json_encode(['success' => true, 'printers' => $list, 'cups_server' => $cups_server_saved]);
    exit;
}

if ($action === 'add' || $action === 'edit') {
    $type = trim($_POST['printer_type'] ?? 'cups');
    $name = trim($_POST['printer_name'] ?? '');
    $id = trim($_POST['printer_id'] ?? '');
    $is_default = isset($_POST['printer_is_default']) && $_POST['printer_is_default'] === '1';

    if ($name === '') {
        echo json_encode(['success' => false, 'message' => 'Bitte einen Anzeigenamen eingeben.']);
        exit;
    }

    $cups_name = preg_replace('/[^a-zA-Z0-9_-]/', '', $name);
    if ($cups_name === '') $cups_name = 'printer' . time();

    if ($type === 'cloud') {
        $cloud_url = trim($_POST['printer_cloud_url'] ?? '');
        $cloud_raw = isset($_POST['printer_cloud_raw']) && $_POST['printer_cloud_raw'] === '1';
        if ($cloud_url === '' || !preg_match('#^https?://#i', $cloud_url)) {
            echo json_encode(['success' => false, 'message' => 'Ungültige Cloud-Drucker-URL.']);
            exit;
        }
        $printer = [
            'id' => $id ?: 'p' . uniqid(),
            'name' => $name,
            'type' => 'cloud',
            'cloud_url' => $cloud_url,
            'cloud_raw' => $cloud_raw,
            'is_default' => $is_default,
        ];
    } else {
        $uri = trim($_POST['printer_uri'] ?? '');
        $model = trim($_POST['printer_model'] ?? 'everywhere') ?: 'everywhere';
        if ($uri === '' || !preg_match('#^(ipp|usb|socket|lpd|smb|https?)://#i', $uri)) {
            echo json_encode(['success' => false, 'message' => 'Ungültige Drucker-URI. Beispiele: ipp://192.168.1.10/ipp/print, usb://...']);
            exit;
        }
        $printer = [
            'id' => $id ?: 'p' . uniqid(),
            'name' => $name,
            'type' => 'cups',
            'cups_name' => $cups_name,
            'cups_uri' => $uri,
            'cups_model' => $model,
            'is_default' => $is_default,
        ];
        $skip_lpadmin = isset($_POST['printer_skip_lpadmin']) && $_POST['printer_skip_lpadmin'] === '1';
        if (!$skip_lpadmin) {
            $reg = print_register_cups_printer($cups_name, $uri, $model, $cups_server);
            if (!$reg['success']) {
                echo json_encode([
                    'success' => false,
                    'message' => $reg['message'],
                    'lpadmin_cmd' => $reg['lpadmin_cmd'] ?? '',
                ]);
                exit;
            }
        }
    }

    if ($action === 'edit' && $id) {
        foreach ($list as $i => $p) {
            if (($p['id'] ?? '') === $id) {
                $list[$i] = $printer;
                break;
            }
        }
    } else {
        foreach ($list as &$p) $p['is_default'] = false;
        $list[] = $printer;
    }
    if ($is_default) {
        foreach ($list as &$p) $p['is_default'] = (($p['id'] ?? '') === ($printer['id'] ?? ''));
    }

    ensure_einheit_settings_table($db);
    save_setting_for_einheit($db, $einheit_id, 'printer_list', json_encode($list));
    save_setting_for_einheit($db, $einheit_id, 'printer_cups_server', $cups_server);

    echo json_encode(['success' => true, 'message' => $action === 'edit' ? 'Drucker aktualisiert.' : 'Drucker hinzugefügt.', 'printers' => $list]);
    exit;
}

if ($action === 'delete') {
    $id = trim($_POST['printer_id'] ?? '');
    if ($id === '') {
        echo json_encode(['success' => false, 'message' => 'Keine Drucker-ID angegeben.']);
        exit;
    }
    $list = array_values(array_filter($list, function ($p) use ($id) {
        return ($p['id'] ?? '') !== $id;
    }));
    if (count($list) > 0 && !array_filter($list, function ($p) { return !empty($p['is_default']); })) {
        $list[0]['is_default'] = true;
    }
    save_setting_for_einheit($db, $einheit_id, 'printer_list', json_encode($list));
    echo json_encode(['success' => true, 'message' => 'Drucker entfernt.', 'printers' => $list]);
    exit;
}

if ($action === 'set_default') {
    $id = trim($_POST['printer_id'] ?? '');
    if ($id === '') {
        echo json_encode(['success' => false, 'message' => 'Keine Drucker-ID angegeben.']);
        exit;
    }
    foreach ($list as &$p) {
        $p['is_default'] = (($p['id'] ?? '') === $id);
    }
    save_setting_for_einheit($db, $einheit_id, 'printer_list', json_encode($list));
    echo json_encode(['success' => true, 'message' => 'Standard-Drucker gesetzt.', 'printers' => $list]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unbekannte Aktion.']);
