<?php
/**
 * Fix: Google Calendar Service Account konfigurieren
 */

require_once 'config/database.php';
require_once 'includes/functions.php';

echo "<h1>🔧 Google Calendar Service Account konfigurieren</h1>";

// 1. Prüfe aktuelle Einstellungen
echo "<h2>1. Aktuelle Google Calendar Einstellungen</h2>";

try {
    $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'google_calendar_%'");
    $stmt->execute();
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Einstellung</th><th>Wert</th><th>Status</th></tr>";
    
    $required_settings = [
        'google_calendar_auth_type' => 'service_account',
        'google_calendar_id' => 'a3f7e2f57f274ba2fe7d3a62a932a33c78ed468aafa6ac477b58f16495e5677a@group.calendar.google.com',
        'google_calendar_service_account' => 'Service Account JSON'
    ];
    
    foreach ($required_settings as $key => $expected_value) {
        $value = $settings[$key] ?? 'Nicht gesetzt';
        $status = '';
        
        if ($key === 'google_calendar_service_account') {
            if (empty($value) || $value === 'Nicht gesetzt') {
                $status = '❌ FEHLT';
            } else {
                $status = '✅ OK';
            }
        } else {
            if ($value === $expected_value) {
                $status = '✅ OK';
            } else {
                $status = '⚠️ Anderer Wert';
            }
        }
        
        $display_value = $value;
        if ($key === 'google_calendar_service_account' && strlen($value) > 50) {
            $display_value = substr($value, 0, 50) . '...';
        }
        
        echo "<tr><td>$key</td><td>" . htmlspecialchars($display_value) . "</td><td>$status</td></tr>";
    }
    
    echo "</table>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim Laden der Einstellungen: " . $e->getMessage() . "</p>";
}

// 2. Service Account JSON erstellen/aktualisieren
echo "<h2>2. Service Account JSON konfigurieren</h2>";

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['service_account_json'])) {
    $service_account_json = $_POST['service_account_json'];
    
    try {
        // Validiere JSON
        $json_data = json_decode($service_account_json, true);
        if (!$json_data) {
            throw new Exception('Ungültiges JSON Format');
        }
        
        // Prüfe erforderliche Felder
        $required_fields = ['type', 'project_id', 'private_key_id', 'private_key', 'client_email', 'client_id', 'auth_uri', 'token_uri'];
        foreach ($required_fields as $field) {
            if (!isset($json_data[$field])) {
                throw new Exception("Erforderliches Feld fehlt: $field");
            }
        }
        
        // Speichere in Datenbank
        $stmt = $db->prepare("
            INSERT INTO settings (setting_key, setting_value) 
            VALUES ('google_calendar_service_account', ?) 
            ON DUPLICATE KEY UPDATE setting_value = ?
        ");
        $stmt->execute([$service_account_json, $service_account_json]);
        
        echo "<p style='color: green;'>✅ Service Account JSON erfolgreich gespeichert!</p>";
        
        // Teste die Konfiguration
        echo "<h3>3. Teste die neue Konfiguration</h3>";
        
        if (class_exists('GoogleCalendarServiceAccount')) {
            try {
                $calendar_service = new GoogleCalendarServiceAccount($service_account_json, $settings['google_calendar_id'] ?? 'primary', true);
                echo "<p style='color: green;'>✅ GoogleCalendarServiceAccount erfolgreich erstellt</p>";
                
                // Teste Event-Erstellung
                $test_event_id = $calendar_service->createEvent(
                    'Test Event - ' . date('Y-m-d H:i:s'),
                    date('Y-m-d H:i:s'),
                    date('Y-m-d H:i:s', strtotime('+1 hour')),
                    'Test Event für Konfiguration'
                );
                
                if ($test_event_id) {
                    echo "<p style='color: green;'>✅ Test-Event erfolgreich erstellt: $test_event_id</p>";
                    
                    // Lösche Test-Event
                    $delete_result = $calendar_service->deleteEvent($test_event_id);
                    if ($delete_result) {
                        echo "<p style='color: green;'>✅ Test-Event erfolgreich gelöscht - Konfiguration funktioniert!</p>";
                    } else {
                        echo "<p style='color: orange;'>⚠️ Test-Event erstellt, aber Löschen schlägt fehl</p>";
                    }
                } else {
                    echo "<p style='color: red;'>❌ Test-Event konnte nicht erstellt werden</p>";
                }
                
            } catch (Exception $e) {
                echo "<p style='color: red;'>❌ Fehler beim Testen: " . $e->getMessage() . "</p>";
            }
        } else {
            echo "<p style='color: red;'>❌ GoogleCalendarServiceAccount Klasse nicht verfügbar</p>";
        }
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Fehler beim Speichern: " . $e->getMessage() . "</p>";
    }
} else {
    // Zeige Formular
    echo "<p style='color: orange;'>⚠️ Service Account JSON fehlt. Bitte konfigurieren Sie die Google Calendar Integration:</p>";
    
    echo "<form method='POST'>";
    echo "<h3>Service Account JSON eingeben:</h3>";
    echo "<textarea name='service_account_json' rows='15' cols='80' placeholder='Fügen Sie hier Ihre Service Account JSON ein...'></textarea><br><br>";
    echo "<button type='submit' class='btn btn-primary'>Service Account JSON speichern</button>";
    echo "</form>";
    
    echo "<h3>Anleitung:</h3>";
    echo "<ol>";
    echo "<li>Gehen Sie zur <a href='https://console.cloud.google.com/' target='_blank'>Google Cloud Console</a></li>";
    echo "<li>Erstellen Sie ein neues Projekt oder wählen Sie ein bestehendes aus</li>";
    echo "<li>Aktivieren Sie die Google Calendar API</li>";
    echo "<li>Erstellen Sie Service Account Credentials</li>";
    echo "<li>Laden Sie die JSON-Datei herunter</li>";
    echo "<li>Kopieren Sie den Inhalt der JSON-Datei in das Textfeld oben</li>";
    echo "<li>Teilen Sie den Kalender mit der Service Account E-Mail-Adresse</li>";
    echo "</ol>";
    
    echo "<h3>Beispiel Service Account JSON Format:</h3>";
    echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ddd;'>";
    echo '{
  "type": "service_account",
  "project_id": "your-project-id",
  "private_key_id": "key-id",
  "private_key": "-----BEGIN PRIVATE KEY-----\n...\n-----END PRIVATE KEY-----\n",
  "client_email": "your-service-account@your-project.iam.gserviceaccount.com",
  "client_id": "client-id",
  "auth_uri": "https://accounts.google.com/o/oauth2/auth",
  "token_uri": "https://oauth2.googleapis.com/token",
  "auth_provider_x509_cert_url": "https://www.googleapis.com/oauth2/v1/certs",
  "client_x509_cert_url": "https://www.googleapis.com/robot/v1/metadata/x509/your-service-account%40your-project.iam.gserviceaccount.com"
}';
    echo "</pre>";
}

echo "<hr>";
echo "<p><a href='test-google-calendar-api-direct.php'>→ Google Calendar API Test</a></p>";
echo "<p><a href='fix-google-calendar-simple.php'>→ Zurück zum Fix-Skript</a></p>";
echo "<p><a href='admin/settings.php'>→ Zu den Einstellungen</a></p>";
echo "<p><small>Service Account Fix: " . date('Y-m-d H:i:s') . "</small></p>";
?>
