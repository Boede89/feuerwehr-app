<?php
/**
 * Prüfe Syntax-Fehler in includes/functions.php
 */

echo "<!DOCTYPE html>";
echo "<html><head><title>Syntax Check</title></head><body>";
echo "<h1>🔍 Syntax-Check für includes/functions.php</h1>";
echo "<p>Zeitstempel: " . date('d.m.Y H:i:s') . "</p>";

try {
    echo "<h2>1. Datei existiert?</h2>";
    if (file_exists('includes/functions.php')) {
        echo "✅ includes/functions.php existiert<br>";
        
        echo "<h2>2. Syntax prüfen</h2>";
        
        // Prüfe Syntax mit php_check_syntax (falls verfügbar)
        if (function_exists('php_check_syntax')) {
            $syntax_ok = php_check_syntax('includes/functions.php');
            if ($syntax_ok) {
                echo "✅ Syntax ist korrekt<br>";
            } else {
                echo "❌ Syntax-Fehler gefunden<br>";
            }
        } else {
            echo "ℹ️ php_check_syntax nicht verfügbar, teste durch Laden...<br>";
        }
        
        echo "<h2>3. Versuche Datei zu laden</h2>";
        
        // Versuche die Datei zu laden
        ob_start();
        $error_occurred = false;
        
        try {
            require_once 'includes/functions.php';
            echo "✅ includes/functions.php erfolgreich geladen<br>";
        } catch (ParseError $e) {
            echo "❌ Parse-Fehler: " . $e->getMessage() . "<br>";
            echo "Zeile: " . $e->getLine() . "<br>";
            $error_occurred = true;
        } catch (Error $e) {
            echo "❌ Fatal Error: " . $e->getMessage() . "<br>";
            echo "Zeile: " . $e->getLine() . "<br>";
            $error_occurred = true;
        } catch (Exception $e) {
            echo "❌ Exception: " . $e->getMessage() . "<br>";
            $error_occurred = true;
        }
        
        $output = ob_get_clean();
        if (!empty($output)) {
            echo "Output: " . htmlspecialchars($output) . "<br>";
        }
        
        if (!$error_occurred) {
            echo "<h2>4. Funktionen nach dem Laden prüfen</h2>";
            
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
echo "<p><a href='simple-debug.php'>Zurück zum Debug</a> | <a href='test-functions.php'>Funktionen testen</a></p>";
echo "</body></html>";
?>
