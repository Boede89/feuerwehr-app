<?php
/**
 * Fix Extreme Double-Encoding - Browser Version
 * Öffnen Sie diese Datei in Ihrem Browser: http://ihre-domain/fix-extreme-double-encoding.php
 */

require_once 'config/database.php';

echo "<h1>🔧 Extreme Double-Encoding Fix</h1>";
echo "<p>Diese Seite repariert extrem beschädigten JSON mit erweiterten Decoding-Methoden.</p>";

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
    
    // 2. Erweiterte Decoding-Methoden
    echo "<h2>2. Erweiterte Decoding-Methoden:</h2>";
    
    $decoded_json = $corrupted_json;
    $attempts = 0;
    $max_attempts = 50; // Viel mehr Versuche
    $last_length = 0;
    
    echo "<p>Starte mit " . strlen($decoded_json) . " Zeichen...</p>";
    
    while ($attempts < $max_attempts) {
        $attempts++;
        $previous_length = strlen($decoded_json);
        
        // Methode 1: Standard HTML-Entity Decoding
        $decoded_json = html_entity_decode($decoded_json, ENT_QUOTES, 'UTF-8');
        
        // Methode 2: Zusätzliche manuelle Ersetzungen
        $decoded_json = str_replace('&amp;', '&', $decoded_json);
        $decoded_json = str_replace('&lt;', '<', $decoded_json);
        $decoded_json = str_replace('&gt;', '>', $decoded_json);
        $decoded_json = str_replace('&quot;', '"', $decoded_json);
        $decoded_json = str_replace('&#39;', "'", $decoded_json);
        
        // Methode 3: Spezielle Double-Encoding Fälle
        $decoded_json = str_replace('&amp;amp;', '&', $decoded_json);
        $decoded_json = str_replace('&amp;lt;', '<', $decoded_json);
        $decoded_json = str_replace('&amp;gt;', '>', $decoded_json);
        $decoded_json = str_replace('&amp;quot;', '"', $decoded_json);
        $decoded_json = str_replace('&amp;#39;', "'", $decoded_json);
        
        // Prüfen ob sich etwas geändert hat
        $current_length = strlen($decoded_json);
        if ($current_length === $previous_length) {
            echo "<p>Versuch $attempts: Keine Änderung mehr - Decoding abgeschlossen</p>";
            break;
        }
        
        echo "<p>Versuch $attempts: Länge von $previous_length auf $current_length reduziert</p>";
        
        // Alle 10 Versuche eine Zwischenprüfung
        if ($attempts % 10 === 0) {
            $test_json = json_decode($decoded_json, true);
            if ($test_json) {
                echo "<p style='color: green;'>✅ JSON ist nach $attempts Versuchen gültig geworden!</p>";
                break;
            }
        }
        
        $last_length = $current_length;
    }
    
    echo "<p><strong>Nach Decoding (erste 200 Zeichen):</strong></p>";
    echo "<pre>" . htmlspecialchars(substr($decoded_json, 0, 200)) . "...</pre>";
    echo "<p><strong>Finale Länge:</strong> " . strlen($decoded_json) . " Zeichen</p>";
    
    // 3. JSON validieren
    echo "<h2>3. JSON-Validierung:</h2>";
    $json_data = json_decode($decoded_json, true);
    
    if (!$json_data) {
        echo "<p style='color: red;'>❌ JSON ist immer noch ungültig nach $attempts Decoding-Versuchen</p>";
        echo "<p><strong>Fehler:</strong> " . json_last_error_msg() . "</p>";
        
        // Versuche manuelle Reparatur
        echo "<h3>4. Manuelle Reparatur versuchen:</h3>";
        
        // Ersetze häufige Probleme
        $manual_fix = $decoded_json;
        $manual_fix = str_replace('&quot;', '"', $manual_fix);
        $manual_fix = str_replace('&amp;', '&', $manual_fix);
        $manual_fix = str_replace('&lt;', '<', $manual_fix);
        $manual_fix = str_replace('&gt;', '>', $manual_fix);
        $manual_fix = str_replace('&#39;', "'", $manual_fix);
        
        // Entferne führende/nachfolgende Leerzeichen
        $manual_fix = trim($manual_fix);
        
        // Prüfe ob es jetzt funktioniert
        $manual_json = json_decode($manual_fix, true);
        if ($manual_json) {
            echo "<p style='color: green;'>✅ Manuelle Reparatur erfolgreich!</p>";
            $decoded_json = $manual_fix;
            $json_data = $manual_json;
        } else {
            echo "<p style='color: red;'>❌ Auch manuelle Reparatur fehlgeschlagen</p>";
            echo "<p><strong>Fehler:</strong> " . json_last_error_msg() . "</p>";
            echo "<p>Der JSON-Inhalt ist zu stark beschädigt. Verwenden Sie stattdessen <a href='insert-json-directly.php'>insert-json-directly.php</a></p>";
            exit;
        }
    }
    
    if ($json_data) {
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
        echo "<li>✅ Extreme Double-Encoding repariert ($attempts Versuche)</li>";
        echo "<li>✅ JSON in richtige Spalte verschoben</li>";
        echo "<li>✅ Beschädigter Inhalt gelöscht</li>";
        echo "<li>✅ Google Calendar Service Account konfiguriert</li>";
        echo "</ul>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<hr>";
echo "<p><em>Extreme Double-Encoding Fix abgeschlossen!</em></p>";
?>
