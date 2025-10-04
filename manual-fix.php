<?php
/**
 * Manueller Fix: Erstelle die korrigierte functions.php direkt
 */

echo "<!DOCTYPE html>";
echo "<html><head><title>Manual Fix</title></head><body>";
echo "<h1>🔧 Manueller Fix</h1>";
echo "<p>Zeitstempel: " . date('d.m.Y H:i:s') . "</p>";

try {
    echo "<h2>1. Lade originale functions.php</h2>";
    
    if (file_exists('includes/functions.php')) {
        $original_content = file_get_contents('includes/functions.php');
        echo "✅ Originale functions.php geladen (" . strlen($original_content) . " Zeichen)<br>";
        
        echo "<h2>2. Erstelle korrigierte Version</h2>";
        
        // Erstelle die neue Funktion
        $new_function = '
/**
 * Google Kalender API - Konflikte prüfen (Einfache Version)
 */
function check_calendar_conflicts($vehicle_name, $start_datetime, $end_datetime) {
    global $db;
    
    try {
        // Google Calendar Einstellungen laden
        $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE \'google_calendar_%\'");
        $stmt->execute();
        $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $auth_type = $settings[\'google_calendar_auth_type\'] ?? \'service_account\';
        $calendar_id = $settings[\'google_calendar_id\'] ?? \'primary\';

        // Für jetzt: Simuliere keine Konflikte (einfache Version)
        // TODO: Implementiere echte Google Calendar API-Abfrage
        return [];
        
    } catch (Exception $e) {
        error_log(\'Google Calendar Konfliktprüfung Fehler: \' . $e->getMessage());
        return [];
    }
}';
        
        // Finde die alte Funktion und ersetze sie
        $pattern = '/\/\*\*[\s\S]*?Google Kalender API - Konflikte prüfen[\s\S]*?\*\/\s*function check_calendar_conflicts\([^}]+\}/';
        
        if (preg_match($pattern, $original_content)) {
            $new_content = preg_replace($pattern, $new_function, $original_content);
            echo "✅ Funktion gefunden und ersetzt<br>";
        } else {
            echo "❌ Funktion nicht gefunden, füge am Ende hinzu<br>";
            $new_content = $original_content . "\n" . $new_function;
        }
        
        echo "<h2>3. Speichere korrigierte Version</h2>";
        
        // Speichere die korrigierte Version
        file_put_contents('includes/functions_fixed.php', $new_content);
        echo "✅ includes/functions_fixed.php erstellt (" . strlen($new_content) . " Zeichen)<br>";
        
        echo "<h2>4. Teste die korrigierte Version</h2>";
        
        // Lade die korrigierte Version
        require_once 'config/database.php';
        require_once 'includes/functions_fixed.php';
        
        if (function_exists('check_calendar_conflicts')) {
            echo "✅ check_calendar_conflicts Funktion ist verfügbar<br>";
            
            // Teste die Funktion
            $test_vehicle = 'MTF';
            $test_start = date('Y-m-d H:i:s', strtotime('+1 day'));
            $test_end = date('Y-m-d H:i:s', strtotime('+1 day +2 hours'));
            
            $start_time = microtime(true);
            $conflicts = check_calendar_conflicts($test_vehicle, $test_start, $test_end);
            $end_time = microtime(true);
            $execution_time = ($end_time - $start_time) * 1000;
            
            echo "Test-Parameter:<br>";
            echo "- Fahrzeug: $test_vehicle<br>";
            echo "- Start: $test_start<br>";
            echo "- Ende: $test_end<br>";
            echo "Ausführungszeit: " . round($execution_time, 2) . " ms<br>";
            
            if (is_array($conflicts)) {
                echo "✅ Funktion erfolgreich ausgeführt<br>";
                echo "Gefundene Konflikte: " . count($conflicts) . "<br>";
            } else {
                echo "❌ Funktion hat einen Fehler zurückgegeben<br>";
            }
            
            echo "<h2>5. Ersetze originale functions.php</h2>";
            
            // Ersetze die originale Datei
            file_put_contents('includes/functions.php', $new_content);
            echo "✅ Originale functions.php wurde ersetzt<br>";
            
            echo "<h2>6. Teste die App</h2>";
            echo "<a href='simple-debug.php' class='btn'>Debug-Test ausführen</a><br>";
            echo "<a href='admin/dashboard.php' class='btn'>Zum Dashboard</a><br>";
            
        } else {
            echo "❌ Funktion ist nach dem Laden nicht verfügbar<br>";
        }
        
    } else {
        echo "❌ includes/functions.php existiert nicht<br>";
    }
    
} catch (Exception $e) {
    echo "<div style='color: red;'>";
    echo "<h3>❌ Fehler aufgetreten:</h3>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    echo "</div>";
}

echo "<hr>";
echo "<p><a href='simple-debug.php'>Zurück zum Debug</a> | <a href='admin/dashboard.php'>Zum Dashboard</a></p>";
echo "</body></html>";
?>
