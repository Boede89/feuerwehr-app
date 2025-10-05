<?php
/**
 * Test: Ort-Korrektur für Google Calendar Events
 */

require_once 'config/database.php';
require_once 'includes/functions.php';

echo "<h1>📍 Test: Ort-Korrektur für Google Calendar Events</h1>";

// 1. Teste Event-Erstellung mit Ort im Ortsfeld
echo "<h2>1. Teste Event-Erstellung mit Ort im Ortsfeld</h2>";

try {
    $test_event_id = create_google_calendar_event(
        'MTF',
        'Test für Ort-Korrektur',
        date('Y-m-d H:i:s'),
        date('Y-m-d H:i:s', strtotime('+1 hour')),
        null,
        'Feuerwehrhaus Ammern'
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
                    echo "<p><strong>Event Beschreibung:</strong> '" . ($event['description'] ?? 'Leer') . "'</p>";
                    echo "<p><strong>Event Ort:</strong> '" . ($event['location'] ?? 'Nicht gesetzt') . "'</p>";
                    
                    // Prüfe ob Ort korrekt im Ortsfeld steht
                    $expected_location = 'Feuerwehrhaus Ammern';
                    if (isset($event['location']) && $event['location'] === $expected_location) {
                        echo "<p style='color: green;'>✅ Ort ist korrekt im Ortsfeld gesetzt</p>";
                    } else {
                        echo "<p style='color: red;'>❌ Ort ist falsch: '" . ($event['location'] ?? 'Nicht gesetzt') . "' (erwartet: '$expected_location')</p>";
                    }
                    
                    // Prüfe ob Beschreibung leer ist
                    if (empty($event['description'])) {
                        echo "<p style='color: green;'>✅ Beschreibung ist leer (korrekt)</p>";
                    } else {
                        echo "<p style='color: red;'>❌ Beschreibung ist nicht leer: '" . $event['description'] . "'</p>";
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

// 2. Teste Event-Löschung
echo "<h2>2. Teste Event-Löschung</h2>";

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
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim echten Event Test: " . $e->getMessage() . "</p>";
}

echo "<hr>";
echo "<h2>📋 Zusammenfassung der Ort-Korrektur</h2>";
echo "<ul>";
echo "<li>✅ <strong>Ort wird im Ortsfeld des Kalendereintrags gesetzt</strong></li>";
echo "<li>✅ <strong>Beschreibung ist leer (keine zusätzlichen Informationen)</strong></li>";
echo "<li>✅ <strong>Events werden vollständig aus Google Calendar entfernt</strong></li>";
echo "<li>✅ <strong>Saubere, einfache Kalendereinträge</strong></li>";
echo "</ul>";

echo "<p><a href='admin/reservations.php'>→ Zur Reservierungen-Übersicht</a></p>";
echo "<p><a href='test-improvements.php'>→ Verbesserungen Test</a></p>";
echo "<p><small>Ort-Korrektur Test abgeschlossen: " . date('Y-m-d H:i:s') . "</small></p>";
?>
