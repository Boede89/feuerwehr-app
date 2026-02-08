<?php
/**
 * Weiterleitung: Dienstplan liegt im Formularcenter.
 */
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

if (!isset($_SESSION['user_id']) || !has_permission('forms')) {
    header('Location: dashboard.php');
    exit;
}

$jahr = isset($_GET['jahr']) ? (int)$_GET['jahr'] : (int)date('Y');
header('Location: formularcenter.php?tab=dienstplan&jahr=' . $jahr);
exit;
