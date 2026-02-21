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
$configured_printer = '';
try {
    $stmt = $db->prepare('SELECT setting_key, setting_value FROM settings WHERE setting_key IN (?, ?)');
    $stmt->execute(['printer_cups_server', 'printer_destination']);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $val = trim($row['setting_value'] ?? '');
        if ($row['setting_key'] === 'printer_cups_server' && $val !== '') $printer_cups_server = $val;
        if ($row['setting_key'] === 'printer_destination' && $val !== '') $configured_printer = $val;
    }
} catch (Exception $e) {}
if ($printer_cups_server === '' && getenv('CUPS_SERVER') !== false) {
    $printer_cups_server = trim(getenv('CUPS_SERVER'));
}
// Fallback: Docker-Socket-Mount (Apache übergibt CUPS_SERVER via PassEnv; falls nicht, hier nutzen)
if ($printer_cups_server === '' && file_exists('/run/cups/cups.sock')) {
    $printer_cups_server = '/run/cups/cups.sock';
}
$env_prefix = ($printer_cups_server !== '') ? 'CUPS_SERVER=' . escapeshellarg($printer_cups_server) . ' ' : '';

$printers = [];
$raw = '';
$err = '';
$cups_servers_tried = [];

// Primärer Versuch
exec($env_prefix . escapeshellarg($lpstat_bin) . ' -p 2>&1', $lines, $ret);
$raw = implode("\n", $lines);
$cups_servers_tried[] = $printer_cups_server ?: '(Standard)';

// Fallback: Wenn primär fehlschlägt, Netzwerk-Varianten probieren (TCP stabiler als Socket bei lang laufenden Containern)
$cups_working = $printer_cups_server;
$fallbacks = ['host.docker.internal:631', '172.17.0.1:631', '172.18.0.1:631', '172.17.0.1', '172.18.0.1'];
foreach ($fallbacks as $fb) {
    if ($ret === 0) break;
    if ($fb === $printer_cups_server || ($printer_cups_server === '' && $fb === getenv('CUPS_SERVER'))) continue;
    $env_fb = 'CUPS_SERVER=' . escapeshellarg($fb) . ' ';
    exec($env_fb . escapeshellarg($lpstat_bin) . ' -p 2>&1', $lines, $ret);
    $raw = implode("\n", $lines);
    $cups_servers_tried[] = $fb;
    if ($ret === 0) { $cups_working = $fb; break; }
}

$manual_hint = 'Druckername manuell eintragen (z.B. workplacepure) – funktioniert auch ohne CUPS-Erkennung.';
if ($ret !== 0) {
    $err = trim($raw);
    $msg = '';
    if (strpos($err, 'not found') !== false || strpos($err, 'No such file') !== false) {
        $msg = 'lpstat nicht gefunden. ' . $manual_hint;
    } elseif (strpos($err, 'Unable to connect') !== false || strpos($err, 'Connection refused') !== false) {
        $msg = 'CUPS nicht erreichbar. Linux-Host: sudo systemctl start cups. ' . $manual_hint;
    } elseif (stripos($err, 'Forbidden') !== false) {
        $msg = 'CUPS blockiert Zugriff. Auf dem Host: sudo bash docker/cups-allow-docker.sh (oder cupsd.conf: Allow from 172.17.0.0/16). ' . $manual_hint;
    } elseif (stripos($err, 'Scheduler is not running') !== false || stripos($err, 'cupsd') !== false) {
        $msg = 'CUPS nicht erreichbar. ';
        $is_socket = ($printer_cups_server !== '' && strpos($printer_cups_server, '/') !== false);
        if ($is_socket) {
            if (is_dir($printer_cups_server)) {
                $msg .= 'Socket-Pfad ist ein Verzeichnis (Docker-Mount-Problem). Container neu starten: docker compose restart web – nachdem CUPS auf dem Host läuft. ';
            } elseif (!file_exists($printer_cups_server)) {
                $msg .= 'Socket nicht vorhanden. Auf dem Host: sudo systemctl start cups. Danach: docker compose restart web. ';
            } else {
                $msg .= 'Socket vorhanden, CUPS antwortet nicht. Auf dem Host: sudo systemctl restart cups. Danach: docker compose restart web. ';
            }
        } else {
            $msg .= 'Linux-Host: sudo systemctl start cups. Danach: docker compose restart web. ';
        }
        $msg .= $manual_hint;
    } else {
        $msg = 'lpstat Fehler. ' . $manual_hint;
    }
    echo json_encode([
        'success' => false,
        'printers' => [],
        'message' => $msg,
        'configured_printer' => $configured_printer,
        'raw' => $raw
    ]);
    exit;
}

foreach ($lines as $line) {
    if (preg_match('/^printer\s+(\S+)\s+/', $line, $m)) {
        $printers[] = ['name' => $m[1], 'line' => trim($line)];
    }
}

// Device-URIs für Cloud-Drucker (lpstat -v), z.B. für IPP mit Anmeldung
$include_uris = !empty($_GET['uris']);
$device_uris = [];
if ($include_uris) {
    $env_v = ($cups_working !== '') ? 'CUPS_SERVER=' . escapeshellarg($cups_working) . ' ' : '';
    exec($env_v . escapeshellarg($lpstat_bin) . ' -v 2>&1', $v_lines, $v_ret);
    if ($v_ret === 0) {
        foreach ($v_lines as $v_line) {
            if (preg_match('/^device for (\S+):\s*(.+)$/', trim($v_line), $vm)) {
                $device_uris[trim($vm[1])] = trim($vm[2]);
            }
        }
    }
    foreach ($printers as &$p) {
        $p['device_uri'] = $device_uris[$p['name']] ?? '';
    }
    unset($p);
}

$env_working = ($cups_working !== '') ? 'CUPS_SERVER=' . escapeshellarg($cups_working) . ' ' : '';
exec($env_working . escapeshellarg($lpstat_bin) . ' -d 2>&1', $def_lines, $dret);
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
    'configured_printer' => $configured_printer,
    'raw' => $raw,
    'cups_server_used' => $cups_working ?: $printer_cups_server ?: '(Standard/Umgebung)',
    'message' => $empty_msg
]);
