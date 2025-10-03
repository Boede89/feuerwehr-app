<?php
/**
 * Debug Simple - Browser Version
 * Öffnen Sie diese Datei in Ihrem Browser: http://ihre-domain/debug-simple.php
 */

// Alle Fehler anzeigen
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

echo "<h1>🔍 Debug Simple</h1>";
echo "<p>Diese Seite debuggt das HTTP 500 Problem systematisch.</p>";

try {
    // 1. PHP Info
    echo "<h2>1. PHP Info:</h2>";
    echo "<p><strong>PHP Version:</strong> " . phpversion() . "</p>";
    echo "<p><strong>Error Reporting:</strong> " . error_reporting() . "</p>";
    echo "<p><strong>Display Errors:</strong> " . (ini_get('display_errors') ? 'ON' : 'OFF') . "</p>";
    
    // 2. Datei-Existenz prüfen
    echo "<h2>2. Datei-Existenz prüfen:</h2>";
    
    $files = [
        'config/database.php',
        'includes/functions.php',
        'admin/reservations.php'
    ];
    
    foreach ($files as $file) {
        if (file_exists($file)) {
            echo "<p style='color: green;'>✅ $file existiert</p>";
            
            // Prüfe Berechtigungen
            if (is_readable($file)) {
                echo "<p style='color: green;'>   ✅ Lesbar</p>";
            } else {
                echo "<p style='color: red;'>   ❌ Nicht lesbar</p>";
            }
        } else {
            echo "<p style='color: red;'>❌ $file existiert nicht</p>";
        }
    }
    
    // 3. Datenbankverbindung testen
    echo "<h2>3. Datenbankverbindung testen:</h2>";
    
    try {
        require_once 'config/database.php';
        echo "<p style='color: green;'>✅ config/database.php geladen</p>";
        
        // Teste einfache Abfrage
        $stmt = $db->query("SELECT 1 as test");
        $result = $stmt->fetch();
        if ($result && $result['test'] == 1) {
            echo "<p style='color: green;'>✅ Datenbankverbindung funktioniert</p>";
        } else {
            echo "<p style='color: red;'>❌ Datenbankabfrage fehlgeschlagen</p>";
        }
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Datenbankfehler: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
    
    // 4. Functions laden testen
    echo "<h2>4. Functions laden testen:</h2>";
    
    try {
        require_once 'includes/functions.php';
        echo "<p style='color: green;'>✅ includes/functions.php geladen</p>";
        
        // Teste wichtige Funktionen
        $functions = [
            'generate_csrf_token',
            'validate_csrf_token',
            'create_google_calendar_event',
            'send_email',
            'can_approve_reservations'
        ];
        
        foreach ($functions as $func) {
            if (function_exists($func)) {
                echo "<p style='color: green;'>   ✅ $func existiert</p>";
            } else {
                echo "<p style='color: red;'>   ❌ $func existiert nicht</p>";
            }
        }
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Functions-Fehler: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
    
    // 5. Session testen
    echo "<h2>5. Session testen:</h2>";
    
    try {
        // Output Buffering starten
        ob_start();
        
        session_start();
        echo "<p style='color: green;'>✅ Session gestartet</p>";
        echo "<p><strong>Session ID:</strong> " . session_id() . "</p>";
        
        // Simuliere Admin-Session
        $_SESSION['user_id'] = 1;
        $_SESSION['user_role'] = 'admin';
        echo "<p style='color: green;'>✅ Admin-Session simuliert</p>";
        
        // Teste can_approve_reservations
        if (function_exists('can_approve_reservations')) {
            $can_approve = can_approve_reservations();
            if ($can_approve) {
                echo "<p style='color: green;'>✅ can_approve_reservations() gibt true zurück</p>";
            } else {
                echo "<p style='color: red;'>❌ can_approve_reservations() gibt false zurück</p>";
            }
        }
        
        ob_end_clean(); // Buffer leeren ohne auszugeben
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Session-Fehler: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
    
    // 6. Reservierung testen
    echo "<h2>6. Reservierung testen:</h2>";
    
    try {
        $stmt = $db->prepare("SELECT r.*, v.name as vehicle_name FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id WHERE r.id = ?");
        $stmt->execute([16]);
        $reservation = $stmt->fetch();
        
        if ($reservation) {
            echo "<p style='color: green;'>✅ Reservierung ID 16 gefunden</p>";
            echo "<p><strong>Status:</strong> " . htmlspecialchars($reservation['status']) . "</p>";
            echo "<p><strong>Fahrzeug:</strong> " . htmlspecialchars($reservation['vehicle_name']) . "</p>";
        } else {
            echo "<p style='color: red;'>❌ Reservierung ID 16 nicht gefunden</p>";
        }
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Reservierungs-Fehler: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
    
    // 7. admin/reservations.php direkt testen
    echo "<h2>7. admin/reservations.php direkt testen:</h2>";
    
    try {
        // Simuliere GET-Parameter
        $_GET['id'] = '16';
        
        // Simuliere Session
        session_start();
        $_SESSION['user_id'] = 1;
        $_SESSION['user_role'] = 'admin';
        
        // Teste ob die Datei geladen werden kann
        $content = file_get_contents('admin/reservations.php');
        echo "<p style='color: green;'>✅ admin/reservations.php kann gelesen werden</p>";
        echo "<p><strong>Dateigröße:</strong> " . strlen($content) . " Zeichen</p>";
        
        // Prüfe ob Output Buffering vorhanden ist
        if (strpos($content, 'ob_start()') !== false) {
            echo "<p style='color: green;'>✅ Output Buffering ist vorhanden</p>";
        } else {
            echo "<p style='color: red;'>❌ Output Buffering fehlt</p>";
        }
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ admin/reservations.php Fehler: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
    
    // 8. PHP Error Log prüfen
    echo "<h2>8. PHP Error Log prüfen:</h2>";
    
    $error_log = ini_get('error_log');
    if ($error_log && file_exists($error_log)) {
        echo "<p><strong>Error Log:</strong> $error_log</p>";
        $errors = file_get_contents($error_log);
        $recent_errors = array_slice(explode("\n", $errors), -10); // Letzte 10 Zeilen
        echo "<pre>" . htmlspecialchars(implode("\n", $recent_errors)) . "</pre>";
    } else {
        echo "<p style='color: orange;'>⚠️ Kein Error Log gefunden</p>";
    }
    
    // 9. Nächste Schritte
    echo "<h2>9. Nächste Schritte:</h2>";
    echo "<ol>";
    echo "<li>Überprüfen Sie die PHP Error Logs</li>";
    echo "<li>Testen Sie die Reservierungs-Genehmigung erneut</li>";
    echo "<li>Falls es immer noch nicht funktioniert, liegt das Problem an der Web Server Konfiguration</li>";
    echo "</ol>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Kritischer Fehler: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><strong>Stack Trace:</strong></p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}

echo "<hr>";
echo "<p><em>Debug Simple abgeschlossen!</em></p>";
?>
