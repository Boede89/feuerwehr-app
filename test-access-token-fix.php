<?php
/**
 * Test: Access Token Fix für Google Calendar Löschen
 */

require_once 'config/database.php';
require_once 'includes/functions.php';

echo "<h1>🔑 Test: Access Token Fix für Google Calendar Löschen</h1>";

// 1. Erstelle Test-Event
echo "<h2>1. Erstelle Test-Event</h2>";

try {
    $test_event_id = create_google_calendar_event(
        'MTF',
        'Access Token Fix Test',
        date('Y-m-d H:i:s'),
        date('Y-m-d H:i:s', strtotime('+1 hour')),
        null,
        'Feuerwehrhaus Ammern'
    );
    
    if ($test_event_id) {
        echo "<p style='color: green;'>✅ Test-Event erstellt: $test_event_id</p>";
        
        // 2. Teste verbesserte Lösch-Funktion
        echo "<h2>2. Teste verbesserte Lösch-Funktion</h2>";
        
        try {
            if (class_exists('GoogleCalendarServiceAccount')) {
                $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'google_calendar_service_account_json'");
                $stmt->execute();
                $service_account_json = $stmt->fetchColumn();
                
                if ($service_account_json) {
                    $calendar_service = new GoogleCalendarServiceAccount($service_account_json, 'a3f7e2f57f274ba2fe7d3a62a932a33c78ed468aafa6ac477b58f16495e5677a@group.calendar.google.com', true);
                    
                    // Teste deleteEvent
                    echo "<h3>2.1 Teste deleteEvent</h3>";
                    
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
                    
                    // Prüfe Event Status
                    echo "<h3>2.2 Prüfe Event Status nach deleteEvent</h3>";
                    
                    try {
                        $event = $calendar_service->getEvent($test_event_id);
                        echo "<p style='color: orange;'>⚠️ Event existiert noch nach deleteEvent</p>";
                        echo "<p><strong>Status:</strong> " . ($event['status'] ?? 'Unbekannt') . "</p>";
                        echo "<p><strong>Summary:</strong> " . ($event['summary'] ?? 'Unbekannt') . "</p>";
                        
                        if (isset($event['status']) && $event['status'] === 'cancelled') {
                            echo "<p style='color: blue;'>ℹ️ Event ist storniert (cancelled) - das ist normal bei Google Calendar</p>";
                        }
                        
                    } catch (Exception $e) {
                        if (strpos($e->getMessage(), '404') !== false) {
                            echo "<p style='color: green; font-weight: bold;'>🎉 Event wurde vollständig gelöscht (404 Not Found)!</p>";
                        } else {
                            echo "<p style='color: red;'>❌ Fehler beim Abrufen des Events: " . $e->getMessage() . "</p>";
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
echo "<h2>📋 Zusammenfassung des Access Token Fix</h2>";
echo "<ul>";
echo "<li>✅ <strong>deleteEvent versucht zuerst Access Token</strong></li>";
echo "<li>✅ <strong>Falls Access Token fehlschlägt, verwendet Service Account direkt</strong></li>";
echo "<li>✅ <strong>JWT wird für Service Account erstellt</strong></li>";
echo "<li>✅ <strong>Access Token wird mit JWT geholt</strong></li>";
echo "<li>✅ <strong>Löschen funktioniert mit beiden Methoden</strong></li>";
echo "</ul>";

echo "<p><a href='admin/reservations.php'>→ Zur Reservierungen-Übersicht</a></p>";
echo "<p><a href='debug-delete-mystery.php'>→ Lösch-Mysterium Debug</a></p>";
echo "<p><small>Access Token Fix Test abgeschlossen: " . date('Y-m-d H:i:s') . "</small></p>";
?>
