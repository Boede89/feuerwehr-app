<?php
/**
 * Listet verfügbare CUPS-Drucker auf (lpstat -p, Fallback: lpstat -v).
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

$raw_p = [];
$raw_v = [];

if ($lpstat_path !== '') {
    if ($cups_server !== '') {
        putenv('CUPS_SERVER=' . $cups_server);
    }
    $output = [];
    @exec(escapeshellarg($lpstat_path) . ' -p 2>/dev/null', $output);
    $raw_p = $output;

    $seen = [];
    foreach ($output as $line) {
        if (preg_match('/^printer\s+(\S+)\s+/', trim($line), $m)) {
            $printers[] = ['name' => $m[1], 'display' => $m[1]];
            $seen[$m[1]] = true;
        }
    }

    // lpstat -v zeigt alle Drucker (auch deaktivierte) – immer ausführen und ergänzen
    $output_v = [];
    @exec(escapeshellarg($lpstat_path) . ' -v 2>/dev/null', $output_v);
    $raw_v = $output_v;
    foreach ($output_v as $line) {
        if (preg_match('/^device for (\S+):\s+/', trim($line), $m) && empty($seen[$m[1]])) {
            $printers[] = ['name' => $m[1], 'display' => $m[1]];
            $seen[$m[1]] = true;
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
        'lpstat_p_raw' => $raw_p,
        'lpstat_v_raw' => $raw_v,
    ];
}

echo json_encode($response);
