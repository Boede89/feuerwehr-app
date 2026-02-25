<?php
session_start();

// Aktivität loggen vor Abmeldung (falls eingeloggt)
if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
    try {
        require_once 'config/database.php';
        require_once 'includes/functions.php';
        log_activity($_SESSION['user_id'], 'logout', 'Benutzer abgemeldet');
    } catch (Exception $e) {
        // Ignoriere Fehler beim Loggen
    }
}

// Prüfen ob Systembenutzer-Wiederherstellung gewünscht (Cookie von login.php?as_user=1)
$redirect_url = 'index.php';
if (isset($_COOKIE['system_user_restore_token']) && !empty(trim($_COOKIE['system_user_restore_token']))) {
    $token = trim($_COOKIE['system_user_restore_token']);
    $redirect_url = 'autologin.php?token=' . urlencode($token);
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    setcookie('system_user_restore_token', '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
}

// Session zerstören
session_destroy();

header("Location: " . $redirect_url);
exit();
?>
