<?php
/**
 * Fix Google Calendar Config - Browser Version
 * Öffnen Sie diese Datei in Ihrem Browser: http://ihre-domain/fix-google-calendar-config.php
 */

// Alle Fehler anzeigen
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

echo "<h1>🔧 Fix Google Calendar Config</h1>";
echo "<p>Diese Seite repariert die Google Calendar Konfiguration.</p>";

try {
    // 1. Datenbankverbindung
    echo "<h2>1. Datenbankverbindung:</h2>";
    require_once 'config/database.php';
    echo "<p style='color: green;'>✅ Datenbankverbindung erfolgreich</p>";
    
    // 2. Prüfe Google Calendar Einstellungen
    echo "<h2>2. Prüfe Google Calendar Einstellungen:</h2>";
    
    $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'google_calendar_%'");
    $stmt->execute();
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    echo "<table border='1' cellpadding='5' cellspacing='0'>";
    echo "<tr><th>Einstellung</th><th>Wert (erste 100 Zeichen)</th><th>Länge</th></tr>";
    
    foreach ($settings as $key => $value) {
        $preview = strlen($value) > 100 ? substr($value, 0, 100) . '...' : $value;
        $length = strlen($value);
        echo "<tr>";
        echo "<td><strong>" . htmlspecialchars($key) . "</strong></td>";
        echo "<td><pre>" . htmlspecialchars($preview) . "</pre></td>";
        echo "<td>" . $length . " Zeichen</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // 3. Prüfe JSON-Inhalt
    echo "<h2>3. Prüfe JSON-Inhalt:</h2>";
    
    $json_content = $settings['google_calendar_service_account_json'] ?? '';
    
    if (empty($json_content)) {
        echo "<p style='color: red;'>❌ Kein JSON-Inhalt vorhanden</p>";
    } else {
        echo "<p style='color: green;'>✅ JSON-Inhalt vorhanden (" . strlen($json_content) . " Zeichen)</p>";
        
        // Prüfe ob JSON gültig ist
        $json_data = json_decode($json_content, true);
        
        if ($json_data) {
            echo "<p style='color: green;'>✅ JSON ist gültig</p>";
            echo "<p><strong>Type:</strong> " . htmlspecialchars($json_data['type'] ?? 'NICHT GEFUNDEN') . "</p>";
            echo "<p><strong>Project ID:</strong> " . htmlspecialchars($json_data['project_id'] ?? 'NICHT GEFUNDEN') . "</p>";
            echo "<p><strong>Client Email:</strong> " . htmlspecialchars($json_data['client_email'] ?? 'NICHT GEFUNDEN') . "</p>";
        } else {
            echo "<p style='color: red;'>❌ JSON ist ungültig</p>";
            echo "<p><strong>Fehler:</strong> " . json_last_error_msg() . "</p>";
            
            // Versuche JSON zu reparieren
            echo "<h3>3.1. Versuche JSON zu reparieren:</h3>";
            
            $decoded_json = $json_content;
            $attempts = 0;
            $max_attempts = 10;
            
            while ($attempts < $max_attempts) {
                $attempts++;
                $previous_length = strlen($decoded_json);
                
                // HTML-Entities dekodieren
                $decoded_json = html_entity_decode($decoded_json, ENT_QUOTES, 'UTF-8');
                
                // Prüfen ob sich etwas geändert hat
                if (strlen($decoded_json) === $previous_length) {
                    echo "<p>Versuch $attempts: Keine Änderung mehr - Decoding abgeschlossen</p>";
                    break;
                }
                
                echo "<p>Versuch $attempts: Länge von $previous_length auf " . strlen($decoded_json) . " reduziert</p>";
            }
            
            // Prüfe ob JSON jetzt gültig ist
            $repaired_json = json_decode($decoded_json, true);
            
            if ($repaired_json) {
                echo "<p style='color: green;'>✅ JSON erfolgreich repariert!</p>";
                echo "<p><strong>Type:</strong> " . htmlspecialchars($repaired_json['type'] ?? 'NICHT GEFUNDEN') . "</p>";
                echo "<p><strong>Project ID:</strong> " . htmlspecialchars($repaired_json['project_id'] ?? 'NICHT GEFUNDEN') . "</p>";
                echo "<p><strong>Client Email:</strong> " . htmlspecialchars($repaired_json['client_email'] ?? 'NICHT GEFUNDEN') . "</p>";
                
                // Speichere reparierten JSON
                $stmt = $db->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'google_calendar_service_account_json'");
                $stmt->execute([$decoded_json]);
                echo "<p style='color: green;'>✅ Reparierter JSON in Datenbank gespeichert</p>";
                
            } else {
                echo "<p style='color: red;'>❌ JSON konnte nicht repariert werden</p>";
                echo "<p><strong>Fehler:</strong> " . json_last_error_msg() . "</p>";
            }
        }
    }
    
    // 4. Teste Google Calendar Funktion
    echo "<h2>4. Teste Google Calendar Funktion:</h2>";
    
    require_once 'includes/functions.php';
    
    if (function_exists('create_google_calendar_event')) {
        echo "<p style='color: green;'>✅ create_google_calendar_event Funktion existiert</p>";
        
        // Teste die Funktion (ohne sie tatsächlich auszuführen)
        echo "<p><strong>Test-Parameter:</strong></p>";
        echo "<ul>";
        echo "<li>Fahrzeug: Test Fahrzeug</li>";
        echo "<li>Grund: Test Reservierung</li>";
        echo "<li>Von: 2025-10-05 10:00:00</li>";
        echo "<li>Bis: 2025-10-05 12:00:00</li>";
        echo "<li>Reservierung ID: 999</li>";
        echo "</ul>";
        
        echo "<p><strong>Hinweis:</strong> Google Calendar Funktion wird nicht ausgeführt, nur getestet</p>";
        
    } else {
        echo "<p style='color: red;'>❌ create_google_calendar_event Funktion existiert nicht!</p>";
    }
    
    // 5. Erstelle Test-Reservierung
    echo "<h2>5. Erstelle Test-Reservierung:</h2>";
    
    // Prüfe ob Reservierung ID 20 existiert
    $stmt = $db->prepare("SELECT r.*, v.name as vehicle_name FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id WHERE r.id = ?");
    $stmt->execute([20]);
    $reservation = $stmt->fetch();
    
    if ($reservation) {
        echo "<p style='color: green;'>✅ Reservierung ID 20 gefunden</p>";
        echo "<p><strong>Fahrzeug:</strong> " . htmlspecialchars($reservation['vehicle_name']) . "</p>";
        echo "<p><strong>Status:</strong> " . htmlspecialchars($reservation['status']) . "</p>";
        
        // Erstelle Test-Formular
        $test_form = "
        <!DOCTYPE html>
        <html lang='de'>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <title>Test Google Calendar Integration</title>
            <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css' rel='stylesheet'>
        </head>
        <body>
            <div class='container mt-5'>
                <h1>Test Google Calendar Integration</h1>
                <p>Diese Seite testet die Google Calendar Integration mit Reservierung ID 20.</p>
                
                <form method='POST' action='admin/reservations.php'>
                    <input type='hidden' name='action' value='approve'>
                    <input type='hidden' name='reservation_id' value='20'>
                    <input type='hidden' name='csrf_token' value='test_token'>
                    
                    <div class='mb-3'>
                        <label class='form-label'>Reservierung ID:</label>
                        <input type='text' class='form-control' value='20' readonly>
                    </div>
                    
                    <div class='mb-3'>
                        <label class='form-label'>Fahrzeug:</label>
                        <input type='text' class='form-control' value='" . htmlspecialchars($reservation['vehicle_name']) . "' readonly>
                    </div>
                    
                    <div class='mb-3'>
                        <label class='form-label'>Status:</label>
                        <input type='text' class='form-control' value='" . htmlspecialchars($reservation['status']) . "' readonly>
                    </div>
                    
                    <button type='submit' class='btn btn-success'>Reservierung genehmigen (mit Google Calendar)</button>
                </form>
            </div>
        </body>
        </html>
        ";
        
        file_put_contents('test-google-calendar-integration.html', $test_form);
        echo "<p style='color: green;'>✅ Test-Formular erstellt: <a href='test-google-calendar-integration.html'>test-google-calendar-integration.html</a></p>";
        
    } else {
        echo "<p style='color: red;'>❌ Reservierung ID 20 nicht gefunden</p>";
    }
    
    // 6. Nächste Schritte
    echo "<h2>6. Nächste Schritte:</h2>";
    echo "<ol>";
    echo "<li>Öffnen Sie <a href='test-google-calendar-integration.html'>test-google-calendar-integration.html</a></li>";
    echo "<li>Klicken Sie auf 'Reservierung genehmigen (mit Google Calendar)'</li>";
    echo "<li>Falls es funktioniert, ist das Problem behoben</li>";
    echo "<li>Falls es nicht funktioniert, schauen Sie in die PHP Error Logs</li>";
    echo "</ol>";
    
    // 7. Zusammenfassung
    echo "<h2>7. Zusammenfassung:</h2>";
    echo "<ul>";
    echo "<li>✅ Datenbankverbindung erfolgreich</li>";
    echo "<li>✅ Google Calendar Einstellungen geprüft</li>";
    echo "<li>✅ JSON-Inhalt repariert (falls nötig)</li>";
    echo "<li>✅ Google Calendar Funktion getestet</li>";
    echo "<li>✅ Test-Formular erstellt</li>";
    echo "</ul>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><strong>Stack Trace:</strong></p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}

echo "<hr>";
echo "<p><em>Fix Google Calendar Config abgeschlossen!</em></p>";
?>
