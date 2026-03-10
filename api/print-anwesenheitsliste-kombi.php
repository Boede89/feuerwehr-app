<?php
/**
 * Anwesenheitsliste + Mängelbericht(e) + Gerätewartmitteilung in einer E-Mail drucken.
 * Alle ausgewählten PDFs werden in einer E-Mail zusammengefasst (E-Mail Druck Tool).
 */
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/print-helper.inc.php';

header('Content-Type: application/json; charset=UTF-8');

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    echo json_encode(['success' => false, 'message' => 'Zugriff verweigert']);
    exit;
}
if (!has_form_fill_permission()) {
    echo json_encode(['success' => false, 'message' => 'Zugriff verweigert']);
    exit;
}

$print_al = (int)($_GET['print'] ?? 0);
$print_mb_ids = trim($_GET['print_maengelbericht'] ?? '');
$print_gwm = (int)($_GET['print_geraetewartmitteilung'] ?? 0);
$einheit_id = isset($_GET['einheit_id']) ? (int)$_GET['einheit_id'] : null;

if ($print_al <= 0 && $print_mb_ids === '' && $print_gwm <= 0) {
    echo json_encode(['success' => false, 'message' => 'Keine Druckaufträge angegeben.']);
    exit;
}

$_GET['_return'] = '1';
$attachments = [];
$first_pdf = null;

// Einheit ermitteln
if ($einheit_id <= 0 && $print_al > 0) {
    $stmt = $db->prepare("SELECT a.einheit_id, u.einheit_id AS user_einheit_id FROM anwesenheitslisten a LEFT JOIN users u ON u.id = a.user_id WHERE a.id = ?");
    $stmt->execute([$print_al]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($r && (int)($r['einheit_id'] ?? 0) > 0) $einheit_id = (int)$r['einheit_id'];
    elseif ($r && (int)($r['user_einheit_id'] ?? 0) > 0) $einheit_id = (int)$r['user_einheit_id'];
}
if ($einheit_id <= 0 && $print_gwm > 0) {
    $stmt = $db->prepare("SELECT g.einheit_id, u.einheit_id AS user_einheit_id FROM geraetewartmitteilungen g LEFT JOIN users u ON u.id = g.user_id WHERE g.id = ?");
    $stmt->execute([$print_gwm]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($r && (int)($r['einheit_id'] ?? 0) > 0) $einheit_id = (int)$r['einheit_id'];
    elseif ($r && (int)($r['user_einheit_id'] ?? 0) > 0) $einheit_id = (int)$r['user_einheit_id'];
}
if ($einheit_id <= 0 && $print_mb_ids !== '') {
    $first_mb = (int)explode(',', $print_mb_ids)[0];
    if ($first_mb > 0) {
        $stmt = $db->prepare("SELECT m.einheit_id, u.einheit_id AS user_einheit_id FROM maengelberichte m LEFT JOIN users u ON u.id = m.user_id WHERE m.id = ?");
        $stmt->execute([$first_mb]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($r && (int)($r['einheit_id'] ?? 0) > 0) $einheit_id = (int)$r['einheit_id'];
        elseif ($r && (int)($r['user_einheit_id'] ?? 0) > 0) $einheit_id = (int)$r['user_einheit_id'];
    }
}

$config = print_get_printer_config($db, $einheit_id);
$use_email = !empty($config['printer_email_recipient']);
$use_cloud = !empty($config['cloud_url']);

// 1. Anwesenheitsliste
if ($print_al > 0) {
    $_GET['id'] = $print_al;
    $GLOBALS['_al_pdf_content'] = null;
    try {
        ob_start();
        require __DIR__ . '/anwesenheitsliste-pdf.php';
        ob_end_clean();
        $pdf = $GLOBALS['_al_pdf_content'] ?? null;
        if ($pdf && strlen($pdf) > 100 && substr($pdf, 0, 5) === '%PDF-') {
            $attachments[] = [$pdf, 'Anwesenheitsliste.pdf'];
            if ($first_pdf === null) $first_pdf = $pdf;
        }
    } catch (Exception $e) {
        ob_end_clean();
    }
}

// 2. Mängelbericht(e) – alle in einem PDF (maengelbericht-pdf-alle, zuverlässig)
if ($print_mb_ids !== '') {
    $mb_id_count = count(array_filter(array_map('intval', explode(',', $print_mb_ids)), function($x) { return $x > 0; }));
    $log_file = __DIR__ . '/../logs/debug-maengelbericht.log';
    if (is_dir(__DIR__ . '/../logs') || @mkdir(__DIR__ . '/../logs', 0755, true)) {
        @file_put_contents($log_file, date('Y-m-d H:i:s') . " KOMBI: print_maengelbericht=" . $print_mb_ids . ", count=" . $mb_id_count . "\n", FILE_APPEND | LOCK_EX);
    }
    $_GET['ids'] = $print_mb_ids;
    $_GET['id'] = '';
    $GLOBALS['_mb_pdf_content'] = null;
    try {
        ob_start();
        require __DIR__ . '/maengelbericht-pdf-alle.php';
        ob_end_clean();
        $pdf = $GLOBALS['_mb_pdf_content'] ?? null;
        if ($pdf && strlen($pdf) > 100 && substr($pdf, 0, 5) === '%PDF-') {
            $attachments[] = [$pdf, 'Maengelberichte.pdf'];
            if ($first_pdf === null) $first_pdf = $pdf;
        }
    } catch (Exception $e) {
        ob_end_clean();
    }
}

// 3. Gerätewartmitteilung
if ($print_gwm > 0) {
    $_GET['id'] = $print_gwm;
    $_GET['ids'] = '';
    $GLOBALS['_gwm_pdf_content'] = null;
    try {
        ob_start();
        require __DIR__ . '/geraetewartmitteilung-pdf.php';
        ob_end_clean();
        $pdf = $GLOBALS['_gwm_pdf_content'] ?? null;
        if ($pdf && strlen($pdf) > 100 && substr($pdf, 0, 5) === '%PDF-') {
            $attachments[] = [$pdf, 'Geraetewartmitteilung.pdf'];
            if ($first_pdf === null) $first_pdf = $pdf;
        }
    } catch (Exception $e) {
        ob_end_clean();
    }
}

if (empty($attachments)) {
    echo json_encode(['success' => false, 'message' => 'Keine PDFs konnten erzeugt werden.']);
    exit;
}

// Kein Drucker konfiguriert: erstes PDF zum Öffnen zurückgeben
if (!$use_email && !$use_cloud) {
    echo json_encode(['success' => true, 'open_pdf' => true, 'pdf_base64' => base64_encode($first_pdf)]);
    exit;
}

// E-Mail-Druck: alle PDFs in einer E-Mail
if ($use_email) {
    $result = print_send_pdfs_via_email($attachments, $config);
    echo json_encode($result);
    exit;
}

// Cloud-Drucker: jedes PDF einzeln senden
$last_result = ['success' => false, 'message' => 'Kein Drucker konfiguriert.'];
foreach ($attachments as $att) {
    $last_result = print_send_pdf($att[0], $config);
    if (!$last_result['success']) break;
}
echo json_encode($last_result);
