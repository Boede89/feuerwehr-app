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

// Session zerstören
session_destroy();

// Weiterleitung zur Startseite
header("Location: index.php");
exit();
?>
