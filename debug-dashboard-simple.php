<?php
// Einfaches Debug-Dashboard um das Problem zu finden
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "=== DEBUG DASHBOARD ===\n";
echo "PHP Version: " . phpversion() . "\n";
echo "Session Status: " . (session_status() === PHP_SESSION_ACTIVE ? "Aktiv" : "Nicht aktiv") . "\n";

// Session starten
if (session_status() === PHP_SESSION_NONE) {
    session_start();
    echo "Session gestartet\n";
}

echo "Session ID: " . session_id() . "\n";
echo "Session Data: " . print_r($_SESSION, true) . "\n";

// Datenbankverbindung testen
echo "\n=== DATENBANK TEST ===\n";
try {
    require_once 'config/database.php';
    echo "Database config geladen\n";
    
    if (isset($db) && $db) {
        echo "Datenbankverbindung erfolgreich\n";
        
        // Test Query
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM users");
        $stmt->execute();
        $result = $stmt->fetch();
        echo "Anzahl Benutzer: " . $result['count'] . "\n";
    } else {
        echo "FEHLER: Keine Datenbankverbindung\n";
    }
} catch (Exception $e) {
    echo "FEHLER: " . $e->getMessage() . "\n";
}

// Functions testen
echo "\n=== FUNCTIONS TEST ===\n";
try {
    require_once 'includes/functions.php';
    echo "Functions geladen\n";
    
    if (function_exists('is_logged_in')) {
        echo "is_logged_in() Funktion verfügbar\n";
        $logged_in = is_logged_in();
        echo "Eingeloggt: " . ($logged_in ? "Ja" : "Nein") . "\n";
    } else {
        echo "FEHLER: is_logged_in() Funktion nicht verfügbar\n";
    }
} catch (Exception $e) {
    echo "FEHLER: " . $e->getMessage() . "\n";
}

echo "\n=== ENDE DEBUG ===\n";
?>