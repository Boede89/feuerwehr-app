<?php
/**
 * Mängelbericht server-seitig drucken.
 */
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=UTF-8');

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    echo json_encode(['success' => false, 'message' => 'Nicht angemeldet']);
    exit;
}
if (!has_permission('forms')) {
    echo json_encode(['success' => false, 'message' => 'Keine Berechtigung']);
    exit;
}

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$alle = !empty($_GET['alle']) || !empty($_POST['alle']);
$ids = trim($_GET['ids'] ?? $_POST['ids'] ?? '');
$filter_datum_von = trim($_GET['filter_datum_von'] ?? $_POST['filter_datum_von'] ?? '');
$filter_datum_bis = trim($_GET['filter_datum_bis'] ?? $_POST['filter_datum_bis'] ?? '');

if (!$alle && $id <= 0 && $ids === '') {
    echo json_encode(['success' => false, 'message' => 'Ungültige ID']);
    exit;
}

$settings = [];
try {
    $stmt = $db->prepare('SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE ? OR setting_key = ?');
    $stmt->execute(['printer_%', 'app_url']);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Exception $e) {}

$printer_type = trim($settings['printer_type'] ?? 'local');
$printer_destination = trim($settings['printer_destination'] ?? '');
$printer_cups_server = trim($settings['printer_cups_server'] ?? '');
if ($printer_cups_server === '' && getenv('CUPS_SERVER') !== false) {
    $printer_cups_server = trim(getenv('CUPS_SERVER'));
}
$printer_ipp_url = trim($settings['printer_ipp_url'] ?? '');
$printer_username = trim($settings['printer_username'] ?? '');
$printer_password = trim($settings['printer_password'] ?? '');

$has_printer = false;
if ($printer_type === 'ipp' && $printer_ipp_url !== '') {
    $has_printer = true;
} elseif ($printer_type === 'local') {
    $has_printer = true;
}

if (!$has_printer) {
    echo json_encode(['success' => false, 'message' => 'Kein Drucker konfiguriert. Bitte in den globalen Einstellungen einen lokalen Drucker oder IPP-Drucker hinterlegen.']);
    exit;
}

$GLOBALS['_mb_pdf_content'] = null;
if ($alle || $ids !== '') {
    $_GET['_return'] = '1';
    $_GET['filter_datum_von'] = $filter_datum_von;
    $_GET['filter_datum_bis'] = $filter_datum_bis;
    if ($ids !== '') $_GET['ids'] = $ids;
    require __DIR__ . '/maengelbericht-pdf-alle.php';
} else {
    $_GET['_return'] = '1';
    $_GET['id'] = $id;
    require __DIR__ . '/maengelbericht-pdf.php';
}
$pdf_content = $GLOBALS['_mb_pdf_content'] ?? null;

if ($pdf_content === null || strlen($pdf_content) < 100) {
    echo json_encode(['success' => false, 'message' => 'PDF konnte nicht erzeugt werden. Prüfen Sie wkhtmltopdf, Dompdf oder TCPDF.']);
    exit;
}

$pdfPath = tempnam(sys_get_temp_dir(), 'mb_print_') . '.pdf';
if (file_put_contents($pdfPath, $pdf_content) === false) {
    echo json_encode(['success' => false, 'message' => 'Temporäre Datei konnte nicht erstellt werden.']);
    exit;
}

$lp_bin = (file_exists('/usr/bin/lp') && is_executable('/usr/bin/lp')) ? '/usr/bin/lp' : 'lp';
$lp_cmd = '';
if ($printer_type === 'ipp' && $printer_ipp_url !== '') {
    $dest = $printer_ipp_url;
    if ($printer_username !== '' && $printer_password !== '') {
        $parsed = parse_url($printer_ipp_url);
        $scheme = $parsed['scheme'] ?? 'ipp';
        $host = $parsed['host'] ?? '';
        $path = $parsed['path'] ?? '/ipp/print';
        $port = $parsed['port'] ?? ($scheme === 'ipps' ? 443 : 631);
        $dest = $scheme . '://' . rawurlencode($printer_username) . ':' . rawurlencode($printer_password) . '@' . $host . ($port ? ':' . $port : '') . $path;
    }
    $lp_cmd = escapeshellarg($lp_bin) . ' -d ' . escapeshellarg($dest) . ' ' . escapeshellarg($pdfPath) . ' 2>&1';
} else {
    if ($printer_destination !== '') {
        $lp_cmd = escapeshellarg($lp_bin) . ' -d ' . escapeshellarg($printer_destination) . ' ' . escapeshellarg($pdfPath) . ' 2>&1';
    } else {
        $lp_cmd = escapeshellarg($lp_bin) . ' ' . escapeshellarg($pdfPath) . ' 2>&1';
    }
}

$env_prefix = '';
if ($printer_cups_server !== '') {
    $env_prefix = 'CUPS_SERVER=' . escapeshellarg($printer_cups_server) . ' ';
}

$output = [];
$return_var = 0;
exec($env_prefix . $lp_cmd, $output, $return_var);
@unlink($pdfPath);

if ($return_var !== 0) {
    $err = implode(' ', $output);
    error_log('print-maengelbericht lp error: ' . $err);
    echo json_encode(['success' => false, 'message' => 'Druckauftrag fehlgeschlagen. ' . $err]);
    exit;
}

echo json_encode(['success' => true, 'message' => 'Druckauftrag wurde an den Drucker gesendet.']);
