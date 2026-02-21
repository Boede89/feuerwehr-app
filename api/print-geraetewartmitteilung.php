<?php
/**
 * Gerätewartmitteilung drucken (PDF an CUPS senden).
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
if (!has_permission('forms')) {
    echo json_encode(['success' => false, 'message' => 'Zugriff verweigert']);
    exit;
}

$alle = !empty($_GET['alle']);
$id = (int)($_GET['id'] ?? 0);
$ids = trim($_GET['ids'] ?? '');

$_GET['_return'] = '1';
$pdf_content = null;

if ($alle || $ids !== '') {
    if ($ids !== '') $_GET['ids'] = $ids;
    require_once __DIR__ . '/geraetewartmitteilung-pdf-alle.php';
    $pdf_content = $GLOBALS['_gwm_pdf_content'] ?? null;
} else {
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Ungültige ID']);
        exit;
    }
    require_once __DIR__ . '/geraetewartmitteilung-pdf.php';
    $pdf_content = $GLOBALS['_gwm_pdf_content'] ?? null;
}

$config = print_get_printer_config($db);
$result = print_send_pdf($pdf_content, $config);
echo json_encode($result);
