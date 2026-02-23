<?php
/**
 * Einheit wechseln (Admin-Bereich) – setzt Session und leitet zurück.
 * Ermöglicht Superadmin, die Einheit zu wechseln ohne die aktuelle Seite zu verlassen.
 */
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/einheiten-setup.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}
// Nur Superadmin darf die Einheit wechseln
if (!is_superadmin()) {
    header('Location: dashboard.php');
    exit;
}

$eid = isset($_GET['einheit_id']) ? (int)$_GET['einheit_id'] : 0;
if ($eid > 0 && user_has_einheit_access($_SESSION['user_id'], $eid)) {
    $_SESSION['current_einheit_id'] = $eid;
}

$redirect = 'dashboard.php';
$referer = $_SERVER['HTTP_REFERER'] ?? '';
$host = $_SERVER['HTTP_HOST'] ?? '';
if (!empty($referer) && !empty($host) && (strpos($referer, 'http://' . $host) === 0 || strpos($referer, 'https://' . $host) === 0)) {
    $redirect = $referer;
}
header('Location: ' . $redirect);
exit;
