<?php
/**
 * Zugriffsschutz für Debug-Skripte: Nur Superadmin.
 * Am Anfang jedes Debug-Skripts einbinden:
 *   require_once __DIR__ . '/includes/debug-auth.php';  (aus Projekt-Root)
 *   require_once __DIR__ . '/../includes/debug-auth.php'; (aus admin/)
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';
if (file_exists(__DIR__ . '/../includes/einheiten-setup.php')) {
    require_once __DIR__ . '/../includes/einheiten-setup.php';
}
if (!isset($_SESSION['user_id']) || !is_superadmin($_SESSION['user_id'])) {
    $login_url = (strpos($_SERVER['SCRIPT_NAME'] ?? '', '/admin/') !== false) ? '../login.php' : 'login.php';
    header('Location: ' . $login_url . '?error=superadmin_only');
    exit;
}
