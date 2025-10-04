<?php
/**
 * Debug für Pfad-Probleme
 */

echo "<h1>Debug: Pfad-Probleme</h1>";
echo "<p>Zeitstempel: " . date('d.m.Y H:i:s') . "</p>";

// 1. Prüfe aktuelle Verzeichnisstruktur
echo "<h2>1. Verzeichnisstruktur prüfen</h2>";
echo "<p><strong>Aktuelles Verzeichnis:</strong> " . getcwd() . "</p>";

$files_to_check = [
    'includes/functions.php',
    'admin/includes/functions.php',
    '../includes/functions.php',
    'config/database.php',
    'admin/config/database.php',
    '../config/database.php'
];

foreach ($files_to_check as $file) {
    if (file_exists($file)) {
        echo "<p style='color: green;'>✅ $file existiert</p>";
    } else {
        echo "<p style='color: red;'>❌ $file existiert NICHT</p>";
    }
}

// 2. Prüfe ob includes/functions.php korrekt geladen wird
echo "<h2>2. includes/functions.php laden testen</h2>";

try {
    require_once 'includes/functions.php';
    echo "<p style='color: green;'>✅ includes/functions.php erfolgreich geladen</p>";
    
    if (function_exists('create_google_calendar_event')) {
        echo "<p style='color: green;'>✅ create_google_calendar_event Funktion ist verfügbar</p>";
    } else {
        echo "<p style='color: red;'>❌ create_google_calendar_event Funktion ist NICHT verfügbar</p>";
    }
    
    if (class_exists('GoogleCalendarServiceAccount')) {
        echo "<p style='color: green;'>✅ GoogleCalendarServiceAccount Klasse ist verfügbar</p>";
    } else {
        echo "<p style='color: red;'>❌ GoogleCalendarServiceAccount Klasse ist NICHT verfügbar</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim Laden von includes/functions.php: " . htmlspecialchars($e->getMessage()) . "</p>";
}

// 3. Simuliere admin/reservations.php Pfade
echo "<h2>3. admin/reservations.php Pfade simulieren</h2>";

// Wechsle in admin Verzeichnis
$original_dir = getcwd();
chdir('admin');

echo "<p><strong>Nach chdir('admin'):</strong> " . getcwd() . "</p>";

$admin_files_to_check = [
    '../includes/functions.php',
    '../config/database.php',
    'includes/functions.php',
    'config/database.php'
];

foreach ($admin_files_to_check as $file) {
    if (file_exists($file)) {
        echo "<p style='color: green;'>✅ admin/$file existiert</p>";
    } else {
        echo "<p style='color: red;'>❌ admin/$file existiert NICHT</p>";
    }
}

// Teste Laden aus admin Verzeichnis
try {
    require_once '../includes/functions.php';
    echo "<p style='color: green;'>✅ ../includes/functions.php erfolgreich geladen</p>";
    
    if (function_exists('create_google_calendar_event')) {
        echo "<p style='color: green;'>✅ create_google_calendar_event Funktion ist verfügbar</p>";
    } else {
        echo "<p style='color: red;'>❌ create_google_calendar_event Funktion ist NICHT verfügbar</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim Laden von ../includes/functions.php: " . htmlspecialchars($e->getMessage()) . "</p>";
}

// Zurück zum ursprünglichen Verzeichnis
chdir($original_dir);

// 4. Prüfe ob die Funktion in admin/reservations.php verfügbar ist
echo "<h2>4. admin/reservations.php Funktionstest</h2>";

// Simuliere admin/reservations.php
session_start();
try {
    require_once 'config/database.php';
    require_once 'includes/functions.php';
    
    echo "<p style='color: green;'>✅ admin/reservations.php Pfade erfolgreich geladen</p>";
    
    if (function_exists('create_google_calendar_event')) {
        echo "<p style='color: green;'>✅ create_google_calendar_event Funktion ist in admin/reservations.php verfügbar</p>";
    } else {
        echo "<p style='color: red;'>❌ create_google_calendar_event Funktion ist in admin/reservations.php NICHT verfügbar</p>";
    }
    
    if (class_exists('GoogleCalendarServiceAccount')) {
        echo "<p style='color: green;'>✅ GoogleCalendarServiceAccount Klasse ist in admin/reservations.php verfügbar</p>";
    } else {
        echo "<p style='color: red;'>❌ GoogleCalendarServiceAccount Klasse ist in admin/reservations.php NICHT verfügbar</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim Laden für admin/reservations.php: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<hr>";
echo "<p><strong>Debug abgeschlossen um:</strong> " . date('d.m.Y H:i:s') . "</p>";
?>
