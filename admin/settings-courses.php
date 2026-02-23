<?php
/**
 * Lehrgangsverwaltung – Weiterleitung zu settings-members.php?tab=lehrgaenge
 * Die Lehrgangsverwaltung wurde in die Mitgliederverwaltung integriert.
 */
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('Location: ../login.php');
    exit;
}
if (!hasAdminPermission()) {
    header('Location: ../login.php?error=access_denied');
    exit;
}

$einheit_id = isset($_GET['einheit_id']) ? (int)$_GET['einheit_id'] : 0;
$redirect = 'settings-members.php?tab=lehrgaenge';
if ($einheit_id > 0) $redirect .= '&einheit_id=' . (int)$einheit_id;
header('Location: ' . $redirect);
exit;
