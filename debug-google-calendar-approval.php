<?php
/**
 * Debug für Google Calendar Integration bei Reservierungsgenehmigung
 */

require_once 'config/database.php';
require_once 'includes/functions.php';

echo "<h1>Debug: Google Calendar bei Reservierungsgenehmigung</h1>";
echo "<p>Zeitstempel: " . date('d.m.Y H:i:s') . "</p>";

// 1. Prüfe ob Funktion verfügbar ist
echo "<h2>1. Funktion Verfügbarkeit</h2>";
if (function_exists('create_google_calendar_event')) {
    echo "<p style='color: green;'>✅ create_google_calendar_event Funktion ist verfügbar</p>";
} else {
    echo "<p style='color: red;'>❌ create_google_calendar_event Funktion ist NICHT verfügbar</p>";
    exit;
}

// 2. Prüfe Einstellungen
echo "<h2>2. Google Calendar Einstellungen</h2>";
try {
    $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'google_calendar_%'");
    $stmt->execute();
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    $auth_type = $settings['google_calendar_auth_type'] ?? 'service_account';
    $calendar_id = $settings['google_calendar_id'] ?? 'primary';
    $service_account_json = $settings['google_calendar_service_account_json'] ?? '';
    
    echo "<p><strong>Authentifizierungstyp:</strong> " . htmlspecialchars($auth_type) . "</p>";
    echo "<p><strong>Kalender ID:</strong> " . htmlspecialchars($calendar_id) . "</p>";
    echo "<p><strong>Service Account JSON:</strong> " . (empty($service_account_json) ? "❌ Nicht konfiguriert" : "✅ Konfiguriert (" . strlen($service_account_json) . " Zeichen)") . "</p>";
    
} catch(PDOException $e) {
    echo "<p style='color: red;'>❌ Fehler beim Laden der Einstellungen: " . $e->getMessage() . "</p>";
    exit;
}

// 3. Prüfe ob es genehmigte Reservierungen gibt
echo "<h2>3. Genehmigte Reservierungen prüfen</h2>";
try {
    $stmt = $db->prepare("SELECT r.*, v.name as vehicle_name FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id WHERE r.status = 'approved' ORDER BY r.approved_at DESC LIMIT 5");
    $stmt->execute();
    $reservations = $stmt->fetchAll();
    
    if (empty($reservations)) {
        echo "<p style='color: orange;'>⚠️ Keine genehmigten Reservierungen gefunden</p>";
    } else {
        echo "<p style='color: green;'>✅ " . count($reservations) . " genehmigte Reservierungen gefunden</p>";
        
        foreach ($reservations as $reservation) {
            echo "<div style='border: 1px solid #ddd; padding: 10px; margin: 10px 0;'>";
            echo "<p><strong>ID:</strong> " . htmlspecialchars($reservation['id']) . "</p>";
            echo "<p><strong>Fahrzeug:</strong> " . htmlspecialchars($reservation['vehicle_name']) . "</p>";
            echo "<p><strong>Grund:</strong> " . htmlspecialchars($reservation['reason']) . "</p>";
            echo "<p><strong>Start:</strong> " . htmlspecialchars($reservation['start_datetime']) . "</p>";
            echo "<p><strong>Ende:</strong> " . htmlspecialchars($reservation['end_datetime']) . "</p>";
            echo "<p><strong>Genehmigt am:</strong> " . htmlspecialchars($reservation['approved_at']) . "</p>";
            echo "</div>";
        }
    }
} catch(PDOException $e) {
    echo "<p style='color: red;'>❌ Fehler beim Laden der Reservierungen: " . $e->getMessage() . "</p>";
}

// 4. Prüfe Google Calendar Events in der Datenbank
echo "<h2>4. Google Calendar Events in der Datenbank</h2>";
try {
    $stmt = $db->prepare("SELECT ce.*, r.reason, v.name as vehicle_name FROM calendar_events ce JOIN reservations r ON ce.reservation_id = r.id JOIN vehicles v ON r.vehicle_id = v.id ORDER BY ce.created_at DESC LIMIT 5");
    $stmt->execute();
    $events = $stmt->fetchAll();
    
    if (empty($events)) {
        echo "<p style='color: red;'>❌ Keine Google Calendar Events in der Datenbank gefunden</p>";
    } else {
        echo "<p style='color: green;'>✅ " . count($events) . " Google Calendar Events in der Datenbank gefunden</p>";
        
        foreach ($events as $event) {
            echo "<div style='border: 1px solid #ddd; padding: 10px; margin: 10px 0;'>";
            echo "<p><strong>Event ID:</strong> " . htmlspecialchars($event['google_event_id']) . "</p>";
            echo "<p><strong>Reservierung ID:</strong> " . htmlspecialchars($event['reservation_id']) . "</p>";
            echo "<p><strong>Fahrzeug:</strong> " . htmlspecialchars($event['vehicle_name']) . "</p>";
            echo "<p><strong>Titel:</strong> " . htmlspecialchars($event['title']) . "</p>";
            echo "<p><strong>Start:</strong> " . htmlspecialchars($event['start_datetime']) . "</p>";
            echo "<p><strong>Ende:</strong> " . htmlspecialchars($event['end_datetime']) . "</p>";
            echo "<p><strong>Erstellt am:</strong> " . htmlspecialchars($event['created_at']) . "</p>";
            echo "</div>";
        }
    }
} catch(PDOException $e) {
    echo "<p style='color: red;'>❌ Fehler beim Laden der Events: " . $e->getMessage() . "</p>";
}

// 5. Teste Google Calendar Integration direkt
echo "<h2>5. Direkter Test der Google Calendar Integration</h2>";

// Test-Reservierung erstellen
try {
    $stmt = $db->prepare("SELECT id, name FROM vehicles WHERE is_active = 1 LIMIT 1");
    $stmt->execute();
    $vehicle = $stmt->fetch();
    
    if (!$vehicle) {
        echo "<p style='color: red;'>❌ Kein Fahrzeug gefunden - Test nicht möglich</p>";
    } else {
        $vehicle_id = $vehicle['id'];
        $vehicle_name = $vehicle['name'];
        
        echo "<p><strong>Verwende Fahrzeug:</strong> " . htmlspecialchars($vehicle_name) . " (ID: $vehicle_id)</p>";
        
        // Test-Reservierung erstellen
        $test_start = date('Y-m-d H:i:s', strtotime('+1 hour'));
        $test_end = date('Y-m-d H:i:s', strtotime('+2 hours'));
        
        $stmt = $db->prepare("INSERT INTO reservations (vehicle_id, requester_name, requester_email, reason, start_datetime, end_datetime, status, created_at) VALUES (?, ?, ?, ?, ?, ?, 'approved', NOW())");
        $stmt->execute([$vehicle_id, 'Debug Test', 'debug@test.com', 'Debug Test für Google Calendar', $test_start, $test_end]);
        $test_reservation_id = $db->lastInsertId();
        
        echo "<p>✅ Test-Reservierung erstellt (ID: $test_reservation_id)</p>";
        
        // Google Calendar Event erstellen
        echo "<p><strong>Versuche Google Calendar Event zu erstellen...</strong></p>";
        
        $event_id = create_google_calendar_event(
            $vehicle_name,
            'Debug Test für Google Calendar',
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
            } else {
                echo "<p style='color: red;'>❌ Event NICHT in der Datenbank gespeichert</p>";
            }
            
            // Event löschen
            try {
                require_once 'includes/google_calendar_service_account.php';
                $google_calendar = new GoogleCalendarServiceAccount($service_account_json, $calendar_id, true);
                $google_calendar->deleteEvent($event_id);
                echo "<p style='color: green;'>✅ Test Event gelöscht</p>";
            } catch (Exception $e) {
                echo "<p style='color: orange;'>⚠️ Fehler beim Löschen: " . htmlspecialchars($e->getMessage()) . "</p>";
            }
        } else {
            echo "<p style='color: red;'>❌ Google Calendar Event konnte NICHT erstellt werden</p>";
        }
        
        // Test-Reservierung löschen
        $stmt = $db->prepare("DELETE FROM reservations WHERE id = ?");
        $stmt->execute([$test_reservation_id]);
        echo "<p>✅ Test-Reservierung gelöscht</p>";
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim Test: " . htmlspecialchars($e->getMessage()) . "</p>";
}

// 6. Prüfe Error Logs
echo "<h2>6. Error Logs prüfen</h2>";
$error_log_file = ini_get('error_log');
if ($error_log_file && file_exists($error_log_file)) {
    $log_content = file_get_contents($error_log_file);
    $google_calendar_errors = [];
    
    $lines = explode("\n", $log_content);
    foreach ($lines as $line) {
        if (strpos($line, 'Google Calendar') !== false) {
            $google_calendar_errors[] = $line;
        }
    }
    
    if (empty($google_calendar_errors)) {
        echo "<p style='color: green;'>✅ Keine Google Calendar Fehler in den Logs gefunden</p>";
    } else {
        echo "<p style='color: orange;'>⚠️ " . count($google_calendar_errors) . " Google Calendar Fehler in den Logs gefunden:</p>";
        echo "<div style='background-color: #f8f9fa; padding: 10px; border-radius: 4px; max-height: 200px; overflow-y: auto;'>";
        foreach (array_slice($google_calendar_errors, -10) as $error) {
            echo "<p style='margin: 2px 0; font-family: monospace; font-size: 12px;'>" . htmlspecialchars($error) . "</p>";
        }
        echo "</div>";
    }
} else {
    echo "<p style='color: orange;'>⚠️ Error Log nicht gefunden oder nicht konfiguriert</p>";
}

echo "<hr>";
echo "<p><strong>Debug abgeschlossen um:</strong> " . date('d.m.Y H:i:s') . "</p>";
?>
