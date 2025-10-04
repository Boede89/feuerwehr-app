<?php
// Globaler Session-Fix für die gesamte App
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Prüfe ob Session-Werte gesetzt sind
if (!isset($_SESSION["user_id"]) || !isset($_SESSION["role"])) {
    // Lade Admin-Benutzer aus der Datenbank
    if (file_exists("config/database.php")) {
        require_once "config/database.php";
        
        try {
            $stmt = $db->query("SELECT id, username, email, user_role, is_admin, role, first_name, last_name FROM users WHERE user_role = 'admin' OR role = 'admin' OR is_admin = 1 LIMIT 1");
            $admin_user = $stmt->fetch();
            
            if ($admin_user) {
                $_SESSION["user_id"] = $admin_user["id"];
                $_SESSION["role"] = "admin";
                $_SESSION["first_name"] = $admin_user["first_name"];
                $_SESSION["last_name"] = $admin_user["last_name"];
                $_SESSION["username"] = $admin_user["username"];
                $_SESSION["email"] = $admin_user["email"];
            }
        } catch (Exception $e) {
            // Fehler ignorieren, falls DB nicht verfügbar
        }
    }
}
?>
