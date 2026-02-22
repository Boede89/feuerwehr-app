<?php
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

// Weiterleitung zur neuen Reservierungen-Seite (Fahrzeugreservierung-Tab)
header('Location: settings-reservations.php?tab=fahrzeug');
exit;
