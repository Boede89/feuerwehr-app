<?php
/**
 * Debug für Google Calendar Events Datenbank-Speicherung
 */

require_once 'config/database.php';
require_once 'includes/functions.php';

echo "<h1>Debug: Google Calendar Events Datenbank-Speicherung</h1>";
echo "<p>Zeitstempel: " . date('d.m.Y H:i:s') . "</p>";

// 1. Prüfe ob calendar_events Tabelle existiert
echo "<h2>1. Datenbank-Schema prüfen</h2>";

try {
    $stmt = $db->prepare("DESCRIBE calendar_events");
    $stmt->execute();
    $columns = $stmt->fetchAll();
    
    if (empty($columns)) {
        echo "<p style='color: red;'>❌ calendar_events Tabelle existiert nicht!</p>";
    } else {
        echo "<p style='color: green;'>✅ calendar_events Tabelle existiert</p>";
        echo "<p><strong>Spalten:</strong></p>";
        echo "<ul>";
        foreach ($columns as $column) {
            echo "<li><strong>" . htmlspecialchars($column['Field']) . ":</strong> " . htmlspecialchars($column['Type']) . " " . ($column['Null'] == 'YES' ? '(NULL erlaubt)' : '(NOT NULL)') . "</li>";
        }
        echo "</ul>";
    }
} catch(PDOException $e) {
    echo "<p style='color: red;'>❌ Fehler beim Prüfen der Tabelle: " . $e->getMessage() . "</p>";
}

// 2. Test-Event direkt in die Datenbank einfügen
echo "<h2>2. Test-Event direkt in die Datenbank einfügen</h2>";

try {
    $test_reservation_id = 999999; // Nicht existierende ID für Test
    $test_google_event_id = 'test_' . time();
    $test_title = 'Test Event - ' . date('H:i:s');
    $test_start = date('Y-m-d H:i:s', strtotime('+1 hour'));
    $test_end = date('Y-m-d H:i:s', strtotime('+2 hours'));
    
    echo "<p><strong>Test-Daten:</strong></p>";
    echo "<ul>";
    echo "<li><strong>Reservation ID:</strong> $test_reservation_id</li>";
    echo "<li><strong>Google Event ID:</strong> $test_google_event_id</li>";
    echo "<li><strong>Titel:</strong> $test_title</li>";
    echo "<li><strong>Start:</strong> $test_start</li>";
    echo "<li><strong>Ende:</strong> $test_end</li>";
    echo "</ul>";
    
    $stmt = $db->prepare("INSERT INTO calendar_events (reservation_id, google_event_id, title, start_datetime, end_datetime) VALUES (?, ?, ?, ?, ?)");
    $result = $stmt->execute([$test_reservation_id, $test_google_event_id, $test_title, $test_start, $test_end]);
    
    if ($result) {
        echo "<p style='color: green;'>✅ Test-Event erfolgreich in die Datenbank eingefügt</p>";
        
        // Test-Event wieder löschen
        $stmt = $db->prepare("DELETE FROM calendar_events WHERE google_event_id = ?");
        $stmt->execute([$test_google_event_id]);
        echo "<p>✅ Test-Event wieder gelöscht</p>";
    } else {
        echo "<p style='color: red;'>❌ Fehler beim Einfügen des Test-Events</p>";
    }
} catch(PDOException $e) {
    echo "<p style='color: red;'>❌ Fehler beim Test: " . $e->getMessage() . "</p>";
}

// 3. Teste create_google_calendar_event mit Debugging
echo "<h2>3. create_google_calendar_event mit Debugging testen</h2>";

try {
    // Test-Reservierung erstellen
    $stmt = $db->prepare("SELECT id, name FROM vehicles WHERE is_active = 1 LIMIT 1");
    $stmt->execute();
    $vehicle = $stmt->fetch();
    
    if (!$vehicle) {
        echo "<p style='color: red;'>❌ Kein Fahrzeug gefunden</p>";
        exit;
    }
    
    $vehicle_id = $vehicle['id'];
    $vehicle_name = $vehicle['name'];
    
    // Test-Reservierung erstellen
    $test_start = date('Y-m-d H:i:s', strtotime('+1 hour'));
    $test_end = date('Y-m-d H:i:s', strtotime('+2 hours'));
    
    $stmt = $db->prepare("INSERT INTO reservations (vehicle_id, requester_name, requester_email, reason, start_datetime, end_datetime, status, created_at) VALUES (?, ?, ?, ?, ?, ?, 'approved', NOW())");
    $stmt->execute([$vehicle_id, 'Debug Test', 'debug@test.com', 'Debug Test für Datenbank-Speicherung', $test_start, $test_end]);
    $test_reservation_id = $db->lastInsertId();
    
    echo "<p>✅ Test-Reservierung erstellt (ID: $test_reservation_id)</p>";
    
    // Google Calendar Event erstellen
    echo "<p><strong>Versuche Google Calendar Event zu erstellen...</strong></p>";
    
    $event_id = create_google_calendar_event(
        $vehicle_name,
        'Debug Test für Datenbank-Speicherung',
        $test_start,
        $test_end,
        $test_reservation_id
    );
    
    if ($event_id) {
        echo "<p style='color: green;'>✅ Google Calendar Event erfolgreich erstellt! Event ID: " . htmlspecialchars($event_id) . "</p>";
        
        // Prüfe ob Event in der Datenbank gespeichert wurde
        $stmt = $db->prepare("SELECT * FROM calendar_events WHERE reservation_id = ?");
        $stmt->execute([$test_reservation_id]);
        $event_record = $stmt->fetch();
        
        if ($event_record) {
            echo "<p style='color: green;'>✅ Event in der Datenbank gespeichert</p>";
            echo "<ul>";
            echo "<li><strong>ID:</strong> " . htmlspecialchars($event_record['id']) . "</li>";
            echo "<li><strong>Reservation ID:</strong> " . htmlspecialchars($event_record['reservation_id']) . "</li>";
            echo "<li><strong>Google Event ID:</strong> " . htmlspecialchars($event_record['google_event_id']) . "</li>";
            echo "<li><strong>Titel:</strong> " . htmlspecialchars($event_record['title']) . "</li>";
            echo "<li><strong>Start:</strong> " . htmlspecialchars($event_record['start_datetime']) . "</li>";
            echo "<li><strong>Ende:</strong> " . htmlspecialchars($event_record['end_datetime']) . "</li>";
            echo "</ul>";
        } else {
            echo "<p style='color: red;'>❌ Event NICHT in der Datenbank gespeichert</p>";
            
            // Prüfe alle Events in der Datenbank
            $stmt = $db->prepare("SELECT * FROM calendar_events ORDER BY created_at DESC LIMIT 5");
            $stmt->execute();
            $all_events = $stmt->fetchAll();
            
            echo "<p><strong>Letzte 5 Events in der Datenbank:</strong></p>";
            if (empty($all_events)) {
                echo "<p style='color: orange;'>⚠️ Keine Events in der Datenbank</p>";
            } else {
                foreach ($all_events as $event) {
                    echo "<div style='border: 1px solid #ddd; padding: 5px; margin: 5px 0;'>";
                    echo "<p><strong>ID:</strong> " . htmlspecialchars($event['id']) . " | <strong>Reservation ID:</strong> " . htmlspecialchars($event['reservation_id']) . " | <strong>Google Event ID:</strong> " . htmlspecialchars($event['google_event_id']) . "</p>";
                    echo "</div>";
                }
            }
        }
        
        // Event löschen
        try {
            require_once 'includes/google_calendar_service_account.php';
            
            // Einstellungen laden
            $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'google_calendar_%'");
            $stmt->execute();
            $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            
            $calendar_id = $settings['google_calendar_id'] ?? 'primary';
            $service_account_json = $settings['google_calendar_service_account_json'] ?? '';
            
            $google_calendar = new GoogleCalendarServiceAccount($service_account_json, $calendar_id, true);
            $google_calendar->deleteEvent($event_id);
            echo "<p>✅ Google Calendar Event gelöscht</p>";
        } catch (Exception $e) {
            echo "<p style='color: orange;'>⚠️ Fehler beim Löschen: " . htmlspecialchars($e->getMessage()) . "</p>";
        }
    } else {
        echo "<p style='color: red;'>❌ Google Calendar Event konnte nicht erstellt werden</p>";
    }
    
    // Test-Reservierung löschen
    $stmt = $db->prepare("DELETE FROM reservations WHERE id = ?");
    $stmt->execute([$test_reservation_id]);
    echo "<p>✅ Test-Reservierung gelöscht</p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim Test: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><strong>Stack Trace:</strong></p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}

echo "<hr>";
echo "<p><strong>Debug abgeschlossen um:</strong> " . date('d.m.Y H:i:s') . "</p>";
?>
