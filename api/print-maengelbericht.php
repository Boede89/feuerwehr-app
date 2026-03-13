<?php
/**
 * Mängelbericht drucken (PDF per E-Mail oder Cloud-Drucker).
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

$alle = !empty($_GET['alle']);
$id = (int)($_GET['id'] ?? 0);
$ids = trim($_GET['ids'] ?? '');
$einheit_id = isset($_GET['einheit_id']) ? (int)$_GET['einheit_id'] : null;

$_GET['_return'] = '1';
$pdf_content = null;

if (!$alle && $ids === '' && $id > 0 && $einheit_id <= 0) {
    $stmt = $db->prepare("SELECT m.einheit_id, u.einheit_id AS user_einheit_id FROM maengelberichte m LEFT JOIN users u ON u.id = m.user_id WHERE m.id = ?");
    $stmt->execute([$id]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($r && (int)($r['einheit_id'] ?? 0) > 0) {
        $einheit_id = (int)$r['einheit_id'];
    } elseif ($r && (int)($r['user_einheit_id'] ?? 0) > 0) {
        $einheit_id = (int)$r['user_einheit_id'];
    }
}

if ($alle || $ids !== '') {
    if ($ids !== '') $_GET['ids'] = $ids;
    require_once __DIR__ . '/maengelbericht-pdf-alle.php';
    $pdf_content = $GLOBALS['_mb_pdf_content'] ?? null;
} else {
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Ungültige ID']);
        exit;
    }
    require_once __DIR__ . '/maengelbericht-pdf.php';
    $pdf_content = $GLOBALS['_mb_pdf_content'] ?? null;
}

$config = print_get_printer_config($db, $einheit_id);
$use_direct = (($config['printer_mode'] ?? '') === 'cups' && !empty(trim($config['printer_cups_name'] ?? '')))
    || (($config['printer_mode'] ?? '') === 'email' && !empty(trim($config['printer_email_recipient'] ?? '')))
    || !empty(trim($config['cloud_url'] ?? ''));
if ($use_direct) {
    $result = print_send_pdf($pdf_content, $config);
    echo json_encode($result);
} else {
    if (empty($pdf_content) || strlen($pdf_content) < 100) {
        echo json_encode(['success' => false, 'message' => 'PDF konnte nicht erzeugt werden.']);
    } else {
        echo json_encode(['success' => true, 'open_pdf' => true, 'pdf_base64' => base64_encode($pdf_content)]);
    }
}
