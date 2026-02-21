<?php
/**
 * Verfügbare Drucker auflisten (für Einstellungen).
 */
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/print-helper.inc.php';

header('Content-Type: application/json; charset=UTF-8');

if (!isset($_SESSION['user_id']) || !hasAdminPermission()) {
    echo json_encode(['success' => false, 'message' => 'Zugriff verweigert']);
    exit;
}

$config = print_get_printer_config($db);
$cups_server = $config['cups_server'] ?: getenv('CUPS_SERVER') ?: ($_SERVER['CUPS_SERVER'] ?? '');
$configured = $config['printer'];

$printers = [];
$default_printer = '';

$env = $cups_server ? 'CUPS_SERVER=' . escapeshellarg($cups_server) . ' ' : '';
$out = [];
@exec($env . 'lpstat -v 2>&1', $out);
foreach ($out as $line) {
    if (preg_match('/device for (\S+):\s*(.+)/', $line, $m)) {
        $printers[] = ['name' => $m[1], 'device_uri' => trim($m[2])];
    }
}

$out2 = [];
@exec($env . 'lpstat -d 2>/dev/null', $out2);
foreach ($out2 as $line) {
    if (preg_match('/system default destination:\s*(\S+)/', $line, $m)) {
        $default_printer = $m[1];
        break;
    }
}

echo json_encode([
    'success' => true,
    'printers' => $printers,
    'default_printer' => $default_printer,
    'configured_printer' => $configured,
]);
