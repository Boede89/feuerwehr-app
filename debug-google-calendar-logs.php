<?php
/**
 * Debug: Google Calendar Logs anzeigen
 */

echo "<!DOCTYPE html>";
echo "<html><head><title>Debug: Google Calendar Logs</title></head><body>";
echo "<h1>🔍 Debug: Google Calendar Logs</h1>";

try {
    echo "<h2>1. Prüfe error_log Datei</h2>";
    
    // Prüfe verschiedene mögliche error_log Pfade
    $log_paths = [
        '/var/log/apache2/error.log',
        '/var/log/nginx/error.log',
        '/var/log/php_errors.log',
        '/tmp/php_errors.log',
        'error.log',
        ini_get('error_log'),
        '/var/log/apache2/access.log',
        '/var/log/php.log'
    ];
    
    $log_found = false;
    foreach ($log_paths as $log_path) {
        if (file_exists($log_path)) {
            echo "✅ Log-Datei gefunden: $log_path<br>";
            $log_content = file_get_contents($log_path);
            $lines = explode("\n", $log_content);
            $recent_lines = array_slice($lines, -50); // Letzte 50 Zeilen
            
            echo "<h3>Letzte 100 Zeilen aus $log_path:</h3>";
            echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ddd; max-height: 400px; overflow-y: auto;'>";
            $recent_lines = array_slice($lines, -100); // Letzte 100 Zeilen
            foreach ($recent_lines as $line) {
                if (strpos($line, 'Google Calendar') !== false || strpos($line, 'Dashboard') !== false) {
                    echo "<strong style='color: red;'>" . htmlspecialchars($line) . "</strong>\n";
                } else {
                    echo htmlspecialchars($line) . "\n";
                }
            }
            echo "</pre>";
            $log_found = true;
            break;
        }
    }
    
    if (!$log_found) {
        echo "❌ Keine error_log Datei gefunden<br>";
        echo "Aktuelle error_log Einstellung: " . ini_get('error_log') . "<br>";
    }
    
    echo "<h2>2. Teste Google Calendar direkt</h2>";
    
    // Lade die Funktionen
    require_once 'config/database.php';
    require_once 'includes/functions.php';
    
    echo "✅ Funktionen geladen<br>";
    
    // Schreibe Test-Log
    error_log('Debug Tool: Teste Google Calendar direkt - ' . date('Y-m-d H:i:s'));
    
    // Teste verschiedene Logging-Methoden
    echo "<h3>3. Teste Logging-Methoden</h3>";
    
    // Test 1: error_log
    $test_log_1 = error_log('TEST ERROR_LOG: ' . date('Y-m-d H:i:s'));
    echo "error_log() Rückgabe: " . ($test_log_1 ? 'true' : 'false') . "<br>";
    
    // Test 2: file_put_contents
    $test_log_2 = file_put_contents('test.log', 'TEST FILE_PUT_CONTENTS: ' . date('Y-m-d H:i:s') . "\n", FILE_APPEND);
    echo "file_put_contents() Rückgabe: " . ($test_log_2 !== false ? 'true (' . $test_log_2 . ' bytes)' : 'false') . "<br>";
    
    // Test 3: syslog
    $test_log_3 = syslog(LOG_INFO, 'TEST SYSLOG: ' . date('Y-m-d H:i:s'));
    echo "syslog() Rückgabe: " . ($test_log_3 ? 'true' : 'false') . "<br>";
    
    // Prüfe error_log Einstellungen
    echo "<h4>PHP error_log Einstellungen:</h4>";
    echo "error_log: " . ini_get('error_log') . "<br>";
    echo "log_errors: " . (ini_get('log_errors') ? 'ON' : 'OFF') . "<br>";
    echo "display_errors: " . (ini_get('display_errors') ? 'ON' : 'OFF') . "<br>";
    echo "error_reporting: " . ini_get('error_reporting') . "<br>";
    
    // Prüfe ob test.log erstellt wurde
    if (file_exists('test.log')) {
        echo "✅ test.log erstellt - Inhalt: " . htmlspecialchars(file_get_contents('test.log')) . "<br>";
        unlink('test.log'); // Aufräumen
    } else {
        echo "❌ test.log nicht erstellt<br>";
    }
    
    // Teste Google Calendar Einstellungen
    $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'google_calendar_%'");
    $stmt->execute();
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    echo "<h3>Google Calendar Einstellungen:</h3>";
    echo "<ul>";
    foreach ($settings as $key => $value) {
        if ($key === 'google_calendar_service_account_json') {
            echo "<li><strong>$key:</strong> " . (strlen($value) > 0 ? "Gesetzt (" . strlen($value) . " Zeichen)" : "Nicht gesetzt") . "</li>";
        } else {
            echo "<li><strong>$key:</strong> " . htmlspecialchars($value) . "</li>";
        }
    }
    echo "</ul>";
    
    // Teste Google Calendar Klassen
    echo "<h3>Google Calendar Klassen:</h3>";
    if (class_exists('GoogleCalendarServiceAccount')) {
        echo "✅ GoogleCalendarServiceAccount Klasse verfügbar<br>";
    } else {
        echo "❌ GoogleCalendarServiceAccount Klasse NICHT verfügbar<br>";
    }
    
    if (class_exists('GoogleCalendar')) {
        echo "✅ GoogleCalendar Klasse verfügbar<br>";
    } else {
        echo "❌ GoogleCalendar Klasse NICHT verfügbar<br>";
    }
    
    // Teste create_google_calendar_event Funktion
    echo "<h3>Teste create_google_calendar_event Funktion:</h3>";
    if (function_exists('create_google_calendar_event')) {
        echo "✅ create_google_calendar_event Funktion verfügbar<br>";
        
        // Teste mit Test-Daten
        echo "🔍 Teste mit Test-Daten...<br>";
        $test_result = create_google_calendar_event(
            'Test Fahrzeug',
            'Test Grund',
            '2025-10-05 10:00:00',
            '2025-10-05 11:00:00',
            999,
            'Test Ort'
        );
        
        if ($test_result) {
            echo "✅ Test erfolgreich - Event ID: " . htmlspecialchars($test_result) . "<br>";
        } else {
            echo "❌ Test fehlgeschlagen - Funktion gab false zurück<br>";
        }
    } else {
        echo "❌ create_google_calendar_event Funktion NICHT verfügbar<br>";
    }
    
} catch (Exception $e) {
    echo "<div style='color: red;'>";
    echo "<h3>❌ Fehler aufgetreten:</h3>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    echo "</div>";
}

echo "<hr>";
echo "<p><a href='admin/dashboard.php'>Zurück zum Dashboard</a></p>";
echo "</body></html>";
?>
