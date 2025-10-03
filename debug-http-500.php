<?php
/**
 * Debug HTTP 500 Error - Browser Version
 * Öffnen Sie diese Datei in Ihrem Browser: http://ihre-domain/debug-http-500.php
 */

// Alle Fehler anzeigen
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

echo "<h1>🔍 Debug HTTP 500 Error</h1>";
echo "<p>Diese Seite debuggt den HTTP 500 Fehler bei der Reservierungs-Genehmigung.</p>";

try {
    // 1. PHP Konfiguration prüfen
    echo "<h2>1. PHP Konfiguration:</h2>";
    echo "<p><strong>PHP Version:</strong> " . phpversion() . "</p>";
    echo "<p><strong>Error Reporting:</strong> " . error_reporting() . "</p>";
    echo "<p><strong>Display Errors:</strong> " . (ini_get('display_errors') ? 'ON' : 'OFF') . "</p>";
    echo "<p><strong>Log Errors:</strong> " . (ini_get('log_errors') ? 'ON' : 'OFF') . "</p>";
    echo "<p><strong>Error Log:</strong> " . ini_get('error_log') . "</p>";
    
    // 2. Datenbankverbindung testen
    echo "<h2>2. Datenbankverbindung testen:</h2>";
    
    try {
        require_once 'config/database.php';
        echo "<p style='color: green;'>✅ Datenbankverbindung erfolgreich</p>";
        
        // Teste einfache Abfrage
        $stmt = $db->query("SELECT COUNT(*) as count FROM reservations");
        $result = $stmt->fetch();
        echo "<p><strong>Anzahl Reservierungen:</strong> " . $result['count'] . "</p>";
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Datenbankfehler: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
    
    // 3. Functions.php laden testen
    echo "<h2>3. Functions.php laden testen:</h2>";
    
    try {
        require_once 'includes/functions.php';
        echo "<p style='color: green;'>✅ includes/functions.php erfolgreich geladen</p>";
        
        // Teste ob Funktionen existieren
        if (function_exists('create_google_calendar_event')) {
            echo "<p style='color: green;'>✅ create_google_calendar_event Funktion existiert</p>";
        } else {
            echo "<p style='color: red;'>❌ create_google_calendar_event Funktion existiert nicht!</p>";
        }
        
        if (function_exists('send_email')) {
            echo "<p style='color: green;'>✅ send_email Funktion existiert</p>";
        } else {
            echo "<p style='color: red;'>❌ send_email Funktion existiert nicht!</p>";
        }
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Fehler beim Laden von functions.php: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
    
    // 4. Reservierung ID 16 testen
    echo "<h2>4. Reservierung ID 16 testen:</h2>";
    
    try {
        $stmt = $db->prepare("SELECT r.*, v.name as vehicle_name FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id WHERE r.id = ?");
        $stmt->execute([16]);
        $reservation = $stmt->fetch();
        
        if ($reservation) {
            echo "<p style='color: green;'>✅ Reservierung ID 16 gefunden</p>";
            echo "<p><strong>Status:</strong> " . htmlspecialchars($reservation['status']) . "</p>";
            echo "<p><strong>Fahrzeug:</strong> " . htmlspecialchars($reservation['vehicle_name']) . "</p>";
        } else {
            echo "<p style='color: red;'>❌ Reservierung ID 16 nicht gefunden!</p>";
        }
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Fehler beim Laden der Reservierung: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
    
    // 5. Simuliere Reservierungs-Genehmigung
    echo "<h2>5. Simuliere Reservierungs-Genehmigung:</h2>";
    
    try {
        // Simuliere die POST-Daten
        $_POST['action'] = 'approve';
        $_POST['reservation_id'] = '16';
        $_POST['csrf_token'] = 'test_token'; // CSRF wird übersprungen
        
        echo "<p><strong>Simulierte POST-Daten:</strong></p>";
        echo "<ul>";
        echo "<li>action: " . htmlspecialchars($_POST['action']) . "</li>";
        echo "<li>reservation_id: " . htmlspecialchars($_POST['reservation_id']) . "</li>";
        echo "<li>csrf_token: " . htmlspecialchars($_POST['csrf_token']) . "</li>";
        echo "</ul>";
        
        // Teste die Genehmigung (ohne sie tatsächlich auszuführen)
        $reservation_id = (int)$_POST['reservation_id'];
        echo "<p><strong>Reservierung ID:</strong> $reservation_id</p>";
        
        // Teste Google Calendar Event Erstellung
        if (function_exists('create_google_calendar_event')) {
            echo "<p style='color: green;'>✅ create_google_calendar_event Funktion verfügbar</p>";
            
            // Teste die Funktion (ohne sie tatsächlich auszuführen)
            echo "<p><strong>Hinweis:</strong> Google Calendar Funktion wird nicht ausgeführt, nur getestet</p>";
            
        } else {
            echo "<p style='color: red;'>❌ create_google_calendar_event Funktion nicht verfügbar!</p>";
        }
        
        // Teste E-Mail Versand
        if (function_exists('send_email')) {
            echo "<p style='color: green;'>✅ send_email Funktion verfügbar</p>";
            
            // Teste die Funktion (ohne sie tatsächlich auszuführen)
            echo "<p><strong>Hinweis:</strong> E-Mail Funktion wird nicht ausgeführt, nur getestet</p>";
            
        } else {
            echo "<p style='color: red;'>❌ send_email Funktion nicht verfügbar!</p>";
        }
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Fehler bei der Simulation: " . htmlspecialchars($e->getMessage()) . "</p>";
        echo "<p><strong>Stack Trace:</strong></p>";
        echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    }
    
    // 6. Prüfe PHP Error Log
    echo "<h2>6. PHP Error Log prüfen:</h2>";
    
    $error_log = ini_get('error_log');
    if ($error_log && file_exists($error_log)) {
        echo "<p><strong>Error Log:</strong> $error_log</p>";
        $errors = file_get_contents($error_log);
        $recent_errors = array_slice(explode("\n", $errors), -30); // Letzte 30 Zeilen
        echo "<pre>" . htmlspecialchars(implode("\n", $recent_errors)) . "</pre>";
    } else {
        echo "<p style='color: orange;'>⚠️ Kein Error Log gefunden oder nicht lesbar</p>";
    }
    
    // 7. Prüfe Apache/Nginx Error Log
    echo "<h2>7. Web Server Error Log prüfen:</h2>";
    
    $web_error_logs = [
        '/var/log/apache2/error.log',
        '/var/log/nginx/error.log',
        '/var/log/httpd/error_log',
        '/var/log/apache/error.log'
    ];
    
    foreach ($web_error_logs as $log_path) {
        if (file_exists($log_path) && is_readable($log_path)) {
            echo "<p><strong>Web Server Log:</strong> $log_path</p>";
            $errors = file_get_contents($log_path);
            $recent_errors = array_slice(explode("\n", $errors), -20); // Letzte 20 Zeilen
            echo "<pre>" . htmlspecialchars(implode("\n", $recent_errors)) . "</pre>";
            break;
        }
    }
    
    // 8. Teste admin/reservations.php direkt
    echo "<h2>8. Teste admin/reservations.php direkt:</h2>";
    
    try {
        // Simuliere Session
        session_start();
        $_SESSION['user_id'] = 1;
        $_SESSION['user_role'] = 'admin';
        
        echo "<p style='color: green;'>✅ Session simuliert</p>";
        
        // Teste ob die Datei existiert
        if (file_exists('admin/reservations.php')) {
            echo "<p style='color: green;'>✅ admin/reservations.php existiert</p>";
            
            // Teste ob die Datei lesbar ist
            if (is_readable('admin/reservations.php')) {
                echo "<p style='color: green;'>✅ admin/reservations.php ist lesbar</p>";
            } else {
                echo "<p style='color: red;'>❌ admin/reservations.php ist nicht lesbar</p>";
            }
        } else {
            echo "<p style='color: red;'>❌ admin/reservations.php existiert nicht!</p>";
        }
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Fehler beim Testen von admin/reservations.php: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
    
    // 9. Empfohlene Aktionen
    echo "<h2>9. Empfohlene Aktionen:</h2>";
    echo "<ol>";
    echo "<li>Überprüfen Sie die PHP Error Logs</li>";
    echo "<li>Überprüfen Sie die Web Server Error Logs</li>";
    echo "<li>Stellen Sie sicher, dass alle Dateien korrekt hochgeladen wurden</li>";
    echo "<li>Testen Sie die Reservierungs-Genehmigung erneut</li>";
    echo "</ol>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Kritischer Fehler: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><strong>Stack Trace:</strong></p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}

echo "<hr>";
echo "<p><em>Debug HTTP 500 Error abgeschlossen!</em></p>";
?>
