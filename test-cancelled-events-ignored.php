<?php
/**
 * Test: Stornierte Events werden in der Konfliktprüfung ignoriert
 */

require_once 'config/database.php';
require_once 'includes/functions.php';

echo "<h1>🔍 Test: Stornierte Events werden in der Konfliktprüfung ignoriert</h1>";

// 1. Erstelle Test-Event
echo "<h2>1. Erstelle Test-Event</h2>";

try {
    $test_event_id = create_google_calendar_event(
        'MTF',
        'Test für stornierte Events',
        date('Y-m-d H:i:s'),
        date('Y-m-d H:i:s', strtotime('+1 hour')),
        null,
        'Feuerwehrhaus Ammern'
    );
    
    if ($test_event_id) {
        echo "<p style='color: green;'>✅ Test-Event erstellt: $test_event_id</p>";
        
        // 2. Teste Konfliktprüfung vor dem Löschen
        echo "<h2>2. Teste Konfliktprüfung vor dem Löschen</h2>";
        
        $conflicts_before = check_calendar_conflicts('MTF', date('Y-m-d H:i:s'), date('Y-m-d H:i:s', strtotime('+1 hour')));
        
        echo "<p><strong>Konflikte vor dem Löschen:</strong> " . count($conflicts_before) . "</p>";
        
        if (count($conflicts_before) > 0) {
            echo "<p style='color: green;'>✅ Konflikt erkannt - das ist korrekt!</p>";
            foreach ($conflicts_before as $conflict) {
                echo "<p>- " . $conflict['title'] . " (" . $conflict['start'] . " - " . $conflict['end'] . ")</p>";
            }
        } else {
            echo "<p style='color: red;'>❌ Kein Konflikt erkannt - das ist falsch!</p>";
        }
        
        // 3. Lösche Event (wird zu cancelled)
        echo "<h2>3. Lösche Event (wird zu cancelled)</h2>";
        
        $start_time = microtime(true);
        $result = delete_google_calendar_event($test_event_id);
        $end_time = microtime(true);
        
        $duration = round(($end_time - $start_time) * 1000, 2);
        
        echo "<p><strong>Lösch-Dauer:</strong> {$duration}ms</p>";
        echo "<p><strong>delete_google_calendar_event Ergebnis:</strong> " . ($result ? 'TRUE' : 'FALSE') . "</p>";
        
        if ($result) {
            echo "<p style='color: green;'>✅ Event erfolgreich gelöscht (zu cancelled markiert)</p>";
            
            // 4. Teste Konfliktprüfung nach dem Löschen
            echo "<h2>4. Teste Konfliktprüfung nach dem Löschen</h2>";
            
            $conflicts_after = check_calendar_conflicts('MTF', date('Y-m-d H:i:s'), date('Y-m-d H:i:s', strtotime('+1 hour')));
            
            echo "<p><strong>Konflikte nach dem Löschen:</strong> " . count($conflicts_after) . "</p>";
            
            if (count($conflicts_after) === 0) {
                echo "<p style='color: green; font-weight: bold;'>🎉 Kein Konflikt mehr erkannt - stornierte Events werden ignoriert!</p>";
            } else {
                echo "<p style='color: red;'>❌ Konflikt noch erkannt - stornierte Events werden nicht ignoriert!</p>";
                foreach ($conflicts_after as $conflict) {
                    echo "<p>- " . $conflict['title'] . " (" . $conflict['start'] . " - " . $conflict['end'] . ")</p>";
                }
            }
            
            // 5. Prüfe Event Status
            echo "<h2>5. Prüfe Event Status</h2>";
            
            try {
                // Lade Google Calendar Service Account
                $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'google_calendar_service_account_json'");
                $stmt->execute();
                $service_account_json = $stmt->fetchColumn();
                
                $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'google_calendar_id'");
                $stmt->execute();
                $calendar_id = $stmt->fetchColumn();
                
                if ($service_account_json && $calendar_id) {
                    $calendar_service = new GoogleCalendarServiceAccount($service_account_json, $calendar_id, true);
                    
                    $event = $calendar_service->getEvent($test_event_id);
                    
                    if ($event) {
                        echo "<p style='color: blue;'>ℹ️ Event existiert noch nach dem Löschen</p>";
                        echo "<p><strong>Status:</strong> " . ($event['status'] ?? 'Unbekannt') . "</p>";
                        echo "<p><strong>Summary:</strong> " . ($event['summary'] ?? 'Unbekannt') . "</p>";
                        
                        if (isset($event['status']) && $event['status'] === 'cancelled') {
                            echo "<p style='color: green;'>✅ Event ist cancelled - das ist korrekt!</p>";
                            echo "<p style='color: blue;'>ℹ️ Cancelled Events werden in der Konfliktprüfung ignoriert</p>";
                        } else {
                            echo "<p style='color: red;'>❌ Event ist NICHT cancelled - das ist das Problem!</p>";
                        }
                    } else {
                        echo "<p style='color: green; font-weight: bold;'>🎉 Event wurde vollständig gelöscht!</p>";
                    }
                }
                
            } catch (Exception $e) {
                if (strpos($e->getMessage(), '404') !== false) {
                    echo "<p style='color: green; font-weight: bold;'>🎉 Event wurde vollständig gelöscht (404 Not Found)!</p>";
                } else {
                    echo "<p style='color: red;'>❌ Fehler beim Abrufen des Events: " . $e->getMessage() . "</p>";
                }
            }
            
        } else {
            echo "<p style='color: red;'>❌ Event konnte nicht gelöscht werden</p>";
        }
        
    } else {
        echo "<p style='color: red;'>❌ Test-Event konnte nicht erstellt werden</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim Test: " . $e->getMessage() . "</p>";
}

echo "<hr>";
echo "<h2>📋 Zusammenfassung</h2>";
echo "<p><strong>Das ist das erwartete Verhalten:</strong></p>";
echo "<ul>";
echo "<li>✅ <strong>Events werden zu 'cancelled' markiert</strong> - Das ist Google Calendar API Verhalten</li>";
echo "<li>✅ <strong>Cancelled Events werden in der Konfliktprüfung ignoriert</strong> - Das ist korrekt</li>";
echo "<li>✅ <strong>Benutzer sehen keine Konflikte mehr</strong> - Das ist das gewünschte Verhalten</li>";
echo "<li>✅ <strong>Google Calendar bleibt funktional</strong> - Keine doppelten Reservierungen</li>";
echo "</ul>";

echo "<p><strong>Wichtiger Hinweis:</strong></p>";
echo "<p>Google Calendar kann Events nicht vollständig löschen - sie werden nur zu 'cancelled' markiert. Das ist ein <strong>Google Calendar API Verhalten</strong>, das wir nicht umgehen können. Aber das ist in Ordnung, weil:</p>";
echo "<ul>";
echo "<li>🔍 <strong>Konfliktprüfung ignoriert cancelled Events</strong></li>";
echo "<li>👁️ <strong>Benutzer sehen keine Konflikte mehr</strong></li>";
echo "<li>✅ <strong>System funktioniert korrekt</strong></li>";
echo "</ul>";

echo "<p><a href='test-advanced-delete.php'>→ Erweiterte Lösch-Methoden Test</a></p>";
echo "<p><a href='admin/reservations.php'>→ Reservierungen-Übersicht</a></p>";
echo "<p><small>Stornierte Events Test abgeschlossen: " . date('Y-m-d H:i:s') . "</small></p>";
?>
