<?php
/**
 * Test: Neuer Service Account JSON Code
 */

require_once 'config/database.php';
require_once 'includes/functions.php';

echo "<h1>🔑 Test: Neuer Service Account JSON Code</h1>";

// 1. Zeige aktuellen JSON Code
echo "<h2>1. Aktueller JSON Code</h2>";

try {
    $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'google_calendar_service_account_json'");
    $stmt->execute();
    $current_json = $stmt->fetchColumn();
    
    if ($current_json) {
        echo "<p style='color: green;'>✅ Aktueller JSON Code gefunden</p>";
        echo "<p><strong>Länge:</strong> " . strlen($current_json) . " Zeichen</p>";
        
        // Prüfe JSON Format
        $json_data = json_decode($current_json, true);
        if ($json_data) {
            echo "<p style='color: green;'>✅ JSON Code ist gültig</p>";
            echo "<p><strong>Client Email:</strong> " . ($json_data['client_email'] ?? 'Nicht gefunden') . "</p>";
            echo "<p><strong>Project ID:</strong> " . ($json_data['project_id'] ?? 'Nicht gefunden') . "</p>";
        } else {
            echo "<p style='color: red;'>❌ JSON Code ist ungültig</p>";
        }
    } else {
        echo "<p style='color: red;'>❌ Kein JSON Code gefunden</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim Laden des JSON Codes: " . $e->getMessage() . "</p>";
}

// 2. Formular für neuen JSON Code
echo "<h2>2. Neuen JSON Code einfügen</h2>";

if (isset($_POST['new_json'])) {
    $new_json = $_POST['new_json'];
    
    echo "<h3>2.1 Teste neuen JSON Code</h3>";
    
    // Validiere JSON
    $json_data = json_decode($new_json, true);
    if (!$json_data) {
        echo "<p style='color: red;'>❌ Neuer JSON Code ist ungültig</p>";
    } else {
        echo "<p style='color: green;'>✅ Neuer JSON Code ist gültig</p>";
        echo "<p><strong>Client Email:</strong> " . ($json_data['client_email'] ?? 'Nicht gefunden') . "</p>";
        echo "<p><strong>Project ID:</strong> " . ($json_data['project_id'] ?? 'Nicht gefunden') . "</p>";
        
        // Speichere neuen JSON Code
        try {
            $stmt = $db->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'google_calendar_service_account_json'");
            $stmt->execute([$new_json]);
            
            echo "<p style='color: green; font-weight: bold;'>🎉 Neuer JSON Code gespeichert!</p>";
            
            // Teste mit neuem JSON Code
            echo "<h3>2.2 Teste mit neuem JSON Code</h3>";
            
            try {
                if (class_exists('GoogleCalendarServiceAccount')) {
                    $calendar_service = new GoogleCalendarServiceAccount($new_json, 'a3f7e2f57f274ba2fe7d3a62a932a33c78ed468aafa6ac477b58f16495e5677a@group.calendar.google.com', true);
                    
                    // Erstelle Test-Event
                    echo "<h4>2.2.1 Erstelle Test-Event</h4>";
                    
                    $test_event_id = create_google_calendar_event(
                        'MTF',
                        'Neuer JSON Test',
                        date('Y-m-d H:i:s'),
                        date('Y-m-d H:i:s', strtotime('+1 hour')),
                        null,
                        'Feuerwehrhaus Ammern'
                    );
                    
                    if ($test_event_id) {
                        echo "<p style='color: green;'>✅ Test-Event erstellt: $test_event_id</p>";
                        
                        // Teste Löschen
                        echo "<h4>2.2.2 Teste Löschen</h4>";
                        
                        $start_time = microtime(true);
                        $result = $calendar_service->deleteEvent($test_event_id);
                        $end_time = microtime(true);
                        
                        $duration = round(($end_time - $start_time) * 1000, 2);
                        
                        echo "<p><strong>Lösch-Dauer:</strong> {$duration}ms</p>";
                        echo "<p><strong>deleteEvent Ergebnis:</strong> " . ($result ? 'TRUE' : 'FALSE') . "</p>";
                        
                        if ($result) {
                            echo "<p style='color: green; font-weight: bold;'>🎉 Löschen mit neuem JSON Code erfolgreich!</p>";
                            
                            // Prüfe Event Status
                            echo "<h4>2.2.3 Prüfe Event Status</h4>";
                            
                            try {
                                $event = $calendar_service->getEvent($test_event_id);
                                echo "<p style='color: orange;'>⚠️ Event existiert noch nach dem Löschen</p>";
                                echo "<p><strong>Status:</strong> " . ($event['status'] ?? 'Unbekannt') . "</p>";
                                
                                if (isset($event['status']) && $event['status'] === 'cancelled') {
                                    echo "<p style='color: blue;'>ℹ️ Event ist cancelled - sollte durchgestrichen sein</p>";
                                } else {
                                    echo "<p style='color: red;'>❌ Event ist NICHT cancelled - das ist das Problem!</p>";
                                }
                                
                            } catch (Exception $e) {
                                if (strpos($e->getMessage(), '404') !== false) {
                                    echo "<p style='color: green; font-weight: bold;'>🎉 Event wurde vollständig gelöscht (404 Not Found)!</p>";
                                } else {
                                    echo "<p style='color: red;'>❌ Fehler beim Abrufen des Events: " . $e->getMessage() . "</p>";
                                }
                            }
                            
                        } else {
                            echo "<p style='color: red;'>❌ Löschen mit neuem JSON Code fehlgeschlagen</p>";
                        }
                        
                    } else {
                        echo "<p style='color: red;'>❌ Test-Event konnte nicht erstellt werden</p>";
                    }
                    
                }
                
            } catch (Exception $e) {
                echo "<p style='color: red;'>❌ Fehler beim Test mit neuem JSON Code: " . $e->getMessage() . "</p>";
            }
            
        } catch (Exception $e) {
            echo "<p style='color: red;'>❌ Fehler beim Speichern des neuen JSON Codes: " . $e->getMessage() . "</p>";
        }
    }
    
} else {
    echo "<form method='POST'>";
    echo "<div class='mb-3'>";
    echo "<label for='new_json' class='form-label'><strong>Neuen Service Account JSON Code einfügen:</strong></label>";
    echo "<textarea class='form-control' id='new_json' name='new_json' rows='10' placeholder='Fügen Sie hier Ihren neuen JSON Code ein...'></textarea>";
    echo "</div>";
    echo "<button type='submit' class='btn btn-primary'>Neuen JSON Code testen und speichern</button>";
    echo "</form>";
}

echo "<hr>";
echo "<h2>📋 Anweisungen</h2>";
echo "<ol>";
echo "<li><strong>Kopieren Sie Ihren neuen Service Account JSON Code</strong></li>";
echo "<li><strong>Fügen Sie ihn in das Textfeld oben ein</strong></li>";
echo "<li><strong>Klicken Sie auf 'Neuen JSON Code testen und speichern'</strong></li>";
echo "<li><strong>Das Skript wird den neuen Code testen und speichern</strong></li>";
echo "<li><strong>Ein Test-Event wird erstellt und gelöscht</strong></li>";
echo "</ol>";

echo "<p><a href='admin/settings.php'>→ Zur Einstellungen-Übersicht</a></p>";
echo "<p><a href='test-direct-delete.php'>→ Direkte Lösch-Methode Test</a></p>";
echo "<p><small>Neuer JSON Code Test abgeschlossen: " . date('Y-m-d H:i:s') . "</small></p>";
?>
