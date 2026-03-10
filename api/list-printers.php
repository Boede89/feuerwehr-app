<?php
/**
 * Drucker-API (Legacy). CUPS wurde entfernt – nur E-Mail-Druck und Cloud-Drucker.
 * Gibt leere Druckerliste zurück (keine CUPS-Drucker mehr).
 */
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=UTF-8');

if (!isset($_SESSION['user_id']) || !hasAdminPermission()) {
    echo json_encode(['success' => false, 'message' => 'Zugriff verweigert']);
    exit;
}

$einheit_id = isset($_GET['einheit_id']) ? (int)$_GET['einheit_id'] : 0;
echo json_encode([
    'success' => true,
    'printers' => [],
    'default_printer' => '',
    'configured_printer' => '',
]);
