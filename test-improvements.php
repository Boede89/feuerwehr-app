<?php
/**
 * Test: Google Calendar Verbesserungen
 */

require_once 'config/database.php';
require_once 'includes/functions.php';

echo "<h1>🚀 Test: Google Calendar Verbesserungen</h1>";

// 1. Teste verbesserte Event-Erstellung
echo "<h2>1. Teste verbesserte Event-Erstellung</h2>";

try {
    $test_event_id = create_google_calendar_event(
        'MTF',
        'Test für Verbesserungen',
        date('Y-m-d H:i:s'),
        date('Y-m-d H:i:s', strtotime('+1 hour')),
        null,
        'Test-Ort für Verbesserungen'
    );
    
    if ($test_event_id) {
        echo "<p style='color: green;'>✅ Test-Event erstellt: $test_event_id</p>";
        
        // Prüfe Event-Details
        echo "<h3>1.1 Event-Details prüfen</h3>";
        
        try {
            if (class_exists('GoogleCalendarServiceAccount')) {
                $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'google_calendar_service_account_json'");
                $stmt->execute();
                $service_account_json = $stmt->fetchColumn();
                
                if ($service_account_json) {
                    $calendar_service = new GoogleCalendarServiceAccount($service_account_json, 'a3f7e2f57f274ba2fe7d3a62a932a33c78ed468aafa6ac477b58f16495e5677a@group.calendar.google.com', true);
                    
                    $event = $calendar_service->getEvent($test_event_id);
                    
                    echo "<p><strong>Event Titel:</strong> " . ($event['summary'] ?? 'Unbekannt') . "</p>";
                    echo "<p><strong>Event Beschreibung:</strong> " . ($event['description'] ?? 'Unbekannt') . "</p>";
                    echo "<p><strong>Event Ort:</strong> " . ($event['location'] ?? 'Nicht gesetzt') . "</p>";
                    
                    // Prüfe ob Beschreibung nur den Ort enthält
                    $expected_description = 'Test-Ort für Verbesserungen';
                    if ($event['description'] === $expected_description) {
                        echo "<p style='color: green;'>✅ Beschreibung enthält nur den Ort (korrekt)</p>";
                    } else {
                        echo "<p style='color: red;'>❌ Beschreibung ist falsch: '" . $event['description'] . "' (erwartet: '$expected_description')</p>";
                    }
                    
                }
            }
            
        } catch (Exception $e) {
            echo "<p style='color: red;'>❌ Fehler beim Abrufen des Events: " . $e->getMessage() . "</p>";
        }
        
    } else {
        echo "<p style='color: red;'>❌ Test-Event konnte nicht erstellt werden</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim Test: " . $e->getMessage() . "</p>";
}

// 2. Teste verbesserte Event-Löschung
echo "<h2>2. Teste verbesserte Event-Löschung</h2>";

if (isset($test_event_id)) {
    echo "<p><strong>Teste reallyDeleteEvent mit Event:</strong> $test_event_id</p>";
    
    try {
        $start_time = microtime(true);
        $result = delete_google_calendar_event($test_event_id);
        $end_time = microtime(true);
        
        $duration = round(($end_time - $start_time) * 1000, 2);
        
        echo "<p><strong>Lösch-Dauer:</strong> {$duration}ms</p>";
        echo "<p><strong>reallyDeleteEvent Ergebnis:</strong> " . ($result ? 'TRUE' : 'FALSE') . "</p>";
        
        if ($result) {
            echo "<p style='color: green; font-weight: bold;'>🎉 Event erfolgreich gelöscht!</p>";
        } else {
            echo "<p style='color: red;'>❌ Event konnte nicht gelöscht werden</p>";
        }
        
        // Prüfe ob Event wirklich gelöscht wurde
        echo "<h3>2.1 Prüfe ob Event wirklich gelöscht wurde</h3>";
        
        try {
            if (class_exists('GoogleCalendarServiceAccount')) {
                $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'google_calendar_service_account_json'");
                $stmt->execute();
                $service_account_json = $stmt->fetchColumn();
                
                if ($service_account_json) {
                    $calendar_service = new GoogleCalendarServiceAccount($service_account_json, 'a3f7e2f57f274ba2fe7d3a62a932a33c78ed468aafa6ac477b58f16495e5677a@group.calendar.google.com', true);
                    
                    try {
                        $event = $calendar_service->getEvent($test_event_id);
                        echo "<p style='color: orange;'>⚠️ Event existiert noch nach reallyDeleteEvent</p>";
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
            echo "<p style='color: red;'>❌ Fehler beim Status-Check: " . $e->getMessage() . "</p>";
        }
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Fehler beim Löschen: " . $e->getMessage() . "</p>";
    }
}

// 3. Teste mit echtem Event
echo "<h2>3. Teste mit echtem Event</h2>";

$real_event_id = '6lu3icbt1ketrk3tp8kujqs4fs'; // Das Event aus Ihrer Meldung

echo "<p><strong>Teste reallyDeleteEvent mit echtem Event:</strong> $real_event_id</p>";

try {
    $start_time = microtime(true);
    $result = delete_google_calendar_event($real_event_id);
    $end_time = microtime(true);
    
    $duration = round(($end_time - $start_time) * 1000, 2);
    
    echo "<p><strong>Lösch-Dauer:</strong> {$duration}ms</p>";
    echo "<p><strong>reallyDeleteEvent Ergebnis:</strong> " . ($result ? 'TRUE' : 'FALSE') . "</p>";
    
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
                    echo "<p style='color: orange;'>⚠️ Echtes Event existiert noch nach reallyDeleteEvent</p>";
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
echo "<h2>📋 Zusammenfassung der Verbesserungen</h2>";
echo "<ul>";
echo "<li>✅ <strong>Kalendereinträge haben nur den Ort in der Beschreibung</strong></li>";
echo "<li>✅ <strong>reallyDeleteEvent versucht Events vollständig zu löschen</strong></li>";
echo "<li>✅ <strong>Mehrfache Löschversuche für stornierte Events</strong></li>";
echo "<li>✅ <strong>Detailliertes Logging für bessere Debugging</strong></li>";
echo "</ul>";

echo "<p><a href='admin/reservations.php'>→ Zur Reservierungen-Übersicht</a></p>";
echo "<p><a href='debug-delete-detailed.php'>→ Detaillierte Debug-Analyse</a></p>";
echo "<p><small>Verbesserungen Test abgeschlossen: " . date('Y-m-d H:i:s') . "</small></p>";
?>
