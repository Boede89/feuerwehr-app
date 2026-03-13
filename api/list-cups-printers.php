<?php
/**
 * Listet verfügbare CUPS-Drucker auf (lpstat -t = alle Infos).
 * Unterstützt CUPS_SERVER (Umgebungsvariable oder printer_cups_server aus Einstellungen).
 */
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/einheit-settings-helper.php';

header('Content-Type: application/json; charset=UTF-8');

if (!isset($_SESSION['user_id']) || !hasAdminPermission()) {
    echo json_encode(['success' => false, 'message' => 'Zugriff verweigert']);
    exit;
}

$einheit_id = isset($_GET['einheit_id']) ? (int)$_GET['einheit_id'] : 0;
$cups_server = '';
if ($einheit_id > 0) {
    $settings = load_settings_for_einheit($db, $einheit_id);
    $cups_server = trim($settings['printer_cups_server'] ?? '');
}
if ($cups_server === '') {
    $env_val = getenv('CUPS_SERVER');
    $cups_server = ($env_val !== false && $env_val !== '') ? $env_val : '';
}

$printers = [];
$lpstat_path = '';
foreach (['/usr/bin/lpstat', '/usr/local/bin/lpstat', 'lpstat'] as $path) {
    if (strpos($path, '/') !== false) {
        if (is_executable($path)) {
            $lpstat_path = $path;
            break;
        }
    } else {
        $out = @shell_exec('which ' . escapeshellarg($path) . ' 2>/dev/null');
        if ($out && trim($out) !== '') {
            $lpstat_path = trim(explode("\n", $out)[0]);
            break;
        }
    }
}

$raw_t = [];

if ($lpstat_path !== '') {
    if ($cups_server !== '') {
        putenv('CUPS_SERVER=' . $cups_server);
    }
    // lpstat -t = alle Drucker (entspricht -r -d -c -v -a -p -o)
    $output = [];
    @exec(escapeshellarg($lpstat_path) . ' -t 2>/dev/null', $output);
    $raw_t = $output;

    $seen = [];
    foreach ($output as $line) {
        $line = trim($line);
        // "printer NAME is idle. enabled since ..."
        if (preg_match('/^printer\s+(.+?)\s+is\s+/', $line, $m)) {
            $name = trim($m[1]);
            if ($name !== '' && empty($seen[$name])) {
                $printers[] = ['name' => $name, 'display' => $name];
                $seen[$name] = true;
            }
        }
        // "device for NAME: uri" (Druckername kann Leerzeichen haben)
        elseif (preg_match('/^device for (.+?):\s+/', $line, $m)) {
            $name = trim($m[1]);
            if ($name !== '' && empty($seen[$name])) {
                $printers[] = ['name' => $name, 'display' => $name];
                $seen[$name] = true;
            }
        }
        // "NAME accepting requests since ..." (lpstat -a)
        elseif (preg_match('/^(.+)\s+accepting requests\s+/', $line, $m)) {
            $name = trim($m[1]);
            if ($name !== '' && empty($seen[$name])) {
                $printers[] = ['name' => $name, 'display' => $name];
                $seen[$name] = true;
            }
        }
    }
}

$debug = isset($_GET['debug']) && $_GET['debug'] === '1';
$response = [
    'success' => true,
    'printers' => $printers,
    'cups_available' => $lpstat_path !== '',
];
if ($debug && $lpstat_path !== '') {
    $response['debug'] = [
        'lpstat_path' => $lpstat_path,
        'cups_server' => $cups_server ?: 'nicht gesetzt',
        'einheit_id' => $einheit_id,
        'lpstat_t_raw' => $raw_t,
    ];
}

echo json_encode($response);
