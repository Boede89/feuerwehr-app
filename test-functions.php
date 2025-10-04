<?php
/**
 * Test: Funktionen laden und prüfen
 */

echo "<!DOCTYPE html>";
echo "<html><head><title>Funktionen Test</title></head><body>";
echo "<h1>🔍 Funktionen Test</h1>";
echo "<p>Zeitstempel: " . date('d.m.Y H:i:s') . "</p>";

try {
    echo "<h2>1. includes/functions.php laden</h2>";
    
    if (file_exists('includes/functions.php')) {
        echo "✅ includes/functions.php existiert<br>";
        
        // Lade die Datei
        require_once 'includes/functions.php';
        echo "✅ includes/functions.php erfolgreich geladen<br>";
        
        echo "<h2>2. Funktionen prüfen</h2>";
        
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
        
        echo "<h2>3. Google Calendar Klassen prüfen</h2>";
        
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
        
        echo "<h2>4. Teste check_calendar_conflicts Funktion</h2>";
        
        if (function_exists('check_calendar_conflicts')) {
            echo "Teste Funktion mit Test-Daten...<br>";
            
            $test_vehicle = 'MTF';
            $test_start = date('Y-m-d H:i:s', strtotime('+1 day'));
            $test_end = date('Y-m-d H:i:s', strtotime('+1 day +2 hours'));
            
            echo "Test-Parameter:<br>";
            echo "- Fahrzeug: $test_vehicle<br>";
            echo "- Start: $test_start<br>";
            echo "- Ende: $test_end<br>";
            
            $conflicts = check_calendar_conflicts($test_vehicle, $test_start, $test_end);
            
            if (is_array($conflicts)) {
                echo "✅ Funktion erfolgreich ausgeführt<br>";
                echo "Gefundene Konflikte: " . count($conflicts) . "<br>";
                
                if (!empty($conflicts)) {
                    echo "Konflikte:<br>";
                    foreach ($conflicts as $i => $conflict) {
                        echo "- " . ($i + 1) . ". " . $conflict['title'] . "<br>";
                    }
                }
            } else {
                echo "❌ Funktion hat einen Fehler zurückgegeben<br>";
            }
        } else {
            echo "❌ Funktion ist nicht verfügbar, kann nicht getestet werden<br>";
        }
        
    } else {
        echo "❌ includes/functions.php existiert NICHT<br>";
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
