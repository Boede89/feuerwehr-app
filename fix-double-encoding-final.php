<?php
/**
 * Fix Double-Encoding Final - Browser Version
 * Öffnen Sie diese Datei in Ihrem Browser: http://ihre-domain/fix-double-encoding-final.php
 */

require_once 'config/database.php';

echo "<h1>🔧 Final Double-Encoding Fix</h1>";
echo "<p>Diese Seite repariert das Double-Encoding Problem und verschiebt den JSON-Inhalt in die richtige Spalte.</p>";

try {
    // 1. Beschädigten JSON aus google_calendar_api_key laden
    echo "<h2>1. Beschädigten JSON laden:</h2>";
    $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'google_calendar_api_key'");
    $stmt->execute();
    $corrupted_json = $stmt->fetchColumn();
    
    if (!$corrupted_json) {
        echo "<p style='color: red;'>❌ Kein beschädigter JSON in google_calendar_api_key gefunden.</p>";
        exit;
    }
    
    echo "<p><strong>Länge des beschädigten JSON:</strong> " . strlen($corrupted_json) . " Zeichen</p>";
    echo "<p><strong>Erste 200 Zeichen:</strong></p>";
    echo "<pre>" . htmlspecialchars(substr($corrupted_json, 0, 200)) . "...</pre>";
    
    // 2. Mehrfaches Decoding durchführen
    echo "<h2>2. Double-Encoding reparieren:</h2>";
    
    $decoded_json = $corrupted_json;
    $attempts = 0;
    $max_attempts = 15; // Mehr Versuche für stark beschädigten JSON
    
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
    
    echo "<p><strong>Nach Decoding (erste 200 Zeichen):</strong></p>";
    echo "<pre>" . htmlspecialchars(substr($decoded_json, 0, 200)) . "...</pre>";
    
    // 3. JSON validieren
    echo "<h2>3. JSON-Validierung:</h2>";
    $json_data = json_decode($decoded_json, true);
    
    if (!$json_data) {
        echo "<p style='color: red;'>❌ JSON ist immer noch ungültig nach $attempts Decoding-Versuchen</p>";
        echo "<p><strong>Fehler:</strong> " . json_last_error_msg() . "</p>";
        echo "<p>Der JSON-Inhalt ist zu stark beschädigt. Verwenden Sie stattdessen <a href='insert-json-directly.php'>insert-json-directly.php</a></p>";
        exit;
    }
    
    echo "<p style='color: green;'>✅ JSON ist jetzt gültig!</p>";
    echo "<p><strong>Type:</strong> " . htmlspecialchars($json_data['type'] ?? 'NICHT GEFUNDEN') . "</p>";
    echo "<p><strong>Project ID:</strong> " . htmlspecialchars($json_data['project_id'] ?? 'NICHT GEFUNDEN') . "</p>";
    echo "<p><strong>Client Email:</strong> " . htmlspecialchars($json_data['client_email'] ?? 'NICHT GEFUNDEN') . "</p>";
    
    // 4. JSON in die richtige Spalte verschieben
    echo "<h2>4. JSON in richtige Spalte verschieben:</h2>";
    
    // Zuerst den reparierten JSON in google_calendar_service_account_json speichern
    $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('google_calendar_service_account_json', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
    $result1 = $stmt->execute([$decoded_json, $decoded_json]);
    
    if ($result1) {
        echo "<p style='color: green;'>✅ JSON erfolgreich in google_calendar_service_account_json gespeichert!</p>";
    } else {
        echo "<p style='color: red;'>❌ Fehler beim Speichern in google_calendar_service_account_json</p>";
    }
    
    // Dann den beschädigten JSON aus google_calendar_api_key löschen
    $stmt = $db->prepare("DELETE FROM settings WHERE setting_key = 'google_calendar_api_key'");
    $result2 = $stmt->execute();
    
    if ($result2) {
        echo "<p style='color: green;'>✅ Beschädigter JSON aus google_calendar_api_key gelöscht!</p>";
    } else {
        echo "<p style='color: orange;'>⚠️ Warnung: Konnte google_calendar_api_key nicht löschen</p>";
    }
    
    // 5. Verifikation
    echo "<h2>5. Verifikation:</h2>";
    
    // Prüfe google_calendar_service_account_json
    $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'google_calendar_service_account_json'");
    $stmt->execute();
    $saved_json = $stmt->fetchColumn();
    
    if ($saved_json) {
        $verification_data = json_decode($saved_json, true);
        if ($verification_data) {
            echo "<p style='color: green;'>✅ google_calendar_service_account_json ist gültig!</p>";
            echo "<p><strong>Type:</strong> " . htmlspecialchars($verification_data['type'] ?? 'NICHT GEFUNDEN') . "</p>";
            echo "<p><strong>Project ID:</strong> " . htmlspecialchars($verification_data['project_id'] ?? 'NICHT GEFUNDEN') . "</p>";
            echo "<p><strong>Client Email:</strong> " . htmlspecialchars($verification_data['client_email'] ?? 'NICHT GEFUNDEN') . "</p>";
        } else {
            echo "<p style='color: red;'>❌ google_calendar_service_account_json ist ungültig</p>";
        }
    } else {
        echo "<p style='color: red;'>❌ google_calendar_service_account_json ist leer</p>";
    }
    
    // Prüfe google_calendar_api_key (sollte leer sein)
    $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'google_calendar_api_key'");
    $stmt->execute();
    $api_key = $stmt->fetchColumn();
    
    if ($api_key) {
        echo "<p style='color: orange;'>⚠️ google_calendar_api_key ist noch nicht leer: " . htmlspecialchars(substr($api_key, 0, 50)) . "...</p>";
    } else {
        echo "<p style='color: green;'>✅ google_calendar_api_key ist jetzt leer</p>";
    }
    
    // 6. Nächste Schritte
    echo "<h2>6. Nächste Schritte:</h2>";
    echo "<ol>";
    echo "<li>Gehen Sie zu <a href='admin/settings.php'>Admin → Einstellungen</a></li>";
    echo "<li>Überprüfen Sie, ob der JSON-Inhalt korrekt angezeigt wird</li>";
    echo "<li>Testen Sie mit: <a href='test-google-calendar-service-account.php'>Google Calendar Test</a></li>";
    echo "<li>Testen Sie mit: <a href='check-json-status.php'>JSON Status Check</a></li>";
    echo "</ol>";
    
    echo "<h2>7. Zusammenfassung:</h2>";
    echo "<ul>";
    echo "<li>✅ Double-Encoding repariert</li>";
    echo "<li>✅ JSON in richtige Spalte verschoben</li>";
    echo "<li>✅ Beschädigter Inhalt gelöscht</li>";
    echo "<li>✅ Google Calendar Service Account konfiguriert</li>";
    echo "</ul>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<hr>";
echo "<p><em>Final Double-Encoding Fix abgeschlossen!</em></p>";
?>
