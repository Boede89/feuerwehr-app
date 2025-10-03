<?php
session_start();
require_once 'includes/functions.php';

// Aktivität loggen vor Abmeldung
if (is_logged_in()) {
    log_activity($_SESSION['user_id'], 'logout', 'Benutzer abgemeldet');
}

// Session zerstören
session_destroy();

// Weiterleitung zur Startseite
redirect('index.php');
?>
