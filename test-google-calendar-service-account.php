<?php
/**
 * Test Google Calendar Service Account Integration
 */

require_once 'config/database.php';
require_once 'includes/functions.php';

echo "📅 Google Calendar Service Account Test\n";
echo "=====================================\n\n";

try {
    // Google Calendar Einstellungen laden
    $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'google_calendar_%'");
    $stmt->execute();
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    echo "1. Google Calendar Einstellungen:\n";
    echo "   Auth Type: " . ($settings['google_calendar_auth_type'] ?? 'nicht gesetzt') . "\n";
    echo "   Service Account File: " . ($settings['google_calendar_service_account_file'] ?? 'nicht gesetzt') . "\n";
    echo "   Service Account JSON: " . (!empty($settings['google_calendar_service_account_json']) ? 'GESETZT' : 'nicht gesetzt') . "\n";
    echo "   Calendar ID: " . ($settings['google_calendar_id'] ?? 'nicht gesetzt') . "\n";
    echo "   API Key: " . (!empty($settings['google_calendar_api_key']) ? 'GESETZT' : 'nicht gesetzt') . "\n\n";
    
    $auth_type = $settings['google_calendar_auth_type'] ?? 'service_account';
    
    if ($auth_type === 'service_account') {
        $service_account_file = $settings['google_calendar_service_account_file'] ?? '';
        $service_account_json = $settings['google_calendar_service_account_json'] ?? '';
        
        if (!empty($service_account_json)) {
            // JSON-Inhalt verwenden
            echo "✅ Service Account JSON-Inhalt gefunden\n";
            
            // Service Account JSON validieren
            $service_account = json_decode($service_account_json, true);
            
            if (!$service_account) {
                echo "❌ Service Account JSON ist ungültig!\n\n";
            } else {
                echo "✅ Service Account JSON ist gültig\n";
                echo "   Client Email: " . ($service_account['client_email'] ?? 'nicht gefunden') . "\n";
                echo "   Project ID: " . ($service_account['project_id'] ?? 'nicht gefunden') . "\n";
                echo "   Private Key: " . (!empty($service_account['private_key']) ? 'GEFUNDEN' : 'nicht gefunden') . "\n\n";
                
                // Service Account Test mit JSON
                echo "2. Service Account Verbindung testen (JSON):\n";
                require_once 'includes/google_calendar_service_account.php';
                
                $calendar_id = $settings['google_calendar_id'] ?? 'primary';
                $calendar = new GoogleCalendarServiceAccount($service_account_json, $calendar_id, true);
                
                if ($calendar->testConnection()) {
                    echo "✅ Service Account Verbindung erfolgreich!\n";
                    echo "   Kalender: $calendar_id\n\n";
                    
                    // Test Event erstellen
                    echo "3. Test Event erstellen:\n";
                    $test_title = "Test Event - " . date('Y-m-d H:i:s');
                    $test_start = date('Y-m-d H:i:s', strtotime('+1 hour'));
                    $test_end = date('Y-m-d H:i:s', strtotime('+2 hours'));
                    $test_description = "Test Event von Feuerwehr App";
                    
                    echo "   Titel: $test_title\n";
                    echo "   Start: $test_start\n";
                    echo "   Ende: $test_end\n";
                    echo "   Erstelle Event...\n";
                    
                    $event_id = $calendar->createEvent($test_title, $test_start, $test_end, $test_description);
                    
                    if ($event_id) {
                        echo "✅ Test Event erfolgreich erstellt!\n";
                        echo "   Event ID: $event_id\n";
                        echo "   Event URL: https://calendar.google.com/calendar/event?eid=" . base64url_encode($event_id) . "\n\n";
                        
                        // Event wieder löschen
                        echo "4. Test Event löschen:\n";
                        if ($calendar->deleteEvent($event_id)) {
                            echo "✅ Test Event erfolgreich gelöscht!\n\n";
                        } else {
                            echo "❌ Test Event konnte nicht gelöscht werden\n\n";
                        }
                    } else {
                        echo "❌ Test Event konnte nicht erstellt werden\n\n";
                    }
                } else {
                    echo "❌ Service Account Verbindung fehlgeschlagen!\n";
                    echo "   Mögliche Ursachen:\n";
                    echo "   - Service Account hat keine Berechtigung für den Kalender\n";
                    echo "   - Kalender ID ist falsch\n";
                    echo "   - Service Account ist deaktiviert\n\n";
                }
            }
        } elseif (!empty($service_account_file) && file_exists($service_account_file)) {
            // Datei verwenden
            echo "✅ Service Account Datei gefunden: $service_account_file\n";
            
            // Service Account JSON validieren
            $json_content = file_get_contents($service_account_file);
            $service_account = json_decode($json_content, true);
            
            if (!$service_account) {
                echo "❌ Service Account JSON ist ungültig!\n\n";
            } else {
                echo "✅ Service Account JSON ist gültig\n";
                echo "   Client Email: " . ($service_account['client_email'] ?? 'nicht gefunden') . "\n";
                echo "   Project ID: " . ($service_account['project_id'] ?? 'nicht gefunden') . "\n";
                echo "   Private Key: " . (!empty($service_account['private_key']) ? 'GEFUNDEN' : 'nicht gefunden') . "\n\n";
                
                // Service Account Test
                echo "2. Service Account Verbindung testen:\n";
                require_once 'includes/google_calendar_service_account.php';
                
                $calendar_id = $settings['google_calendar_id'] ?? 'primary';
                $calendar = new GoogleCalendarServiceAccount($service_account_file, $calendar_id);
                
                if ($calendar->testConnection()) {
                    echo "✅ Service Account Verbindung erfolgreich!\n";
                    echo "   Kalender: $calendar_id\n\n";
                    
                    // Test Event erstellen
                    echo "3. Test Event erstellen:\n";
                    $test_title = "Test Event - " . date('Y-m-d H:i:s');
                    $test_start = date('Y-m-d H:i:s', strtotime('+1 hour'));
                    $test_end = date('Y-m-d H:i:s', strtotime('+2 hours'));
                    $test_description = "Test Event von Feuerwehr App";
                    
                    echo "   Titel: $test_title\n";
                    echo "   Start: $test_start\n";
                    echo "   Ende: $test_end\n";
                    echo "   Erstelle Event...\n";
                    
                    $event_id = $calendar->createEvent($test_title, $test_start, $test_end, $test_description);
                    
                    if ($event_id) {
                        echo "✅ Test Event erfolgreich erstellt!\n";
                        echo "   Event ID: $event_id\n";
                        echo "   Event URL: https://calendar.google.com/calendar/event?eid=" . base64url_encode($event_id) . "\n\n";
                        
                        // Event wieder löschen
                        echo "4. Test Event löschen:\n";
                        if ($calendar->deleteEvent($event_id)) {
                            echo "✅ Test Event erfolgreich gelöscht!\n\n";
                        } else {
                            echo "❌ Test Event konnte nicht gelöscht werden\n\n";
                        }
                    } else {
                        echo "❌ Test Event konnte nicht erstellt werden\n\n";
                    }
                } else {
                    echo "❌ Service Account Verbindung fehlgeschlagen!\n";
                    echo "   Mögliche Ursachen:\n";
                    echo "   - Service Account hat keine Berechtigung für den Kalender\n";
                    echo "   - Kalender ID ist falsch\n";
                    echo "   - Service Account ist deaktiviert\n\n";
                }
                }
            }
        } else {
            echo "❌ Service Account nicht konfiguriert!\n";
            echo "   Bitte setzen Sie entweder:\n";
            echo "   - Den Pfad zur JSON-Datei ODER\n";
            echo "   - Den JSON-Inhalt direkt in den Einstellungen\n\n";
        }
    } else {
        echo "ℹ️  API Key Modus aktiviert (Service Account wird nicht verwendet)\n\n";
    }
    
    echo "5. Setup-Anweisungen für Service Account:\n";
    echo "   1. Gehen Sie zu: https://console.cloud.google.com/\n";
    echo "   2. Erstellen Sie ein neues Projekt oder wählen Sie ein bestehendes\n";
    echo "   3. Aktivieren Sie die Google Calendar API\n";
    echo "   4. Gehen Sie zu: APIs & Services → Credentials\n";
    echo "   5. Klicken Sie auf 'Create Credentials' → 'Service Account'\n";
    echo "   6. Geben Sie einen Namen ein (z.B. 'feuerwehr-calendar')\n";
    echo "   7. Klicken Sie auf 'Create and Continue'\n";
    echo "   8. Überspringen Sie die Rollen (optional)\n";
    echo "   9. Klicken Sie auf 'Done'\n";
    echo "   10. Klicken Sie auf den erstellten Service Account\n";
    echo "   11. Gehen Sie zum Tab 'Keys'\n";
    echo "   12. Klicken Sie auf 'Add Key' → 'Create new key'\n";
    echo "   13. Wählen Sie 'JSON' und klicken Sie 'Create'\n";
    echo "   14. Laden Sie die JSON-Datei herunter\n";
    echo "   15. Laden Sie die Datei auf Ihren Server hoch\n";
    echo "   16. Notieren Sie den absoluten Pfad zur Datei\n";
    echo "   17. Gehen Sie zu Ihrem Google Kalender\n";
    echo "   18. Klicken Sie auf 'Einstellungen' → 'Kalender freigeben'\n";
    echo "   19. Fügen Sie die E-Mail-Adresse des Service Accounts hinzu\n";
    echo "   20. Setzen Sie die Berechtigung auf 'Ereignisse verwalten'\n\n";
    
    echo "6. Vorteile von Service Account:\n";
    echo "   ✅ Sicherer als API Keys\n";
    echo "   ✅ Keine Benutzerinteraktion erforderlich\n";
    echo "   ✅ Automatische Token-Erneuerung\n";
    echo "   ✅ Granulare Berechtigungen\n";
    echo "   ✅ Keine OAuth-Flows\n\n";
    
} catch (Exception $e) {
    echo "❌ Fehler: " . $e->getMessage() . "\n";
}

echo "🎯 Google Calendar Service Account Test abgeschlossen!\n";

// Base64 URL Encoding Funktion
function base64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
?>
