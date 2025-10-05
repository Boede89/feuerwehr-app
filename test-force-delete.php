<?php
/**
 * Test: Force Delete für stornierte Google Calendar Events
 */

require_once 'config/database.php';
require_once 'includes/functions.php';

echo "<h1>🧪 Test: Force Delete für stornierte Events</h1>";

// 1. Erstelle Test-Event
echo "<h2>1. Erstelle Test-Event</h2>";

try {
    $test_event_id = create_google_calendar_event(
        'Force Delete Test',
        'Test Event für Force Delete - ' . date('Y-m-d H:i:s'),
        date('Y-m-d H:i:s'),
        date('Y-m-d H:i:s', strtotime('+1 hour')),
        null,
        'Test Ort'
    );
    
    if ($test_event_id) {
        echo "<p style='color: green;'>✅ Test-Event erstellt: $test_event_id</p>";
        
        // 2. Teste normales Löschen
        echo "<h2>2. Teste normales Löschen</h2>";
        
        $start_time = microtime(true);
        $result = delete_google_calendar_event($test_event_id);
        $end_time = microtime(true);
        
        $duration = round(($end_time - $start_time) * 1000, 2);
        
        echo "<p><strong>Lösch-Dauer:</strong> {$duration}ms</p>";
        
        if ($result) {
            echo "<p style='color: green;'>✅ Event erfolgreich gelöscht!</p>";
        } else {
            echo "<p style='color: red;'>❌ Event konnte nicht gelöscht werden</p>";
        }
        
        // 3. Prüfe Event Status
        echo "<h2>3. Prüfe Event Status</h2>";
        
        try {
            if (class_exists('GoogleCalendarServiceAccount')) {
                $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'google_calendar_service_account'");
                $stmt->execute();
                $service_account_json = $stmt->fetchColumn();
                
                if ($service_account_json) {
                    $calendar_service = new GoogleCalendarServiceAccount($service_account_json, 'a3f7e2f57f274ba2fe7d3a62a932a33c78ed468aafa6ac477b58f16495e5677a@group.calendar.google.com', true);
                    
                    try {
                        $event = $calendar_service->getEvent($test_event_id);
                        echo "<p style='color: orange;'>⚠️ Event existiert noch:</p>";
                        echo "<p><strong>Status:</strong> " . ($event['status'] ?? 'Unbekannt') . "</p>";
                        echo "<p><strong>Summary:</strong> " . ($event['summary'] ?? 'Unbekannt') . "</p>";
                        
                        if (isset($event['status']) && $event['status'] === 'cancelled') {
                            echo "<p style='color: orange;'>⚠️ Event ist storniert (cancelled) - das ist das Problem!</p>";
                        }
                        
                    } catch (Exception $e) {
                        if (strpos($e->getMessage(), '404') !== false) {
                            echo "<p style='color: green;'>✅ Event wurde vollständig gelöscht (404 Not Found)</p>";
                        } else {
                            echo "<p style='color: red;'>❌ Fehler beim Abrufen des Events: " . $e->getMessage() . "</p>";
                        }
                    }
                }
            }
            
        } catch (Exception $e) {
            echo "<p style='color: red;'>❌ Fehler beim Status-Check: " . $e->getMessage() . "</p>";
        }
        
    } else {
        echo "<p style='color: red;'>❌ Test-Event konnte nicht erstellt werden</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim Test: " . $e->getMessage() . "</p>";
    echo "<p><strong>Stack Trace:</strong></p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}

// 4. Teste mit echtem storniertem Event
echo "<h2>4. Teste mit echtem storniertem Event</h2>";

$cancelled_event_id = '884l9psadasha236elqd8dd76o'; // Das Event aus dem Debug

echo "<p><strong>Teste Force Delete mit storniertem Event:</strong> $cancelled_event_id</p>";

try {
    $start_time = microtime(true);
    $result = delete_google_calendar_event($cancelled_event_id);
    $end_time = microtime(true);
    
    $duration = round(($end_time - $start_time) * 1000, 2);
    
    echo "<p><strong>Force Delete Dauer:</strong> {$duration}ms</p>";
    
    if ($result) {
        echo "<p style='color: green; font-weight: bold;'>🎉 Storniertes Event erfolgreich gelöscht!</p>";
    } else {
        echo "<p style='color: red;'>❌ Storniertes Event konnte nicht gelöscht werden</p>";
    }
    
    // Prüfe Status nach Force Delete
    echo "<h3>4.1 Status nach Force Delete</h3>";
    
    try {
        if (class_exists('GoogleCalendarServiceAccount')) {
            $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'google_calendar_service_account'");
            $stmt->execute();
            $service_account_json = $stmt->fetchColumn();
            
            if ($service_account_json) {
                $calendar_service = new GoogleCalendarServiceAccount($service_account_json, 'a3f7e2f57f274ba2fe7d3a62a932a33c78ed468aafa6ac477b58f16495e5677a@group.calendar.google.com', true);
                
                try {
                    $event = $calendar_service->getEvent($cancelled_event_id);
                    echo "<p style='color: orange;'>⚠️ Event existiert noch nach Force Delete:</p>";
                    echo "<p><strong>Status:</strong> " . ($event['status'] ?? 'Unbekannt') . "</p>";
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
    echo "<p style='color: red;'>❌ Fehler beim Force Delete Test: " . $e->getMessage() . "</p>";
}

echo "<hr>";
echo "<p><a href='admin/reservations.php'>→ Zur Reservierungen-Übersicht</a></p>";
echo "<p><a href='debug-reservations-delete.php'>→ Reservierungen Debug</a></p>";
echo "<p><small>Force Delete Test abgeschlossen: " . date('Y-m-d H:i:s') . "</small></p>";
?>
