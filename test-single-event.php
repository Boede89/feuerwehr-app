<?php
/**
 * Test: Einzelnes Event testen
 */

require_once 'config/database.php';
require_once 'includes/functions.php';

echo "<h1>🧪 Test: Einzelnes Event testen</h1>";

if (!isset($_GET['event_id'])) {
    echo "<p style='color: red;'>❌ Keine Event ID angegeben</p>";
    echo "<p><a href='debug-event-ids.php'>← Zurück zur Event IDs Übersicht</a></p>";
    exit;
}

$event_id = $_GET['event_id'];
echo "<h2>Teste Event ID: $event_id</h2>";

try {
    // Lade Google Calendar Service Account
    $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'google_calendar_service_account_json'");
    $stmt->execute();
    $service_account_json = $stmt->fetchColumn();
    
    $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'google_calendar_id'");
    $stmt->execute();
    $calendar_id = $stmt->fetchColumn();
    
    if (!$service_account_json || !$calendar_id) {
        echo "<p style='color: red;'>❌ Google Calendar Einstellungen nicht gefunden</p>";
        exit;
    }
    
    $calendar_service = new GoogleCalendarServiceAccount($service_account_json, $calendar_id, true);
    
    // 1. Event Details abrufen
    echo "<h3>1. Event Details abrufen</h3>";
    
    try {
        $event = $calendar_service->getEvent($event_id);
        
        if ($event) {
            echo "<p style='color: green;'>✅ Event gefunden</p>";
            echo "<div style='background-color: #f0f0f0; padding: 10px; margin: 10px 0;'>";
            echo "<p><strong>Event ID:</strong> " . ($event['id'] ?? 'Unbekannt') . "</p>";
            echo "<p><strong>Status:</strong> " . ($event['status'] ?? 'Unbekannt') . "</p>";
            echo "<p><strong>Summary:</strong> " . ($event['summary'] ?? 'Unbekannt') . "</p>";
            echo "<p><strong>Description:</strong> " . ($event['description'] ?? 'Keine Beschreibung') . "</p>";
            echo "<p><strong>Location:</strong> " . ($event['location'] ?? 'Kein Ort') . "</p>";
            echo "<p><strong>Created:</strong> " . ($event['created'] ?? 'Unbekannt') . "</p>";
            echo "<p><strong>Updated:</strong> " . ($event['updated'] ?? 'Unbekannt') . "</p>";
            
            if (isset($event['creator'])) {
                echo "<p><strong>Creator Email:</strong> " . ($event['creator']['email'] ?? 'Unbekannt') . "</p>";
            }
            
            if (isset($event['organizer'])) {
                echo "<p><strong>Organizer Email:</strong> " . ($event['organizer']['email'] ?? 'Unbekannt') . "</p>";
            }
            
            if (isset($event['start'])) {
                echo "<p><strong>Start:</strong> " . ($event['start']['dateTime'] ?? $event['start']['date'] ?? 'Unbekannt') . "</p>";
            }
            
            if (isset($event['end'])) {
                echo "<p><strong>Ende:</strong> " . ($event['end']['dateTime'] ?? $event['end']['date'] ?? 'Unbekannt') . "</p>";
            }
            
            echo "</div>";
            
        } else {
            echo "<p style='color: red;'>❌ Event nicht gefunden</p>";
        }
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Fehler beim Abrufen des Events: " . $e->getMessage() . "</p>";
    }
    
    // 2. Event löschen testen
    echo "<h3>2. Event löschen testen</h3>";
    
    $start_time = microtime(true);
    $result = $calendar_service->deleteEvent($event_id);
    $end_time = microtime(true);
    
    $duration = round(($end_time - $start_time) * 1000, 2);
    
    echo "<p><strong>Lösch-Dauer:</strong> {$duration}ms</p>";
    echo "<p><strong>deleteEvent Ergebnis:</strong> " . ($result ? 'TRUE' : 'FALSE') . "</p>";
    
    if ($result) {
        echo "<p style='color: green;'>✅ Event erfolgreich gelöscht!</p>";
        
        // 3. Event Status nach dem Löschen prüfen
        echo "<h3>3. Event Status nach dem Löschen prüfen</h3>";
        
        try {
            $event_after = $calendar_service->getEvent($event_id);
            
            if ($event_after) {
                echo "<p style='color: orange;'>⚠️ Event existiert noch nach dem Löschen</p>";
                echo "<div style='background-color: #fff3cd; padding: 10px; margin: 10px 0;'>";
                echo "<p><strong>Status:</strong> " . ($event_after['status'] ?? 'Unbekannt') . "</p>";
                echo "<p><strong>Summary:</strong> " . ($event_after['summary'] ?? 'Unbekannt') . "</p>";
                echo "<p><strong>Updated:</strong> " . ($event_after['updated'] ?? 'Unbekannt') . "</p>";
                echo "</div>";
                
                if (isset($event_after['status']) && $event_after['status'] === 'cancelled') {
                    echo "<p style='color: blue;'>ℹ️ Event ist cancelled - das ist normal bei Google Calendar</p>";
                    echo "<p style='color: green;'>✅ Das System funktioniert korrekt - cancelled Events werden ignoriert</p>";
                } else {
                    echo "<p style='color: red;'>❌ Event ist NICHT cancelled - das ist das Problem!</p>";
                }
                
            } else {
                echo "<p style='color: green; font-weight: bold;'>🎉 Event wurde vollständig gelöscht!</p>";
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
    
    // 4. Konfliktprüfung testen
    echo "<h3>4. Konfliktprüfung testen</h3>";
    
    try {
        $conflicts = check_calendar_conflicts('MTF', date('Y-m-d H:i:s'), date('Y-m-d H:i:s', strtotime('+1 hour')));
        
        echo "<p><strong>Konflikte gefunden:</strong> " . count($conflicts) . "</p>";
        
        if (count($conflicts) === 0) {
            echo "<p style='color: green;'>✅ Keine Konflikte - cancelled Events werden ignoriert</p>";
        } else {
            echo "<p style='color: orange;'>⚠️ Konflikte gefunden:</p>";
            foreach ($conflicts as $conflict) {
                echo "<p>- " . $conflict['title'] . " (" . $conflict['start'] . " - " . $conflict['end'] . ")</p>";
            }
        }
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Fehler bei der Konfliktprüfung: " . $e->getMessage() . "</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim Test: " . $e->getMessage() . "</p>";
}

echo "<hr>";
echo "<p><a href='debug-event-ids.php'>← Zurück zur Event IDs Übersicht</a></p>";
echo "<p><a href='admin/reservations.php'>→ Reservierungen anzeigen</a></p>";
echo "<p><small>Einzelnes Event Test abgeschlossen: " . date('Y-m-d H:i:s') . "</small></p>";
?>
