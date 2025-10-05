<?php
/**
 * Test: Erweiterte Lösch-Methoden für Google Calendar Events
 */

require_once 'config/database.php';
require_once 'includes/functions.php';

echo "<h1>🔧 Test: Erweiterte Lösch-Methoden für Google Calendar Events</h1>";

// 1. Erstelle Test-Event
echo "<h2>1. Erstelle Test-Event</h2>";

try {
    $test_event_id = create_google_calendar_event(
        'MTF',
        'Erweiterte Lösch-Methoden Test',
        date('Y-m-d H:i:s'),
        date('Y-m-d H:i:s', strtotime('+1 hour')),
        null,
        'Feuerwehrhaus Ammern'
    );
    
    if ($test_event_id) {
        echo "<p style='color: green;'>✅ Test-Event erstellt: $test_event_id</p>";
        
        // 2. Teste erweiterte Lösch-Methoden
        echo "<h2>2. Teste erweiterte Lösch-Methoden</h2>";
        
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
            
            echo "<h3>2.2 Teste erweiterte Lösch-Methode</h3>";
            
            $start_time = microtime(true);
            $result = $calendar_service->deleteEvent($test_event_id);
            $end_time = microtime(true);
            
            $duration = round(($end_time - $start_time) * 1000, 2);
            
            echo "<p><strong>Lösch-Dauer:</strong> {$duration}ms</p>";
            echo "<p><strong>deleteEvent Ergebnis:</strong> " . ($result ? 'TRUE' : 'FALSE') . "</p>";
            
            if ($result) {
                echo "<p style='color: green; font-weight: bold;'>🎉 Erweiterte Lösch-Methode erfolgreich!</p>";
                
                // 3. Prüfe Event Status nach dem Löschen
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
                        echo "<p style='color: red;'>❌ Fehler beim Abrufen des Events: " . $e->getMessage() . "</p>";
                    }
                }
                
            } else {
                echo "<p style='color: red;'>❌ Erweiterte Lösch-Methode fehlgeschlagen</p>";
            }
            
        } else {
            echo "<p style='color: red;'>❌ Google Calendar Einstellungen nicht gefunden</p>";
        }
        
    } else {
        echo "<p style='color: red;'>❌ Test-Event konnte nicht erstellt werden</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim Erstellen des Test-Events: " . $e->getMessage() . "</p>";
}

// 4. Teste mit echtem Event
echo "<h2>3. Teste mit echtem Event aus Reservierungen</h2>";

try {
    $stmt = $db->prepare("
        SELECT r.id, r.vehicle_id, v.name as vehicle_name, r.reason, ce.google_event_id 
        FROM reservations r 
        LEFT JOIN vehicles v ON r.vehicle_id = v.id 
        LEFT JOIN calendar_events ce ON r.id = ce.reservation_id 
        WHERE r.status = 'approved' AND ce.google_event_id IS NOT NULL 
        LIMIT 1
    ");
    $stmt->execute();
    $real_reservation = $stmt->fetch();
    
    if ($real_reservation) {
        echo "<p style='color: green;'>✅ Echtes Event gefunden</p>";
        echo "<p><strong>Reservierung ID:</strong> " . $real_reservation['id'] . "</p>";
        echo "<p><strong>Fahrzeug:</strong> " . $real_reservation['vehicle_name'] . "</p>";
        echo "<p><strong>Grund:</strong> " . $real_reservation['reason'] . "</p>";
        echo "<p><strong>Google Event ID:</strong> " . $real_reservation['google_event_id'] . "</p>";
        
        echo "<h3>3.1 Teste Löschen des echten Events</h3>";
        
        $start_time = microtime(true);
        $result = delete_google_calendar_event($real_reservation['google_event_id']);
        $end_time = microtime(true);
        
        $duration = round(($end_time - $start_time) * 1000, 2);
        
        echo "<p><strong>Lösch-Dauer:</strong> {$duration}ms</p>";
        echo "<p><strong>delete_google_calendar_event Ergebnis:</strong> " . ($result ? 'TRUE' : 'FALSE') . "</p>";
        
        if ($result) {
            echo "<p style='color: green; font-weight: bold;'>🎉 Echtes Event erfolgreich gelöscht!</p>";
            
            // Prüfe Event Status
            echo "<h3>3.2 Prüfe Status des echten Events</h3>";
            
            try {
                $event_after = $calendar_service->getEvent($real_reservation['google_event_id']);
                echo "<p style='color: orange;'>⚠️ Echtes Event existiert noch nach dem Löschen</p>";
                echo "<p><strong>Status:</strong> " . ($event_after['status'] ?? 'Unbekannt') . "</p>";
                echo "<p><strong>Summary:</strong> " . ($event_after['summary'] ?? 'Unbekannt') . "</p>";
                
                if (isset($event_after['status']) && $event_after['status'] === 'cancelled') {
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
            
        } else {
            echo "<p style='color: red;'>❌ Löschen des echten Events fehlgeschlagen</p>";
        }
        
    } else {
        echo "<p style='color: orange;'>⚠️ Kein genehmigtes Event mit Google Event ID gefunden</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim Test mit echtem Event: " . $e->getMessage() . "</p>";
}

echo "<hr>";
echo "<h2>📋 Zusammenfassung der erweiterten Lösch-Methoden</h2>";
echo "<ul>";
echo "<li>✅ <strong>Methode 1:</strong> Standard DELETE - Standard Google Calendar API Löschung</li>";
echo "<li>✅ <strong>Methode 2:</strong> DELETE mit showDeleted=false - Versucht Events vollständig zu entfernen</li>";
echo "<li>✅ <strong>Methode 3:</strong> Stornierung + Löschung - Event zuerst stornieren, dann löschen</li>";
echo "<li>✅ <strong>Mehrfache Versuche:</strong> Alle Methoden werden nacheinander versucht</li>";
echo "<li>✅ <strong>Detailliertes Logging:</strong> Jede Methode wird einzeln geloggt</li>";
echo "</ul>";

echo "<p><strong>Erwartetes Ergebnis:</strong></p>";
echo "<ul>";
echo "<li>🎯 <strong>Events werden vollständig gelöscht</strong> - Nicht nur storniert</li>";
echo "<li>🎯 <strong>Keine durchgestrichenen Events</strong> - Events verschwinden komplett</li>";
echo "<li>🎯 <strong>Google Calendar bleibt sauber</strong> - Keine sichtbaren Events mehr</li>";
echo "</ul>";

echo "<p><a href='test-new-json.php'>→ Neuer JSON Code Test</a></p>";
echo "<p><a href='admin/reservations.php'>→ Reservierungen-Übersicht</a></p>";
echo "<p><small>Erweiterte Lösch-Methoden Test abgeschlossen: " . date('Y-m-d H:i:s') . "</small></p>";
?>
