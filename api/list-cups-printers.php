<?php
/**
 * Listet verfügbare CUPS-Drucker auf (lpstat -p, Fallback: lpstat -v).
 * Unterstützt CUPS_SERVER-Umgebungsvariable für Docker (z.B. Host-CUPS).
 */
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=UTF-8');

if (!isset($_SESSION['user_id']) || !hasAdminPermission()) {
    echo json_encode(['success' => false, 'message' => 'Zugriff verweigert']);
    exit;
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
    $env = [];
    $cups_server = getenv('CUPS_SERVER');
    if ($cups_server !== false && $cups_server !== '') {
        $env['CUPS_SERVER'] = $cups_server;
    }

    $cmd = escapeshellarg($lpstat_path) . ' -p 2>/dev/null';
    $output = [];
    foreach ($env as $k => $v) {
        putenv($k . '=' . $v);
    }
    @exec($cmd, $output);
    $raw_p = $output;

    foreach ($output as $line) {
        if (preg_match('/^printer\s+(\S+)\s+/', trim($line), $m)) {
            $printers[] = ['name' => $m[1], 'display' => $m[1]];
        }
    }

    // Fallback: lpstat -v zeigt alle Drucker (auch deaktivierte)
    if (empty($printers)) {
        $output_v = [];
        @exec(escapeshellarg($lpstat_path) . ' -v 2>/dev/null', $output_v);
        $raw_v = $output_v;
        foreach ($output_v as $line) {
            if (preg_match('/^device for (\S+):\s+/', trim($line), $m)) {
                $printers[] = ['name' => $m[1], 'display' => $m[1]];
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
        'cups_server' => getenv('CUPS_SERVER') ?: 'nicht gesetzt',
        'lpstat_p_raw' => $raw_p,
        'lpstat_v_raw' => $raw_v,
    ];
}

echo json_encode($response);
