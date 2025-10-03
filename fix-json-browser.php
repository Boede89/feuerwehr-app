<?php
/**
 * Fix JSON Double-Encoding Problem - Browser Version
 * Öffnen Sie diese Datei in Ihrem Browser: http://ihre-domain/fix-json-browser.php
 */

require_once 'config/database.php';

echo "<h1>🔧 JSON Double-Encoding Fix</h1>";
echo "<p>Diese Seite repariert das JSON Double-Encoding Problem.</p>";

try {
    // Aktuellen JSON-Inhalt aus der Datenbank laden
    $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'google_calendar_service_account_json'");
    $stmt->execute();
    $current_json = $stmt->fetchColumn();
    
    if (!$current_json) {
        echo "<p style='color: red;'>❌ Kein JSON-Inhalt in der Datenbank gefunden.</p>";
        exit;
    }
    
    echo "<h2>1. Aktueller JSON-Inhalt (erste 200 Zeichen):</h2>";
    echo "<pre>" . htmlspecialchars(substr($current_json, 0, 200)) . "...</pre>";
    
    echo "<p><strong>Länge:</strong> " . strlen($current_json) . " Zeichen</p>";
    
    // Versuche mehrfaches Decoding
    $decoded_json = $current_json;
    $attempts = 0;
    $max_attempts = 10;
    
    echo "<h2>2. Decoding-Versuche:</h2>";
    
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
    
    echo "<h2>3. Nach Decoding (erste 200 Zeichen):</h2>";
    echo "<pre>" . htmlspecialchars(substr($decoded_json, 0, 200)) . "...</pre>";
    
    echo "<h2>4. JSON-Validierung:</h2>";
    $json_data = json_decode($decoded_json, true);
    
    if ($json_data) {
        echo "<p style='color: green;'>✅ JSON ist jetzt gültig!</p>";
        echo "<p><strong>Type:</strong> " . htmlspecialchars($json_data['type'] ?? 'NICHT GEFUNDEN') . "</p>";
        echo "<p><strong>Project ID:</strong> " . htmlspecialchars($json_data['project_id'] ?? 'NICHT GEFUNDEN') . "</p>";
        echo "<p><strong>Client Email:</strong> " . htmlspecialchars($json_data['client_email'] ?? 'NICHT GEFUNDEN') . "</p>";
        
        // Reparierten JSON in Datenbank speichern
        echo "<h2>5. Reparierten JSON in Datenbank speichern:</h2>";
        $stmt = $db->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'google_calendar_service_account_json'");
        $result = $stmt->execute([$decoded_json]);
        
        if ($result) {
            echo "<p style='color: green;'>✅ JSON erfolgreich repariert und gespeichert!</p>";
            echo "<p><strong>Neue Länge:</strong> " . strlen($decoded_json) . " Zeichen</p>";
            
            echo "<h2>6. Verifikation:</h2>";
            $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'google_calendar_service_account_json'");
            $stmt->execute();
            $saved_json = $stmt->fetchColumn();
            
            $verification_data = json_decode($saved_json, true);
            if ($verification_data) {
                echo "<p style='color: green;'>✅ Verifikation erfolgreich - JSON ist gültig</p>";
                echo "<p><strong>Type:</strong> " . htmlspecialchars($verification_data['type'] ?? 'NICHT GEFUNDEN') . "</p>";
                echo "<p><strong>Project ID:</strong> " . htmlspecialchars($verification_data['project_id'] ?? 'NICHT GEFUNDEN') . "</p>";
            } else {
                echo "<p style='color: red;'>❌ Verifikation fehlgeschlagen - JSON ist immer noch ungültig</p>";
            }
        } else {
            echo "<p style='color: red;'>❌ Fehler beim Speichern in Datenbank</p>";
        }
    } else {
        echo "<p style='color: red;'>❌ JSON ist immer noch ungültig nach $attempts Decoding-Versuchen</p>";
        echo "<p><strong>Fehler:</strong> " . json_last_error_msg() . "</p>";
        echo "<p>Möglicherweise ist der JSON-Inhalt zu stark beschädigt.</p>";
        echo "<p>Bitte löschen Sie den aktuellen Inhalt und geben Sie ihn neu ein.</p>";
        
        // JSON-Inhalt löschen
        echo "<h2>5. JSON-Inhalt löschen:</h2>";
        $stmt = $db->prepare("DELETE FROM settings WHERE setting_key = 'google_calendar_service_account_json'");
        $result = $stmt->execute();
        
        if ($result) {
            echo "<p style='color: green;'>✅ JSON-Inhalt gelöscht. Sie können ihn jetzt neu eingeben.</p>";
        } else {
            echo "<p style='color: red;'>❌ Fehler beim Löschen des JSON-Inhalts</p>";
        }
    }
    
    echo "<h2>7. Nächste Schritte:</h2>";
    echo "<ol>";
    echo "<li>Gehen Sie zu <a href='admin/settings.php'>Admin → Einstellungen</a></li>";
    echo "<li>Scrollen Sie zu Google Calendar Einstellungen</li>";
    echo "<li>Geben Sie den JSON-Inhalt neu ein</li>";
    echo "<li>Speichern Sie die Einstellungen</li>";
    echo "<li>Testen Sie mit: <a href='test-google-calendar-service-account.php'>Google Calendar Test</a></li>";
    echo "</ol>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<hr>";
echo "<p><em>JSON Double-Encoding Fix abgeschlossen!</em></p>";
?>
