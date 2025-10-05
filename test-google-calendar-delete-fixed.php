<?php
/**
 * Test: Google Calendar Löschen nach Fix
 */

require_once 'config/database.php';
require_once 'includes/functions.php';

echo "<h1>🧪 Test: Google Calendar Löschen nach Fix</h1>";

// 1. Teste delete_google_calendar_event Funktion
echo "<h2>1. delete_google_calendar_event Funktion testen</h2>";

if (function_exists('delete_google_calendar_event')) {
    echo "<p style='color: green;'>✅ delete_google_calendar_event Funktion verfügbar</p>";
    
    // Hole eine echte Event ID aus der Datenbank
    try {
        $stmt = $db->prepare("SELECT google_event_id FROM calendar_events WHERE google_event_id IS NOT NULL LIMIT 1");
        $stmt->execute();
        $event = $stmt->fetch();
        
        if ($event && !empty($event['google_event_id'])) {
            $event_id = $event['google_event_id'];
            echo "<p><strong>Teste mit echter Event ID:</strong> $event_id</p>";
            
            echo "<p>Starte Lösch-Test...</p>";
            $start_time = microtime(true);
            $result = delete_google_calendar_event($event_id);
            $end_time = microtime(true);
            
            $duration = round(($end_time - $start_time) * 1000, 2);
            
            echo "<p><strong>Dauer:</strong> {$duration}ms</p>";
            
            if ($result) {
                echo "<p style='color: green; font-weight: bold;'>🎉 Google Calendar Event erfolgreich gelöscht!</p>";
            } else {
                echo "<p style='color: red;'>❌ Google Calendar Event konnte nicht gelöscht werden</p>";
            }
            
        } else {
            echo "<p style='color: orange;'>⚠️ Keine Event ID für Test gefunden - erstelle Test-Event...</p>";
            
            // Erstelle Test-Event
            $test_event_id = create_google_calendar_event(
                'Test Fahrzeug',
                'Test Reservierung für Lösch-Test - ' . date('Y-m-d H:i:s'),
                date('Y-m-d H:i:s'),
                date('Y-m-d H:i:s', strtotime('+1 hour')),
                null,
                'Test Ort'
            );
            
            if ($test_event_id) {
                echo "<p style='color: green;'>✅ Test-Event erstellt: $test_event_id</p>";
                
                // Teste Löschen
                echo "<p>Teste Löschen des Test-Events...</p>";
                $result = delete_google_calendar_event($test_event_id);
                
                if ($result) {
                    echo "<p style='color: green; font-weight: bold;'>🎉 Test-Event erfolgreich gelöscht!</p>";
                } else {
                    echo "<p style='color: red;'>❌ Test-Event konnte nicht gelöscht werden</p>";
                }
            } else {
                echo "<p style='color: red;'>❌ Test-Event konnte nicht erstellt werden</p>";
            }
        }
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Exception beim Test: " . $e->getMessage() . "</p>";
        echo "<p><strong>Stack Trace:</strong></p>";
        echo "<pre>" . $e->getTraceAsString() . "</pre>";
    }
    
} else {
    echo "<p style='color: red;'>❌ delete_google_calendar_event Funktion nicht verfügbar</p>";
}

// 2. Teste Google Calendar Service Account direkt
echo "<h2>2. Google Calendar Service Account direkt testen</h2>";

try {
    if (class_exists('GoogleCalendarServiceAccount')) {
        // Lade Einstellungen
        $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'google_calendar_%'");
        $stmt->execute();
        $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        $service_account_json = $settings['google_calendar_service_account'] ?? '';
        $calendar_id = $settings['google_calendar_id'] ?? 'primary';
        
        if ($service_account_json) {
            echo "<p style='color: green;'>✅ Service Account JSON gefunden</p>";
            
            // Erstelle Service Account
            $calendar_service = new GoogleCalendarServiceAccount($service_account_json, $calendar_id, true);
            echo "<p style='color: green;'>✅ GoogleCalendarServiceAccount Instanz erstellt</p>";
            
            // Erstelle Test-Event
            echo "<p>Erstelle Test-Event für direkten Test...</p>";
            $test_event_id = $calendar_service->createEvent(
                'Direkter Test - ' . date('Y-m-d H:i:s'),
                date('Y-m-d H:i:s'),
                date('Y-m-d H:i:s', strtotime('+1 hour')),
                'Direkter Test Event'
            );
            
            if ($test_event_id) {
                echo "<p style='color: green;'>✅ Test-Event erstellt: $test_event_id</p>";
                
                // Teste Löschen direkt
                echo "<p>Teste Löschen direkt...</p>";
                $start_time = microtime(true);
                $result = $calendar_service->deleteEvent($test_event_id);
                $end_time = microtime(true);
                
                $duration = round(($end_time - $start_time) * 1000, 2);
                
                echo "<p><strong>Lösch-Dauer:</strong> {$duration}ms</p>";
                
                if ($result) {
                    echo "<p style='color: green; font-weight: bold;'>🎉 Direkter Lösch-Test erfolgreich!</p>";
                } else {
                    echo "<p style='color: red;'>❌ Direkter Lösch-Test fehlgeschlagen</p>";
                }
                
            } else {
                echo "<p style='color: red;'>❌ Test-Event konnte nicht erstellt werden</p>";
            }
            
        } else {
            echo "<p style='color: red;'>❌ Service Account JSON nicht gefunden</p>";
        }
        
    } else {
        echo "<p style='color: red;'>❌ GoogleCalendarServiceAccount Klasse nicht verfügbar</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim direkten Test: " . $e->getMessage() . "</p>";
    echo "<p><strong>Stack Trace:</strong></p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}

// 3. Teste mit echten Reservierungen
echo "<h2>3. Teste mit echten Reservierungen</h2>";

try {
    // Hole eine genehmigte Reservierung mit Google Event ID
    $stmt = $db->prepare("
        SELECT r.*, v.name as vehicle_name, ce.google_event_id
        FROM reservations r 
        JOIN vehicles v ON r.vehicle_id = v.id 
        LEFT JOIN calendar_events ce ON r.id = ce.reservation_id
        WHERE r.status = 'approved' 
        AND ce.google_event_id IS NOT NULL
        LIMIT 1
    ");
    $stmt->execute();
    $reservation = $stmt->fetch();
    
    if ($reservation) {
        echo "<p><strong>Teste mit echter Reservierung:</strong></p>";
        echo "<ul>";
        echo "<li><strong>Reservation ID:</strong> " . $reservation['id'] . "</li>";
        echo "<li><strong>Fahrzeug:</strong> " . $reservation['vehicle_name'] . "</li>";
        echo "<li><strong>Google Event ID:</strong> " . $reservation['google_event_id'] . "</li>";
        echo "</ul>";
        
        // Teste Löschen
        echo "<p>Teste Löschen der echten Reservierung...</p>";
        $result = delete_google_calendar_event($reservation['google_event_id']);
        
        if ($result) {
            echo "<p style='color: green; font-weight: bold;'>🎉 Echte Reservierung erfolgreich gelöscht!</p>";
        } else {
            echo "<p style='color: red;'>❌ Echte Reservierung konnte nicht gelöscht werden</p>";
        }
        
    } else {
        echo "<p style='color: orange;'>⚠️ Keine genehmigte Reservierung mit Google Event ID gefunden</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim Test mit echten Reservierungen: " . $e->getMessage() . "</p>";
}

echo "<hr>";
echo "<p><a href='admin/reservations.php'>→ Zur Reservierungen-Übersicht</a></p>";
echo "<p><a href='manage-google-calendar-events.php'>→ Google Calendar Events verwalten</a></p>";
echo "<p><small>Test abgeschlossen: " . date('Y-m-d H:i:s') . "</small></p>";
?>
