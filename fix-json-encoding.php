<?php
/**
 * Fix JSON Double-Encoding Problem
 */

require_once 'config/database.php';

echo "🔧 JSON Double-Encoding Fix\n";
echo "===========================\n\n";

try {
    // Aktuellen JSON-Inhalt aus der Datenbank laden
    $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'google_calendar_service_account_json'");
    $stmt->execute();
    $current_json = $stmt->fetchColumn();
    
    if (!$current_json) {
        echo "❌ Kein JSON-Inhalt in der Datenbank gefunden.\n";
        exit;
    }
    
    echo "1. Aktueller JSON-Inhalt (erste 200 Zeichen):\n";
    echo substr($current_json, 0, 200) . "...\n\n";
    
    echo "2. Länge: " . strlen($current_json) . " Zeichen\n\n";
    
    // Versuche mehrfaches Decoding
    $decoded_json = $current_json;
    $attempts = 0;
    $max_attempts = 10;
    
    echo "3. Decoding-Versuche:\n";
    
    while ($attempts < $max_attempts) {
        $attempts++;
        $previous_length = strlen($decoded_json);
        
        // HTML-Entities dekodieren
        $decoded_json = html_entity_decode($decoded_json, ENT_QUOTES, 'UTF-8');
        
        // Prüfen ob sich etwas geändert hat
        if (strlen($decoded_json) === $previous_length) {
            echo "   Versuch $attempts: Keine Änderung mehr - Decoding abgeschlossen\n";
            break;
        }
        
        echo "   Versuch $attempts: Länge von $previous_length auf " . strlen($decoded_json) . " reduziert\n";
    }
    
    echo "\n4. Nach Decoding (erste 200 Zeichen):\n";
    echo substr($decoded_json, 0, 200) . "...\n\n";
    
    echo "5. JSON-Validierung:\n";
    $json_data = json_decode($decoded_json, true);
    
    if ($json_data) {
        echo "   ✅ JSON ist jetzt gültig!\n";
        echo "   Type: " . ($json_data['type'] ?? 'NICHT GEFUNDEN') . "\n";
        echo "   Project ID: " . ($json_data['project_id'] ?? 'NICHT GEFUNDEN') . "\n";
        echo "   Client Email: " . ($json_data['client_email'] ?? 'NICHT GEFUNDEN') . "\n\n";
        
        // Reparierten JSON in Datenbank speichern
        echo "6. Reparierten JSON in Datenbank speichern:\n";
        $stmt = $db->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'google_calendar_service_account_json'");
        $result = $stmt->execute([$decoded_json]);
        
        if ($result) {
            echo "   ✅ JSON erfolgreich repariert und gespeichert!\n";
            echo "   Neue Länge: " . strlen($decoded_json) . " Zeichen\n\n";
            
            echo "7. Verifikation:\n";
            $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'google_calendar_service_account_json'");
            $stmt->execute();
            $saved_json = $stmt->fetchColumn();
            
            $verification_data = json_decode($saved_json, true);
            if ($verification_data) {
                echo "   ✅ Verifikation erfolgreich - JSON ist gültig\n";
                echo "   Type: " . ($verification_data['type'] ?? 'NICHT GEFUNDEN') . "\n";
                echo "   Project ID: " . ($verification_data['project_id'] ?? 'NICHT GEFUNDEN') . "\n";
            } else {
                echo "   ❌ Verifikation fehlgeschlagen - JSON ist immer noch ungültig\n";
            }
        } else {
            echo "   ❌ Fehler beim Speichern in Datenbank\n";
        }
    } else {
        echo "   ❌ JSON ist immer noch ungültig nach $attempts Decoding-Versuchen\n";
        echo "   Fehler: " . json_last_error_msg() . "\n";
        echo "   Möglicherweise ist der JSON-Inhalt zu stark beschädigt.\n";
        echo "   Bitte löschen Sie den aktuellen Inhalt und geben Sie ihn neu ein.\n";
    }
    
    echo "\n8. Empfohlene Aktionen:\n";
    echo "   - Gehen Sie zu Admin → Einstellungen\n";
    echo "   - Prüfen Sie ob der JSON-Inhalt jetzt korrekt angezeigt wird\n";
    echo "   - Falls nicht, löschen Sie den Inhalt und geben Sie ihn neu ein\n";
    echo "   - Testen Sie mit: docker exec feuerwehr_web php test-google-calendar-service-account.php\n";
    
} catch (Exception $e) {
    echo "❌ Fehler: " . $e->getMessage() . "\n";
}

echo "\n🎯 JSON Double-Encoding Fix abgeschlossen!\n";
?>
