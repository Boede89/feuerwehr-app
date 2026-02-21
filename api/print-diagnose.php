<?php
/**
 * Druck-Diagnose: CUPS-Verbindung, Drucker-Liste und Test-Druck prüfen.
 * Aufruf: api/print-diagnose.php oder api/print-diagnose.php?test=1 (Test-PDF drucken)
 */
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/print-helper.inc.php';

header('Content-Type: application/json; charset=UTF-8');

if (!isset($_SESSION['user_id']) || !hasAdminPermission()) {
    echo json_encode(['success' => false, 'message' => 'Keine Berechtigung']);
    exit;
}

$settings = [];
try {
    $stmt = $db->prepare('SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE ?');
    $stmt->execute(['printer_%']);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Exception $e) {}

$printer_cups_server = trim($settings['printer_cups_server'] ?? '');
if ($printer_cups_server === '' && getenv('CUPS_SERVER') !== false) {
    $printer_cups_server = trim(getenv('CUPS_SERVER'));
}

$cups_servers = print_helper_get_cups_servers($printer_cups_server);
$default_printer = print_helper_get_default_printer($cups_servers);

// Drucker-Liste via lpstat -p
$lpstat_bin = (file_exists('/usr/bin/lpstat') && is_executable('/usr/bin/lpstat')) ? '/usr/bin/lpstat' : 'lpstat';
$printers = [];
$cups_reachable = false;
$lpstat_output = '';

foreach ($cups_servers as $cups_srv) {
    $env_prefix = ($cups_srv !== '') ? 'CUPS_SERVER=' . escapeshellarg($cups_srv) . ' ' : '';
    $lines = [];
    exec($env_prefix . escapeshellarg($lpstat_bin) . ' -p 2>&1', $lines, $ret);
    $lpstat_output = implode("\n", $lines);
    if ($ret === 0) {
        $cups_reachable = true;
        foreach ($lines as $line) {
            if (preg_match('/^printer\s+(\S+)\s+/', $line, $m)) {
                $printers[] = $m[1];
            }
        }
        break;
    }
}

$result = [
    'success' => true,
    'cups_reachable' => $cups_reachable,
    'cups_servers_tried' => $cups_servers,
    'default_printer' => $default_printer,
    'printers' => array_values(array_unique($printers)),
    'configured_destination' => trim($settings['printer_destination'] ?? ''),
    'lpstat_output' => $lpstat_output,
    'hints' => []
];

if (!$cups_reachable) {
    $result['hints'][] = 'CUPS ist nicht erreichbar. Auf dem Host: sudo systemctl start cups. Docker: CUPS_SERVER in docker-compose prüfen (z.B. host.docker.internal:631).';
}
if (empty($printers) && $cups_reachable) {
    $result['hints'][] = 'Keine Drucker in CUPS gefunden. Auf dem Host: lpstat -p prüfen, Drucker mit lpadmin anlegen.';
}
if ($default_printer === null && $cups_reachable) {
    $result['hints'][] = 'Kein Standard-Drucker gesetzt. In den Einstellungen einen Drucker auswählen oder auf dem Host: lpoptions -d DRUCKERNAME';
}
if (empty($result['configured_destination']) && $default_printer !== null) {
    $result['hints'][] = 'Es wird der Standard-Drucker "' . $default_printer . '" verwendet. Für zuverlässigen Druck: In Einstellungen > Drucker explizit einen Drucker auswählen.';
}

// Optional: Test-Druck
if (!empty($_GET['test']) || !empty($_POST['test'])) {
    $pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/MediaBox[0 0 595 842]/Parent 2 0 R>>endobj\nxref\n0 4\n0000000000 65535 f\n0000000009 00000 n\n0000000052 00000 n\n0000000101 00000 n\ntrailer<</Size 4/Root 1 0 R>>\nstartxref\n178\n%%EOF";
    $pdfPath = tempnam(sys_get_temp_dir(), 'diag_print_') . '.pdf';
    file_put_contents($pdfPath, $pdf);

    $printer_type = trim($settings['printer_type'] ?? 'local');
    $effective_destination = null;

    if ($printer_type === 'ipp') {
        $ipp_url = trim($settings['printer_ipp_url'] ?? '');
        $ipp_user = trim($settings['printer_username'] ?? '');
        $ipp_pass = trim($settings['printer_password'] ?? '');
        if ($ipp_url !== '' && $ipp_user !== '' && $ipp_pass !== '') {
            $parsed = parse_url($ipp_url);
            $scheme = $parsed['scheme'] ?? 'ipp';
            $host = $parsed['host'] ?? '';
            $path = isset($parsed['path']) ? $parsed['path'] : '/ipp/print';
            if ($path === '' || $path[0] !== '/') $path = '/' . $path;
            if (!empty($parsed['query'])) $path .= '?' . $parsed['query'];
            $port = $parsed['port'] ?? ($scheme === 'ipps' ? 443 : 631);
            $effective_destination = $scheme . '://' . rawurlencode($ipp_user) . ':' . rawurlencode($ipp_pass) . '@' . $host . ($port ? ':' . $port : '') . $path;
        }
    } else {
        $effective_destination = trim($settings['printer_destination'] ?? '') ?: $default_printer;
    }

    if ($effective_destination === null || $effective_destination === '') {
        $result['test_print'] = ['success' => false, 'message' => 'Kein Drucker verfügbar. Bei Cloud-Drucker: URL, Benutzername und Passwort in den Einstellungen eintragen.'];
    } else {
        $lp_bin = (file_exists('/usr/bin/lp') && is_executable('/usr/bin/lp')) ? '/usr/bin/lp' : 'lp';
        $lp_cmd = escapeshellarg($lp_bin) . ' -d ' . escapeshellarg($effective_destination) . ' ' . escapeshellarg($pdfPath) . ' 2>&1';
        list($test_ok, $test_output, , $test_cups) = print_helper_run_lp($lp_cmd, $cups_servers);
        @unlink($pdfPath);
        $result['test_print'] = [
            'success' => $test_ok,
            'lp_output' => $test_output,
            'job_id' => $test_ok ? print_helper_parse_job_id($test_output) : null,
            'cups_used' => $test_cups,
            'printer_used' => $printer_type === 'ipp' ? '(Cloud-Drucker mit Anmeldung)' : $effective_destination
        ];
    }
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
