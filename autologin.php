<?php
/**
 * Autologin für Systembenutzer.
 * Einmaliger oder zeitlich begrenzter Link zum direkten Einloggen ohne Passwort.
 */
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

$token = isset($_GET['token']) ? trim($_GET['token']) : '';

if (empty($token) || strlen($token) < 32) {
    header('Location: login.php?error=invalid_token');
    exit;
}

// Spalten sicherstellen
try {
    $db->exec("ALTER TABLE users ADD COLUMN is_system_user TINYINT(1) DEFAULT 0");
} catch (Exception $e) {}
try {
    $db->exec("ALTER TABLE users ADD COLUMN autologin_token VARCHAR(64) NULL");
} catch (Exception $e) {}
try {
    $db->exec("ALTER TABLE users ADD COLUMN autologin_expires DATETIME NULL");
} catch (Exception $e) {}

try {
    $stmt = $db->prepare("
        SELECT id, username, first_name, last_name, is_admin, user_role, is_active, is_system_user,
               can_reservations, can_atemschutz, can_members, can_ric, can_courses, can_forms,
               can_users, can_settings, can_vehicles, email_notifications, autologin_expires
        FROM users
        WHERE autologin_token = ? AND is_active = 1 AND is_system_user = 1
    ");
    $stmt->execute([$token]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        header('Location: login.php?error=invalid_token');
        exit;
    }

    // Token abgelaufen?
    if (!empty($user['autologin_expires']) && strtotime($user['autologin_expires']) < time()) {
        header('Location: login.php?error=token_expired');
        exit;
    }

    // Session setzen
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['email'] = $user['email'] ?? '';
    $_SESSION['first_name'] = $user['first_name'] ?: $user['username'];
    $_SESSION['last_name'] = $user['last_name'] ?? '';
    $_SESSION['is_admin'] = 0;
    $_SESSION['role'] = $user['user_role'] ?? 'user';
    $_SESSION['is_system_user'] = 1;
    $_SESSION['email_notifications'] = $user['email_notifications'] ?? 0;
    $_SESSION['can_reservations'] = $user['can_reservations'] ?? 0;
    $_SESSION['can_users'] = $user['can_users'] ?? 0;
    $_SESSION['can_settings'] = $user['can_settings'] ?? 0;
    $_SESSION['can_vehicles'] = $user['can_vehicles'] ?? 0;
    $_SESSION['can_atemschutz'] = $user['can_atemschutz'] ?? 0;
    $_SESSION['can_members'] = $user['can_members'] ?? 0;
    $_SESSION['can_ric'] = $user['can_ric'] ?? 0;
    $_SESSION['can_courses'] = $user['can_courses'] ?? 0;
    $_SESSION['can_forms'] = $user['can_forms'] ?? 0;

    log_activity($user['id'], 'autologin', 'Systembenutzer per Autologin angemeldet');

    header('Location: index.php');
    exit;
} catch (Exception $e) {
    error_log('Autologin Fehler: ' . $e->getMessage());
    header('Location: login.php?error=invalid_token');
    exit;
}
