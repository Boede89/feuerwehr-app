<?php
/**
 * Force Load: Lade functions.php explizit und teste
 */

echo "<!DOCTYPE html>";
echo "<html><head><title>Force Load Functions</title></head><body>";
echo "<h1>🔧 Force Load Functions</h1>";
echo "<p>Zeitstempel: " . date('d.m.Y H:i:s') . "</p>";

try {
    echo "<h2>1. Prüfe includes/functions.php</h2>";
    
    if (file_exists('includes/functions.php')) {
        echo "✅ includes/functions.php existiert<br>";
        echo "Dateigröße: " . filesize('includes/functions.php') . " Bytes<br>";
        
        // Zeige die ersten Zeilen
        $content = file_get_contents('includes/functions.php');
        $lines = explode("\n", $content);
        echo "Anzahl Zeilen: " . count($lines) . "<br>";
        
        echo "<h3>Erste 10 Zeilen:</h3>";
        echo "<pre>";
        for ($i = 0; $i < min(10, count($lines)); $i++) {
            echo ($i + 1) . ": " . htmlspecialchars($lines[$i]) . "\n";
        }
        echo "</pre>";
        
        echo "<h2>2. Lade functions.php explizit</h2>";
        
        // Lade database.php zuerst
        if (file_exists('config/database.php')) {
            require_once 'config/database.php';
            echo "✅ config/database.php geladen<br>";
        } else {
            echo "❌ config/database.php existiert nicht<br>";
        }
        
        // Lade functions.php
        require_once 'includes/functions.php';
        echo "✅ includes/functions.php geladen<br>";
        
        echo "<h2>3. Prüfe Funktionen nach dem Laden</h2>";
        
        if (function_exists('check_calendar_conflicts')) {
            echo "✅ check_calendar_conflicts Funktion ist verfügbar<br>";
            
            // Teste die Funktion
            $test_vehicle = 'MTF';
            $test_start = date('Y-m-d H:i:s', strtotime('+1 day'));
            $test_end = date('Y-m-d H:i:s', strtotime('+1 day +2 hours'));
            
            echo "Teste Funktion...<br>";
            $start_time = microtime(true);
            $conflicts = check_calendar_conflicts($test_vehicle, $test_start, $test_end);
            $end_time = microtime(true);
            $execution_time = ($end_time - $start_time) * 1000;
            
            echo "Ausführungszeit: " . round($execution_time, 2) . " ms<br>";
            
            if (is_array($conflicts)) {
                echo "✅ Funktion erfolgreich ausgeführt<br>";
                echo "Gefundene Konflikte: " . count($conflicts) . "<br>";
            } else {
                echo "❌ Funktion hat einen Fehler zurückgegeben<br>";
            }
        } else {
            echo "❌ check_calendar_conflicts Funktion ist NICHT verfügbar<br>";
        }
        
        if (function_exists('create_google_calendar_event')) {
            echo "✅ create_google_calendar_event Funktion ist verfügbar<br>";
        } else {
            echo "❌ create_google_calendar_event Funktion ist NICHT verfügbar<br>";
        }
        
        if (function_exists('validate_csrf_token')) {
            echo "✅ validate_csrf_token Funktion ist verfügbar<br>";
        } else {
            echo "❌ validate_csrf_token Funktion ist NICHT verfügbar<br>";
        }
        
        if (function_exists('has_admin_access')) {
            echo "✅ has_admin_access Funktion ist verfügbar<br>";
        } else {
            echo "❌ has_admin_access Funktion ist NICHT verfügbar<br>";
        }
        
        echo "<h2>4. Prüfe Google Calendar Klassen</h2>";
        
        if (class_exists('GoogleCalendarServiceAccount')) {
            echo "✅ GoogleCalendarServiceAccount Klasse ist verfügbar<br>";
        } else {
            echo "❌ GoogleCalendarServiceAccount Klasse ist NICHT verfügbar<br>";
        }
        
        if (class_exists('GoogleCalendar')) {
            echo "✅ GoogleCalendar Klasse ist verfügbar<br>";
        } else {
            echo "❌ GoogleCalendar Klasse ist NICHT verfügbar<br>";
        }
        
        echo "<h2>5. Teste einfache Funktion</h2>";
        
        // Erstelle eine einfache Test-Funktion
        function test_simple_function() {
            return "Funktion funktioniert!";
        }
        
        if (function_exists('test_simple_function')) {
            echo "✅ Test-Funktion erstellt und verfügbar<br>";
            echo "Test-Ergebnis: " . test_simple_function() . "<br>";
        } else {
            echo "❌ Test-Funktion konnte nicht erstellt werden<br>";
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
