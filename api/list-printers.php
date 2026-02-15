<?php
/**
 * Listet verfügbare CUPS-Drucker auf (für Drucker-Konfiguration).
 */
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=UTF-8');

if (!isset($_SESSION['user_id']) || !hasAdminPermission()) {
    echo json_encode(['success' => false, 'printers' => [], 'message' => 'Keine Berechtigung']);
    exit;
}

$lp_bin = (file_exists('/usr/bin/lp') && is_executable('/usr/bin/lp')) ? '/usr/bin/lp' : 'lp';
$lpstat_bin = (file_exists('/usr/bin/lpstat') && is_executable('/usr/bin/lpstat')) ? '/usr/bin/lpstat' : 'lpstat';

$printer_cups_server = '';
try {
    $stmt = $db->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
    $stmt->execute(['printer_cups_server']);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && trim($row['setting_value'] ?? '') !== '') {
        $printer_cups_server = trim($row['setting_value']);
    }
} catch (Exception $e) {}
if ($printer_cups_server === '' && getenv('CUPS_SERVER') !== false) {
    $printer_cups_server = trim(getenv('CUPS_SERVER'));
}
$env_prefix = ($printer_cups_server !== '') ? 'CUPS_SERVER=' . escapeshellarg($printer_cups_server) . ' ' : '';

$printers = [];
$raw = '';
$err = '';

exec($env_prefix . escapeshellarg($lpstat_bin) . ' -p 2>&1', $lines, $ret);
$raw = implode("\n", $lines);

if ($ret !== 0) {
    $err = trim($raw);
    if (strpos($err, 'not found') !== false || strpos($err, 'No such file') !== false) {
        echo json_encode(['success' => false, 'printers' => [], 'message' => 'lpstat nicht gefunden. CUPS-Client installiert?', 'raw' => $raw]);
        exit;
    }
    if (strpos($err, 'Unable to connect') !== false || strpos($err, 'Connection refused') !== false) {
        echo json_encode(['success' => false, 'printers' => [], 'message' => 'Keine Verbindung zum CUPS-Server. Setzen Sie CUPS_SERVER in docker-compose (z.B. host.docker.internal oder Host-IP).', 'raw' => $raw]);
        exit;
    }
}

foreach ($lines as $line) {
    if (preg_match('/^printer\s+(\S+)\s+/', $line, $m)) {
        $printers[] = ['name' => $m[1], 'line' => trim($line)];
    }
}

exec($env_prefix . escapeshellarg($lpstat_bin) . ' -d 2>&1', $def_lines, $dret);
$default_printer = '';
foreach ($def_lines as $line) {
    if (preg_match('/^system default destination:\s*(\S+)/', $line, $m)) {
        $default_printer = $m[1];
        break;
    }
}

echo json_encode([
    'success' => true,
    'printers' => $printers,
    'default_printer' => $default_printer,
    'raw' => $raw,
    'message' => empty($printers) ? 'Keine Drucker gefunden. CUPS-Server konfigurieren (CUPS_SERVER) und Drucker hinzufügen.' : ''
]);
