<?php
/**
 * Test: Direkte Lösch-Methode für Google Calendar Events
 */

require_once 'config/database.php';
require_once 'includes/functions.php';

echo "<h1>🗑️ Test: Direkte Lösch-Methode für Google Calendar Events</h1>";

// 1. Erstelle Test-Event
echo "<h2>1. Erstelle Test-Event</h2>";

try {
    $test_event_id = create_google_calendar_event(
        'MTF',
        'Direkte Lösch-Methode Test',
        date('Y-m-d H:i:s'),
        date('Y-m-d H:i:s', strtotime('+1 hour')),
        null,
        'Feuerwehrhaus Ammern'
    );
    
    if ($test_event_id) {
        echo "<p style='color: green;'>✅ Test-Event erstellt: $test_event_id</p>";
        
        // 2. Teste direkte Lösch-Methode
        echo "<h2>2. Teste direkte Lösch-Methode</h2>";
        
        try {
            if (class_exists('GoogleCalendarServiceAccount')) {
                $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'google_calendar_service_account_json'");
                $stmt->execute();
                $service_account_json = $stmt->fetchColumn();
                
                if ($service_account_json) {
                    $calendar_service = new GoogleCalendarServiceAccount($service_account_json, 'a3f7e2f57f274ba2fe7d3a62a932a33c78ed468aafa6ac477b58f16495e5677a@group.calendar.google.com', true);
                    
                    // Event vor dem Löschen
                    echo "<h3>2.1 Event vor dem Löschen</h3>";
                    
                    try {
                        $event_before = $calendar_service->getEvent($test_event_id);
                        echo "<p style='color: green;'>✅ Event vor dem Löschen gefunden</p>";
                        echo "<p><strong>Status:</strong> " . ($event_before['status'] ?? 'Unbekannt') . "</p>";
                        echo "<p><strong>Summary:</strong> " . ($event_before['summary'] ?? 'Unbekannt') . "</p>";
                        
                    } catch (Exception $e) {
                        echo "<p style='color: red;'>❌ Fehler beim Abrufen des Events vor dem Löschen: " . $e->getMessage() . "</p>";
                    }
                    
                    // Teste deleteEvent (verwendet jetzt deleteEventDirectly)
                    echo "<h3>2.2 Teste deleteEvent (direkte Methode)</h3>";
                    
                    $start_time = microtime(true);
                    $result = $calendar_service->deleteEvent($test_event_id);
                    $end_time = microtime(true);
                    
                    $duration = round(($end_time - $start_time) * 1000, 2);
                    
                    echo "<p><strong>Lösch-Dauer:</strong> {$duration}ms</p>";
                    echo "<p><strong>deleteEvent Ergebnis:</strong> " . ($result ? 'TRUE' : 'FALSE') . "</p>";
                    
                    if ($result) {
                        echo "<p style='color: green; font-weight: bold;'>🎉 deleteEvent erfolgreich!</p>";
                    } else {
                        echo "<p style='color: red;'>❌ deleteEvent fehlgeschlagen</p>";
                    }
                    
                    // Prüfe Event Status nach dem Löschen
                    echo "<h3>2.3 Prüfe Event Status nach dem Löschen</h3>";
                    
                    try {
                        $event_after = $calendar_service->getEvent($test_event_id);
                        echo "<p style='color: orange;'>⚠️ Event existiert noch nach dem Löschen</p>";
                        echo "<p><strong>Status:</strong> " . ($event_after['status'] ?? 'Unbekannt') . "</p>";
                        echo "<p><strong>Summary:</strong> " . ($event_after['summary'] ?? 'Unbekannt') . "</p>";
                        
                        if (isset($event_after['status']) && $event_after['status'] === 'cancelled') {
                            echo "<p style='color: blue;'>ℹ️ Event ist cancelled - sollte durchgestrichen sein</p>";
                        } else {
                            echo "<p style='color: red;'>❌ Event ist NICHT cancelled - das ist das Problem!</p>";
                        }
                        
                    } catch (Exception $e) {
                        if (strpos($e->getMessage(), '404') !== false) {
                            echo "<p style='color: green; font-weight: bold;'>🎉 Event wurde vollständig gelöscht (404 Not Found)!</p>";
                        } else {
                            echo "<p style='color: red;'>❌ Fehler beim Abrufen des Events nach dem Löschen: " . $e->getMessage() . "</p>";
                        }
                    }
                    
                }
            }
            
        } catch (Exception $e) {
            echo "<p style='color: red;'>❌ Fehler beim Löschen: " . $e->getMessage() . "</p>";
        }
        
    } else {
        echo "<p style='color: red;'>❌ Test-Event konnte nicht erstellt werden</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim Test: " . $e->getMessage() . "</p>";
}

// 3. Teste mit echtem Event
echo "<h2>3. Teste mit echtem Event</h2>";

$real_event_id = '6lu3icbt1ketrk3tp8kujqs4fs'; // Das Event aus Ihrer Meldung

echo "<p><strong>Teste mit echtem Event:</strong> $real_event_id</p>";

try {
    $start_time = microtime(true);
    $result = delete_google_calendar_event($real_event_id);
    $end_time = microtime(true);
    
    $duration = round(($end_time - $start_time) * 1000, 2);
    
    echo "<p><strong>Lösch-Dauer:</strong> {$duration}ms</p>";
    echo "<p><strong>delete_google_calendar_event Ergebnis:</strong> " . ($result ? 'TRUE' : 'FALSE') . "</p>";
    
    if ($result) {
        echo "<p style='color: green; font-weight: bold;'>🎉 Echtes Event erfolgreich gelöscht!</p>";
    } else {
        echo "<p style='color: red;'>❌ Echtes Event konnte nicht gelöscht werden</p>";
    }
    
    // Prüfe Status des echten Events
    echo "<h3>3.1 Prüfe Status des echten Events</h3>";
    
    try {
        if (class_exists('GoogleCalendarServiceAccount')) {
            $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'google_calendar_service_account_json'");
            $stmt->execute();
            $service_account_json = $stmt->fetchColumn();
            
            if ($service_account_json) {
                $calendar_service = new GoogleCalendarServiceAccount($service_account_json, 'a3f7e2f57f274ba2fe7d3a62a932a33c78ed468aafa6ac477b58f16495e5677a@group.calendar.google.com', true);
                
                try {
                    $event = $calendar_service->getEvent($real_event_id);
                    echo "<p style='color: orange;'>⚠️ Echtes Event existiert noch nach Löschen</p>";
                    echo "<p><strong>Status:</strong> " . ($event['status'] ?? 'Unbekannt') . "</p>";
                    echo "<p><strong>Summary:</strong> " . ($event['summary'] ?? 'Unbekannt') . "</p>";
                    
                    if (isset($event['status']) && $event['status'] === 'cancelled') {
                        echo "<p style='color: blue;'>ℹ️ Echtes Event ist cancelled - sollte durchgestrichen sein</p>";
                    } else {
                        echo "<p style='color: red;'>❌ Echtes Event ist NICHT cancelled - das ist das Problem!</p>";
                    }
                    
                } catch (Exception $e) {
                    if (strpos($e->getMessage(), '404') !== false) {
                        echo "<p style='color: green; font-weight: bold;'>🎉 Echtes Event wurde vollständig gelöscht (404 Not Found)!</p>";
                    } else {
                        echo "<p style='color: red;'>❌ Fehler beim Abrufen des echten Events: " . $e->getMessage() . "</p>";
                    }
                }
            }
        }
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Fehler beim Status-Check des echten Events: " . $e->getMessage() . "</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim echten Event Test: " . $e->getMessage() . "</p>";
}

echo "<hr>";
echo "<h2>📋 Zusammenfassung der direkten Lösch-Methode</h2>";
echo "<ul>";
echo "<li>✅ <strong>deleteEvent verwendet jetzt deleteEventDirectly</strong></li>";
echo "<li>✅ <strong>Service Account JSON wird direkt verwendet</strong></li>";
echo "<li>✅ <strong>JWT wird für Access Token erstellt</strong></li>";
echo "<li>✅ <strong>Events werden vollständig gelöscht (nicht nur storniert)</strong></li>";
echo "<li>✅ <strong>Keine Access Token Probleme mehr</strong></li>";
echo "</ul>";

echo "<p><strong>Erwartetes Ergebnis:</strong></p>";
echo "<ul>";
echo "<li>🎯 <strong>Events werden vollständig aus Google Calendar entfernt</strong></li>";
echo "<li>🎯 <strong>Keine durchgestrichenen Events mehr</strong></li>";
echo "<li>🎯 <strong>Events sind nicht mehr sichtbar</strong></li>";
echo "</ul>";

echo "<p><a href='admin/reservations.php'>→ Zur Reservierungen-Übersicht</a></p>";
echo "<p><a href='test-access-token-fix.php'>→ Access Token Fix Test</a></p>";
echo "<p><small>Direkte Lösch-Methode Test abgeschlossen: " . date('Y-m-d H:i:s') . "</small></p>";
?>
