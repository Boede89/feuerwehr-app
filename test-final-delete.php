<?php
/**
 * Test: Finale Google Calendar Lösch-Logik
 */

require_once 'config/database.php';
require_once 'includes/functions.php';

echo "<h1>🎯 Test: Finale Google Calendar Lösch-Logik</h1>";

// 1. Erstelle Test-Event
echo "<h2>1. Erstelle Test-Event</h2>";

try {
    $test_event_id = create_google_calendar_event(
        'Final Delete Test',
        'Test Event für finale Lösch-Logik - ' . date('Y-m-d H:i:s'),
        date('Y-m-d H:i:s'),
        date('Y-m-d H:i:s', strtotime('+1 hour')),
        null,
        'Test Ort'
    );
    
    if ($test_event_id) {
        echo "<p style='color: green;'>✅ Test-Event erstellt: $test_event_id</p>";
        
        // 2. Teste neue Lösch-Logik
        echo "<h2>2. Teste neue Lösch-Logik</h2>";
        
        $start_time = microtime(true);
        $result = delete_google_calendar_event($test_event_id);
        $end_time = microtime(true);
        
        $duration = round(($end_time - $start_time) * 1000, 2);
        
        echo "<p><strong>Lösch-Dauer:</strong> {$duration}ms</p>";
        
        if ($result) {
            echo "<p style='color: green; font-weight: bold;'>✅ Event erfolgreich gelöscht (neue Logik)!</p>";
        } else {
            echo "<p style='color: red;'>❌ Event konnte nicht gelöscht werden</p>";
        }
        
        // 3. Prüfe Event Status mit neuer Logik
        echo "<h2>3. Prüfe Event Status mit neuer Logik</h2>";
        
        try {
            if (class_exists('GoogleCalendarServiceAccount')) {
                $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'google_calendar_service_account'");
                $stmt->execute();
                $service_account_json = $stmt->fetchColumn();
                
                if ($service_account_json) {
                    $calendar_service = new GoogleCalendarServiceAccount($service_account_json, 'a3f7e2f57f274ba2fe7d3a62a932a33c78ed468aafa6ac477b58f16495e5677a@group.calendar.google.com', true);
                    
                    // Teste isEventDeleted Methode
                    $is_deleted = $calendar_service->isEventDeleted($test_event_id);
                    
                    if ($is_deleted) {
                        echo "<p style='color: green; font-weight: bold;'>🎉 Event wird als gelöscht betrachtet (neue Logik)!</p>";
                    } else {
                        echo "<p style='color: orange;'>⚠️ Event wird noch als aktiv betrachtet</p>";
                    }
                    
                    // Detaillierte Status-Prüfung
                    try {
                        $event = $calendar_service->getEvent($test_event_id);
                        echo "<p><strong>Event Status:</strong> " . ($event['status'] ?? 'Unbekannt') . "</p>";
                        echo "<p><strong>Event Summary:</strong> " . ($event['summary'] ?? 'Unbekannt') . "</p>";
                        
                        if (isset($event['status']) && $event['status'] === 'cancelled') {
                            echo "<p style='color: blue;'>ℹ️ Event ist storniert - das ist OK nach neuer Logik!</p>";
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

echo "<p><strong>Teste neue Logik mit storniertem Event:</strong> $cancelled_event_id</p>";

try {
    if (class_exists('GoogleCalendarServiceAccount')) {
        $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'google_calendar_service_account'");
        $stmt->execute();
        $service_account_json = $stmt->fetchColumn();
        
        if ($service_account_json) {
            $calendar_service = new GoogleCalendarServiceAccount($service_account_json, 'a3f7e2f57f274ba2fe7d3a62a932a33c78ed468aafa6ac477b58f16495e5677a@group.calendar.google.com', true);
            
            // Teste isEventDeleted mit storniertem Event
            $is_deleted = $calendar_service->isEventDeleted($cancelled_event_id);
            
            if ($is_deleted) {
                echo "<p style='color: green; font-weight: bold;'>🎉 Storniertes Event wird als gelöscht betrachtet!</p>";
            } else {
                echo "<p style='color: orange;'>⚠️ Storniertes Event wird noch als aktiv betrachtet</p>";
            }
            
            // Teste forceDeleteEvent mit storniertem Event
            $start_time = microtime(true);
            $result = $calendar_service->forceDeleteEvent($cancelled_event_id);
            $end_time = microtime(true);
            
            $duration = round(($end_time - $start_time) * 1000, 2);
            
            echo "<p><strong>Force Delete Dauer:</strong> {$duration}ms</p>";
            
            if ($result) {
                echo "<p style='color: green; font-weight: bold;'>🎉 Storniertes Event erfolgreich verarbeitet!</p>";
            } else {
                echo "<p style='color: red;'>❌ Storniertes Event konnte nicht verarbeitet werden</p>";
            }
        }
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim stornierten Event Test: " . $e->getMessage() . "</p>";
}

// 5. Teste Konflikt-Prüfung mit stornierten Events
echo "<h2>5. Teste Konflikt-Prüfung mit stornierten Events</h2>";

try {
    $conflicts = check_calendar_conflicts('MTF', date('Y-m-d H:i:s'), date('Y-m-d H:i:s', strtotime('+1 hour')));
    
    echo "<p><strong>Gefundene Konflikte:</strong> " . count($conflicts) . "</p>";
    
    if (!empty($conflicts)) {
        echo "<p style='color: orange;'>⚠️ Konflikte gefunden (sollten stornierte Events ignorieren):</p>";
        foreach ($conflicts as $conflict) {
            echo "<p>- " . htmlspecialchars($conflict['title']) . " (" . $conflict['start'] . " - " . $conflict['end'] . ")</p>";
        }
    } else {
        echo "<p style='color: green;'>✅ Keine aktiven Konflikte gefunden (stornierte Events werden ignoriert)</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler bei Konflikt-Prüfung: " . $e->getMessage() . "</p>";
}

echo "<hr>";
echo "<h2>📋 Zusammenfassung der neuen Logik:</h2>";
echo "<ul>";
echo "<li>✅ <strong>Stornierte Events (cancelled) werden als gelöscht betrachtet</strong></li>";
echo "<li>✅ <strong>Konflikt-Prüfung ignoriert stornierte Events</strong></li>";
echo "<li>✅ <strong>Lösch-Funktion behandelt stornierte Events als Erfolg</strong></li>";
echo "<li>✅ <strong>Detailliertes Logging für bessere Debugging</strong></li>";
echo "</ul>";

echo "<p><a href='admin/reservations.php'>→ Zur Reservierungen-Übersicht</a></p>";
echo "<p><a href='debug-reservations-delete.php'>→ Reservierungen Debug</a></p>";
echo "<p><small>Finale Lösch-Logik Test abgeschlossen: " . date('Y-m-d H:i:s') . "</small></p>";
?>
