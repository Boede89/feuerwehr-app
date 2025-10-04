<?php
/**
 * Test: Korrigierte functions.php
 */

echo "<!DOCTYPE html>";
echo "<html><head><title>Fixed Functions Test</title></head><body>";
echo "<h1>🔧 Test: Korrigierte Functions</h1>";
echo "<p>Zeitstempel: " . date('d.m.Y H:i:s') . "</p>";

try {
    echo "<h2>1. Lade korrigierte functions.php</h2>";
    
    if (file_exists('includes/functions_fixed.php')) {
        require_once 'includes/functions_fixed.php';
        echo "✅ includes/functions_fixed.php erfolgreich geladen<br>";
        
        echo "<h2>2. Prüfe Funktionen</h2>";
        
        if (function_exists('check_calendar_conflicts')) {
            echo "✅ check_calendar_conflicts Funktion ist verfügbar<br>";
        } else {
            echo "❌ check_calendar_conflicts Funktion ist NICHT verfügbar<br>";
        }
        
        if (function_exists('create_google_calendar_event')) {
            echo "✅ create_google_calendar_event Funktion ist verfügbar<br>";
        } else {
            echo "❌ create_google_calendar_event Funktion ist NICHT verfügbar<br>";
        }
        
        echo "<h2>3. Teste check_calendar_conflicts Funktion</h2>";
        
        $test_vehicle = 'MTF';
        $test_start = date('Y-m-d H:i:s', strtotime('+1 day'));
        $test_end = date('Y-m-d H:i:s', strtotime('+1 day +2 hours'));
        
        echo "Test-Parameter:<br>";
        echo "- Fahrzeug: $test_vehicle<br>";
        echo "- Start: $test_start<br>";
        echo "- Ende: $test_end<br>";
        
        $start_time = microtime(true);
        $conflicts = check_calendar_conflicts($test_vehicle, $test_start, $test_end);
        $end_time = microtime(true);
        $execution_time = ($end_time - $start_time) * 1000; // in Millisekunden
        
        echo "Ausführungszeit: " . round($execution_time, 2) . " ms<br>";
        
        if (is_array($conflicts)) {
            echo "✅ Funktion erfolgreich ausgeführt<br>";
            echo "Gefundene Konflikte: " . count($conflicts) . "<br>";
            
            if (!empty($conflicts)) {
                echo "Konflikte:<br>";
                foreach ($conflicts as $i => $conflict) {
                    echo "- " . ($i + 1) . ". " . $conflict['title'] . "<br>";
                }
            } else {
                echo "ℹ️ Keine Konflikte gefunden (erwartet bei einfacher Version)<br>";
            }
        } else {
            echo "❌ Funktion hat einen Fehler zurückgegeben<br>";
        }
        
        echo "<h2>4. Ersetze originale functions.php</h2>";
        
        if (file_exists('includes/functions_fixed.php')) {
            $fixed_content = file_get_contents('includes/functions_fixed.php');
            file_put_contents('includes/functions.php', $fixed_content);
            echo "✅ Originale functions.php wurde ersetzt<br>";
            echo "<a href='simple-debug.php'>Teste die App erneut</a><br>";
        } else {
            echo "❌ includes/functions_fixed.php existiert nicht<br>";
        }
        
    } else {
        echo "❌ includes/functions_fixed.php existiert nicht<br>";
        echo "<a href='fix-functions.php'>Erstelle die korrigierte Version</a><br>";
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
