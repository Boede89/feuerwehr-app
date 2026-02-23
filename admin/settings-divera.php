<?php
/**
 * Divera 24/7 Einstellungen wurden in die Globalen Einstellungen verschoben.
 * Redirect für bestehende Links.
 */
session_start();
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('Location: ../login.php');
    exit;
}
require_once __DIR__ . '/../includes/functions.php';
if (!hasAdminPermission()) {
    header('Location: ../login.php?error=access_denied');
    exit;
}
header('Location: settings-global.php');
exit;
