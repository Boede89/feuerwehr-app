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
        $hint = $printer_cups_server !== ''
            ? 'CUPS-Server ' . $printer_cups_server . ' nicht erreichbar. Prüfen Sie: CUPS auf Host läuft, hört auf 0.0.0.0:631, Firewall.'
            : 'Bei Docker: „CUPS-Server (Docker)“ in den Einstellungen auf 172.17.0.1 setzen, oder CUPS_SERVER in docker-compose.';
        echo json_encode(['success' => false, 'printers' => [], 'message' => 'Keine Verbindung zum CUPS-Server. ' . $hint, 'raw' => $raw]);
        exit;
    }
    if (stripos($err, 'Forbidden') !== false) {
        $hint = 'CUPS blockiert den Zugriff vom Container. Auf dem Host in /etc/cups/cupsd.conf: 1) ServerAlias * einfügen (für Host-Header). 2) Im Abschnitt Location / die Zeile Allow from 172.17.0.0/16. Dann: sudo systemctl restart cups';
        echo json_encode(['success' => false, 'printers' => [], 'message' => 'Zugriff verweigert (Forbidden). ' . $hint, 'raw' => $raw]);
        exit;
    }
    if (stripos($err, 'Scheduler is not running') !== false || stripos($err, 'cupsd') !== false) {
        if ($printer_cups_server !== '') {
            $is_socket = (strpos($printer_cups_server, '/') !== false);
            $hint = $is_socket
                ? 'CUPS-Dienst läuft nicht. Führen Sie aus: sudo systemctl start cups (oder sudo service cups start).'
                : 'CUPS auf dem Host (' . $printer_cups_server . ') läuft nicht. Auf dem Host: sudo systemctl start cups. Bei Docker: Host-IP (z.B. 172.17.0.1) statt Socket verwenden.';
        } else {
            $hint = 'CUPS-Dienst läuft nicht. Führen Sie aus: sudo systemctl start cups. Bei Docker: CUPS-Server in den Einstellungen eintragen (z.B. 172.17.0.1).';
        }
        echo json_encode(['success' => false, 'printers' => [], 'message' => 'CUPS-Scheduler läuft nicht. ' . $hint, 'raw' => $raw]);
        exit;
    }
    echo json_encode(['success' => false, 'printers' => [], 'message' => 'lpstat Fehler: ' . $err, 'raw' => $raw]);
    exit;
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

$empty_msg = '';
if (empty($printers)) {
    if ($printer_cups_server !== '') {
        $empty_msg = 'Keine Drucker gefunden. Der Host-CUPS (' . $printer_cups_server . ') hat keine Drucker. Prüfen Sie auf dem Host: lpstat -p';
    } else {
        $empty_msg = 'Keine Drucker gefunden. Bei Docker: Tragen Sie unter „CUPS-Server (Docker)“ die Host-IP ein (z.B. 172.17.0.1) und speichern Sie. Oder setzen Sie CUPS_SERVER in docker-compose.';
    }
}

echo json_encode([
    'success' => true,
    'printers' => $printers,
    'default_printer' => $default_printer,
    'raw' => $raw,
    'cups_server_used' => $printer_cups_server ?: '(Standard/Umgebung)',
    'message' => $empty_msg
]);
