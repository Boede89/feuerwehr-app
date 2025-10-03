<?php
/**
 * Debug Google Calendar Einstellungen
 */

require_once 'config/database.php';

echo "🔍 Google Calendar Einstellungen Debug\n";
echo "=====================================\n\n";

try {
    // Alle Google Calendar Einstellungen laden
    $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'google_calendar_%'");
    $stmt->execute();
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    echo "1. Aktuelle Einstellungen in der Datenbank:\n";
    foreach ($settings as $key => $value) {
        if ($key === 'google_calendar_service_account_json') {
            $json_length = strlen($value);
            $json_preview = $json_length > 0 ? substr($value, 0, 100) . '...' : 'LEER';
            echo "   $key: $json_preview (Länge: $json_length)\n";
        } else {
            echo "   $key: " . ($value ?: 'LEER') . "\n";
        }
    }
    
    echo "\n2. POST-Daten Simulation:\n";
    echo "   service_account_method_value: " . ($_POST['service_account_method_value'] ?? 'NICHT GESETZT') . "\n";
    echo "   google_calendar_service_account_json: " . (isset($_POST['google_calendar_service_account_json']) ? 'GESETZT' : 'NICHT GESETZT') . "\n";
    
    echo "\n3. JSON-Validierung:\n";
    if (!empty($settings['google_calendar_service_account_json'])) {
        $json_data = json_decode($settings['google_calendar_service_account_json'], true);
        if ($json_data) {
            echo "   ✅ JSON ist gültig\n";
            echo "   Type: " . ($json_data['type'] ?? 'NICHT GEFUNDEN') . "\n";
            echo "   Project ID: " . ($json_data['project_id'] ?? 'NICHT GEFUNDEN') . "\n";
            echo "   Client Email: " . ($json_data['client_email'] ?? 'NICHT GEFUNDEN') . "\n";
        } else {
            echo "   ❌ JSON ist ungültig\n";
        }
    } else {
        echo "   ⚠️  Kein JSON-Inhalt vorhanden\n";
    }
    
    echo "\n4. Empfohlene Aktionen:\n";
    if (empty($settings['google_calendar_service_account_json'])) {
        echo "   - JSON-Inhalt in den Einstellungen eingeben\n";
        echo "   - 'JSON-Inhalt' Radio-Button auswählen\n";
        echo "   - Einstellungen speichern\n";
    } else {
        echo "   - Einstellungen sind korrekt konfiguriert\n";
        echo "   - Test mit: docker exec feuerwehr_web php test-google-calendar-service-account.php\n";
    }
    
    echo "\n5. Test POST-Daten:\n";
    echo "   Um die Einstellungen zu testen, können Sie folgende URL aufrufen:\n";
    echo "   http://ihre-domain/debug-google-calendar-settings.php?test=1\n";
    
    if (isset($_GET['test'])) {
        echo "\n6. Test-Modus aktiviert:\n";
        
        // Simuliere POST-Daten
        $_POST['service_account_method_value'] = 'json';
        $_POST['google_calendar_service_account_json'] = '{"type": "service_account", "test": true}';
        
        echo "   Simuliere Service Account Methode: " . ($_POST['service_account_method_value'] ?? 'NICHT GESETZT') . "\n";
        echo "   Simuliere JSON-Inhalt: " . (isset($_POST['google_calendar_service_account_json']) ? 'GESETZT' : 'NICHT GESETZT') . "\n";
        
        // Teste die Logik
        $service_account_method = $_POST['service_account_method_value'] ?? 'file';
        $json_content = $service_account_method === 'json' ? ($_POST['google_calendar_service_account_json'] ?? '') : '';
        
        echo "   Ergebnis JSON-Inhalt: " . ($json_content ?: 'LEER') . "\n";
        echo "   Methode: $service_account_method\n";
    }
    
} catch (Exception $e) {
    echo "❌ Fehler: " . $e->getMessage() . "\n";
}

echo "\n🎯 Debug abgeschlossen!\n";
?>
