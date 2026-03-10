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
// Superadmin und Admins dürfen die Einheit wechseln
if (!is_superadmin() && !(function_exists('hasAdminPermission') && hasAdminPermission())) {
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
    // einheit_id aus URL entfernen, damit die neu gesetzte Session-Einheit greift
    $parsed = parse_url($redirect);
    if (!empty($parsed['query'])) {
        parse_str($parsed['query'], $params);
        unset($params['einheit_id']);
        $parsed['query'] = http_build_query($params);
        $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';
        $redirect = ($parsed['scheme'] ?? 'http') . '://' . ($parsed['host'] ?? '') . $port . ($parsed['path'] ?? '') . ($parsed['query'] ? '?' . $parsed['query'] : '');
    }
}
header('Location: ' . $redirect);
exit;
