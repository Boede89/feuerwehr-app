<?php
/**
 * Prüfe Syntax von includes/functions.php
 */

echo "<!DOCTYPE html>";
echo "<html><head><title>Syntax Check</title></head><body>";
echo "<h1>🔍 Syntax Check für includes/functions.php</h1>";

try {
    echo "<h2>1. Prüfe ob Datei existiert</h2>";
    
    if (file_exists('includes/functions.php')) {
        echo "✅ includes/functions.php existiert<br>";
        
        echo "<h2>2. Prüfe Dateigröße</h2>";
        $file_size = filesize('includes/functions.php');
        echo "Dateigröße: $file_size Bytes<br>";
        
        echo "<h2>3. Prüfe Syntax</h2>";
        
        // Lade die Datei und prüfe Syntax
        $content = file_get_contents('includes/functions.php');
        
        if ($content) {
            echo "✅ Datei kann gelesen werden<br>";
            
            // Prüfe auf offensichtliche Syntax-Fehler
            if (strpos($content, '<?php') === 0) {
                echo "✅ Beginnt mit PHP-Tag<br>";
            } else {
                echo "❌ Beginnt nicht mit PHP-Tag<br>";
            }
            
            // Zähle öffnende und schließende Klammern
            $open_braces = substr_count($content, '{');
            $close_braces = substr_count($content, '}');
            
            echo "Öffnende Klammern: $open_braces<br>";
            echo "Schließende Klammern: $close_braces<br>";
            
            if ($open_braces === $close_braces) {
                echo "✅ Klammern sind ausgeglichen<br>";
            } else {
                echo "❌ Klammern sind nicht ausgeglichen!<br>";
            }
            
            // Prüfe auf offensichtliche Fehler
            if (strpos($content, 'function create_google_calendar_event') !== false) {
                echo "✅ create_google_calendar_event Funktion gefunden<br>";
            } else {
                echo "❌ create_google_calendar_event Funktion NICHT gefunden<br>";
            }
            
            if (strpos($content, 'function check_calendar_conflicts') !== false) {
                echo "✅ check_calendar_conflicts Funktion gefunden<br>";
            } else {
                echo "❌ check_calendar_conflicts Funktion NICHT gefunden<br>";
            }
            
        } else {
            echo "❌ Datei kann nicht gelesen werden<br>";
        }
        
        echo "<h2>4. Versuche Datei zu laden</h2>";
        
        try {
            require_once 'includes/functions.php';
            echo "✅ includes/functions.php erfolgreich geladen<br>";
            
            if (function_exists('create_google_calendar_event')) {
                echo "✅ create_google_calendar_event Funktion ist verfügbar<br>";
            } else {
                echo "❌ create_google_calendar_event Funktion ist NICHT verfügbar<br>";
            }
            
            if (function_exists('check_calendar_conflicts')) {
                echo "✅ check_calendar_conflicts Funktion ist verfügbar<br>";
            } else {
                echo "❌ check_calendar_conflicts Funktion ist NICHT verfügbar<br>";
            }
            
        } catch (ParseError $e) {
            echo "❌ Parse-Fehler: " . htmlspecialchars($e->getMessage()) . "<br>";
            echo "Zeile: " . $e->getLine() . "<br>";
        } catch (Exception $e) {
            echo "❌ Fehler beim Laden: " . htmlspecialchars($e->getMessage()) . "<br>";
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
echo "<p><a href='admin/dashboard.php'>Zum Dashboard</a> | <a href='admin/reservations.php'>Zu den Reservierungen</a></p>";
echo "</body></html>";
?>