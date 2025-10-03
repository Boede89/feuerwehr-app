<?php
/**
 * Test JSON-Speicher-Problem
 */

require_once 'config/database.php';

echo "🧪 JSON-Speicher Test\n";
echo "====================\n\n";

try {
    // Test JSON-Inhalt
    $test_json = '{"type": "service_account", "project_id": "test-project", "private_key": "-----BEGIN PRIVATE KEY-----\nMIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQC...\n-----END PRIVATE KEY-----\n", "client_email": "test@test-project.iam.gserviceaccount.com"}';
    
    echo "1. Test JSON-Inhalt:\n";
    echo "   Länge: " . strlen($test_json) . " Zeichen\n";
    echo "   Preview: " . substr($test_json, 0, 100) . "...\n\n";
    
    // Simuliere POST-Daten
    $_POST['google_calendar_service_account_json'] = $test_json;
    $_POST['google_calendar_service_account_file'] = '';
    $_POST['google_calendar_id'] = 'primary';
    $_POST['google_calendar_auth_type'] = 'service_account';
    
    echo "2. Simulierte POST-Daten:\n";
    echo "   JSON Length: " . strlen($_POST['google_calendar_service_account_json']) . "\n";
    echo "   File Path: '" . ($_POST['google_calendar_service_account_file'] ?: 'LEER') . "'\n";
    echo "   Calendar ID: " . $_POST['google_calendar_id'] . "\n";
    echo "   Auth Type: " . $_POST['google_calendar_auth_type'] . "\n\n";
    
    // Teste die Logik aus settings.php
    $json_content = $_POST['google_calendar_service_account_json'] ?? '';
    $file_path = sanitize_input($_POST['google_calendar_service_account_file'] ?? '');
    
    echo "3. Nach Verarbeitung:\n";
    echo "   JSON Content Length: " . strlen($json_content) . "\n";
    echo "   JSON Content Preview: " . substr($json_content, 0, 100) . "...\n";
    echo "   File Path: '" . $file_path . "'\n\n";
    
    // Teste JSON-Validierung
    $json_data = json_decode($json_content, true);
    if ($json_data) {
        echo "4. JSON-Validierung:\n";
        echo "   ✅ JSON ist gültig\n";
        echo "   Type: " . ($json_data['type'] ?? 'NICHT GEFUNDEN') . "\n";
        echo "   Project ID: " . ($json_data['project_id'] ?? 'NICHT GEFUNDEN') . "\n";
        echo "   Client Email: " . ($json_data['client_email'] ?? 'NICHT GEFUNDEN') . "\n\n";
    } else {
        echo "4. JSON-Validierung:\n";
        echo "   ❌ JSON ist ungültig\n";
        echo "   Fehler: " . json_last_error_msg() . "\n\n";
    }
    
    // Teste Datenbank-Speicherung
    echo "5. Datenbank-Speicherung testen:\n";
    
    // Lösche alte Test-Einstellungen
    $stmt = $db->prepare("DELETE FROM settings WHERE setting_key = 'google_calendar_service_account_json'");
    $stmt->execute();
    
    // Speichere Test-JSON
    $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('google_calendar_service_account_json', ?)");
    $result = $stmt->execute([$json_content]);
    
    if ($result) {
        echo "   ✅ JSON erfolgreich in Datenbank gespeichert\n";
        
        // Lade JSON wieder aus Datenbank
        $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'google_calendar_service_account_json'");
        $stmt->execute();
        $saved_json = $stmt->fetchColumn();
        
        echo "   Gespeicherte Länge: " . strlen($saved_json) . " Zeichen\n";
        echo "   Gespeicherte Preview: " . substr($saved_json, 0, 100) . "...\n";
        
        // Vergleiche
        if ($saved_json === $json_content) {
            echo "   ✅ JSON identisch (Speicherung erfolgreich)\n";
        } else {
            echo "   ❌ JSON unterschiedlich (Speicherung fehlgeschlagen)\n";
            echo "   Unterschiede:\n";
            echo "   Original: " . substr($json_content, 0, 50) . "...\n";
            echo "   Gespeichert: " . substr($saved_json, 0, 50) . "...\n";
        }
    } else {
        echo "   ❌ Fehler beim Speichern in Datenbank\n";
    }
    
    echo "\n6. Empfohlene Aktionen:\n";
    echo "   - Prüfen Sie die Browser-Konsole auf JavaScript-Fehler\n";
    echo "   - Prüfen Sie die PHP-Logs: docker logs feuerwehr_web\n";
    echo "   - Testen Sie mit einem einfachen JSON: {\"test\": \"value\"}\n";
    
} catch (Exception $e) {
    echo "❌ Fehler: " . $e->getMessage() . "\n";
}

echo "\n🎯 JSON-Speicher Test abgeschlossen!\n";
?>
