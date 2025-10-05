<?php
/**
 * Test: Vergleich zwischen Debug-Skript und Reservierungen-Löschung
 */

require_once 'config/database.php';
require_once 'includes/functions.php';

echo "<h1>🔍 Test: Vergleich zwischen Debug-Skript und Reservierungen-Löschung</h1>";

// 1. Erstelle Test-Event
echo "<h2>1. Erstelle Test-Event</h2>";

try {
    $test_event_id = create_google_calendar_event(
        'MTF',
        'Vergleichs-Test',
        date('Y-m-d H:i:s'),
        date('Y-m-d H:i:s', strtotime('+1 hour')),
        null,
        'Feuerwehrhaus Ammern'
    );
    
    if ($test_event_id) {
        echo "<p style='color: green;'>✅ Test-Event erstellt: $test_event_id</p>";
        
        // 2. Teste Debug-Skript Methode (direkt)
        echo "<h2>2. Teste Debug-Skript Methode (direkt)</h2>";
        
        // Lade Google Calendar Service Account
        $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'google_calendar_service_account_json'");
        $stmt->execute();
        $service_account_json = $stmt->fetchColumn();
        
        $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'google_calendar_id'");
        $stmt->execute();
        $calendar_id = $stmt->fetchColumn();
        
        if ($service_account_json && $calendar_id) {
            $calendar_service = new GoogleCalendarServiceAccount($service_account_json, $calendar_id, true);
            
            echo "<h3>2.1 Event vor dem Löschen prüfen</h3>";
            
            try {
                $event_before = $calendar_service->getEvent($test_event_id);
                echo "<p style='color: green;'>✅ Event vor dem Löschen gefunden</p>";
                echo "<p><strong>Status:</strong> " . ($event_before['status'] ?? 'Unbekannt') . "</p>";
                echo "<p><strong>Summary:</strong> " . ($event_before['summary'] ?? 'Unbekannt') . "</p>";
            } catch (Exception $e) {
                echo "<p style='color: red;'>❌ Fehler beim Abrufen des Events: " . $e->getMessage() . "</p>";
            }
            
            echo "<h3>2.2 Teste Debug-Skript Methode (calendar_service->deleteEvent)</h3>";
            
            $start_time = microtime(true);
            $result_debug = $calendar_service->deleteEvent($test_event_id);
            $end_time = microtime(true);
            
            $duration_debug = round(($end_time - $start_time) * 1000, 2);
            
            echo "<p><strong>Lösch-Dauer:</strong> {$duration_debug}ms</p>";
            echo "<p><strong>deleteEvent Ergebnis:</strong> " . ($result_debug ? 'TRUE' : 'FALSE') . "</p>";
            
            if ($result_debug) {
                echo "<p style='color: green;'>✅ Debug-Skript Methode erfolgreich!</p>";
            } else {
                echo "<p style='color: red;'>❌ Debug-Skript Methode fehlgeschlagen</p>";
            }
            
            // 3. Teste Reservierungen Methode (über Funktion)
            echo "<h2>3. Teste Reservierungen Methode (über delete_google_calendar_event)</h2>";
            
            // Erstelle neues Test-Event für den zweiten Test
            $test_event_id_2 = create_google_calendar_event(
                'MTF',
                'Vergleichs-Test 2',
                date('Y-m-d H:i:s', strtotime('+2 hours')),
                date('Y-m-d H:i:s', strtotime('+3 hours')),
                null,
                'Feuerwehrhaus Ammern'
            );
            
            if ($test_event_id_2) {
                echo "<p style='color: green;'>✅ Zweites Test-Event erstellt: $test_event_id_2</p>";
                
                echo "<h3>3.1 Event vor dem Löschen prüfen</h3>";
                
                try {
                    $event_before_2 = $calendar_service->getEvent($test_event_id_2);
                    echo "<p style='color: green;'>✅ Event vor dem Löschen gefunden</p>";
                    echo "<p><strong>Status:</strong> " . ($event_before_2['status'] ?? 'Unbekannt') . "</p>";
                    echo "<p><strong>Summary:</strong> " . ($event_before_2['summary'] ?? 'Unbekannt') . "</p>";
                } catch (Exception $e) {
                    echo "<p style='color: red;'>❌ Fehler beim Abrufen des Events: " . $e->getMessage() . "</p>";
                }
                
                echo "<h3>3.2 Teste Reservierungen Methode (delete_google_calendar_event)</h3>";
                
                $start_time_2 = microtime(true);
                $result_reservations = delete_google_calendar_event($test_event_id_2);
                $end_time_2 = microtime(true);
                
                $duration_reservations = round(($end_time_2 - $start_time_2) * 1000, 2);
                
                echo "<p><strong>Lösch-Dauer:</strong> {$duration_reservations}ms</p>";
                echo "<p><strong>delete_google_calendar_event Ergebnis:</strong> " . ($result_reservations ? 'TRUE' : 'FALSE') . "</p>";
                
                if ($result_reservations) {
                    echo "<p style='color: green;'>✅ Reservierungen Methode erfolgreich!</p>";
                } else {
                    echo "<p style='color: red;'>❌ Reservierungen Methode fehlgeschlagen</p>";
                }
                
                // 4. Vergleich der Ergebnisse
                echo "<h2>4. Vergleich der Ergebnisse</h2>";
                
                echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
                echo "<tr style='background-color: #f0f0f0;'>";
                echo "<th>Methode</th>";
                echo "<th>Ergebnis</th>";
                echo "<th>Dauer</th>";
                echo "<th>Status</th>";
                echo "</tr>";
                
                echo "<tr>";
                echo "<td>Debug-Skript (calendar_service->deleteEvent)</td>";
                echo "<td>" . ($result_debug ? 'TRUE' : 'FALSE') . "</td>";
                echo "<td>{$duration_debug}ms</td>";
                echo "<td style='color: " . ($result_debug ? 'green' : 'red') . ";'>" . ($result_debug ? '✅ Erfolgreich' : '❌ Fehlgeschlagen') . "</td>";
                echo "</tr>";
                
                echo "<tr>";
                echo "<td>Reservierungen (delete_google_calendar_event)</td>";
                echo "<td>" . ($result_reservations ? 'TRUE' : 'FALSE') . "</td>";
                echo "<td>{$duration_reservations}ms</td>";
                echo "<td style='color: " . ($result_reservations ? 'green' : 'red') . ";'>" . ($result_reservations ? '✅ Erfolgreich' : '❌ Fehlgeschlagen') . "</td>";
                echo "</tr>";
                
                echo "</table>";
                
                if ($result_debug && $result_reservations) {
                    echo "<p style='color: green; font-weight: bold;'>🎉 Beide Methoden funktionieren!</p>";
                } elseif ($result_debug && !$result_reservations) {
                    echo "<p style='color: red; font-weight: bold;'>❌ Nur Debug-Skript funktioniert - Reservierungen Methode hat ein Problem!</p>";
                } elseif (!$result_debug && $result_reservations) {
                    echo "<p style='color: red; font-weight: bold;'>❌ Nur Reservierungen Methode funktioniert - Debug-Skript hat ein Problem!</p>";
                } else {
                    echo "<p style='color: red; font-weight: bold;'>❌ Beide Methoden funktionieren nicht!</p>";
                }
                
            } else {
                echo "<p style='color: red;'>❌ Zweites Test-Event konnte nicht erstellt werden</p>";
            }
            
        } else {
            echo "<p style='color: red;'>❌ Google Calendar Einstellungen nicht gefunden</p>";
        }
        
    } else {
        echo "<p style='color: red;'>❌ Test-Event konnte nicht erstellt werden</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim Test: " . $e->getMessage() . "</p>";
}

echo "<hr>";
echo "<h2>📋 Zusammenfassung</h2>";
echo "<p><strong>Das Problem:</strong> Debug-Skript funktioniert, Reservierungen-Seite nicht</p>";
echo "<p><strong>Mögliche Ursachen:</strong></p>";
echo "<ul>";
echo "<li>❌ <strong>Unterschiedliche Methoden:</strong> Debug-Skript verwendet calendar_service->deleteEvent(), Reservierungen verwendet delete_google_calendar_event()</li>";
echo "<li>❌ <strong>Unterschiedliche Parameter:</strong> Verschiedene Service Account JSON oder Calendar ID</li>";
echo "<li>❌ <strong>Fehler in delete_google_calendar_event:</strong> Funktion funktioniert nicht korrekt</li>";
echo "<li>❌ <strong>Fehler in admin/reservations.php:</strong> Falsche Verwendung der Funktion</li>";
echo "</ul>";

echo "<p><a href='debug-event-ids.php'>→ Event IDs Debug</a></p>";
echo "<p><a href='admin/reservations.php'>→ Reservierungen anzeigen</a></p>";
echo "<p><small>Lösch-Vergleich Test abgeschlossen: " . date('Y-m-d H:i:s') . "</small></p>";
?>
