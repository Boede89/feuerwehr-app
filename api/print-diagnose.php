<?php
/**
 * CUPS-Diagnose für Drucker einer Einheit.
 * Zeigt Warteschlangen-Status und Drucker-Info.
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

$einheit_id = isset($_GET['einheit_id']) ? (int)$_GET['einheit_id'] : 0;
if ($einheit_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Bitte eine Einheit auswählen.']);
    exit;
}

$config = print_get_printer_config($db, $einheit_id);
$diag = print_diagnose($config);
echo json_encode([
    'success' => true,
    'printer' => $config['printer'],
    'cups_server' => $config['cups_server'] ?: '(Standard)',
    'diagnose' => $diag,
]);
