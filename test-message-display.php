<?php
/**
 * Test für Meldungsanzeige
 */

session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';

echo "<h1>Test: Meldungsanzeige</h1>";
echo "<p>Zeitstempel: " . date('d.m.Y H:i:s') . "</p>";

// 1. Prüfe Session
echo "<h2>1. Session prüfen</h2>";
if (isset($_SESSION['user_id'])) {
    echo "<p style='color: green;'>✅ Eingeloggt als User ID: " . $_SESSION['user_id'] . "</p>";
} else {
    echo "<p style='color: red;'>❌ Nicht eingeloggt</p>";
    exit;
}

// 2. Teste Google Calendar Integration direkt
echo "<h2>2. Google Calendar Integration direkt testen</h2>";

if (function_exists('create_google_calendar_event')) {
    echo "<p style='color: green;'>✅ create_google_calendar_event Funktion ist verfügbar</p>";
    
    try {
        // Test-Reservierung erstellen
        $stmt = $db->prepare("SELECT id, name FROM vehicles WHERE is_active = 1 LIMIT 1");
        $stmt->execute();
        $vehicle = $stmt->fetch();
        
        if ($vehicle) {
            $test_start = date('Y-m-d H:i:s', strtotime('+1 hour'));
            $test_end = date('Y-m-d H:i:s', strtotime('+2 hours'));
            
            $stmt = $db->prepare("INSERT INTO reservations (vehicle_id, requester_name, requester_email, reason, start_datetime, end_datetime, status, created_at) VALUES (?, ?, ?, ?, ?, ?, 'approved', NOW())");
            $stmt->execute([$vehicle['id'], 'Message Test', 'message@test.com', 'Message Test für Google Calendar', $test_start, $test_end]);
            $test_reservation_id = $db->lastInsertId();
            
            echo "<p>✅ Test-Reservierung erstellt (ID: $test_reservation_id)</p>";
            
            // Google Calendar Event erstellen
            echo "<p><strong>Versuche Google Calendar Event zu erstellen...</strong></p>";
            
            $event_id = create_google_calendar_event(
                $vehicle['name'],
                'Message Test für Google Calendar',
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
                    echo "<li><strong>Event ID:</strong> " . htmlspecialchars($event_record['google_event_id']) . "</li>";
                    echo "<li><strong>Titel:</strong> " . htmlspecialchars($event_record['title']) . "</li>";
                    echo "<li><strong>Start:</strong> " . htmlspecialchars($event_record['start_datetime']) . "</li>";
                    echo "<li><strong>Ende:</strong> " . htmlspecialchars($event_record['end_datetime']) . "</li>";
                    echo "</ul>";
                } else {
                    echo "<p style='color: red;'>❌ Event NICHT in der Datenbank gespeichert</p>";
                }
                
                // Event löschen
                try {
                    require_once 'includes/google_calendar_service_account.php';
                    
                    $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'google_calendar_%'");
                    $stmt->execute();
                    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
                    
                    $calendar_id = $settings['google_calendar_id'] ?? 'primary';
                    $service_account_json = $settings['google_calendar_service_account_json'] ?? '';
                    
                    $google_calendar = new GoogleCalendarServiceAccount($service_account_json, $calendar_id, true);
                    $google_calendar->deleteEvent($event_id);
                    echo "<p>✅ Test Event gelöscht</p>";
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
        } else {
            echo "<p style='color: red;'>❌ Kein Fahrzeug gefunden</p>";
        }
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Fehler beim Test: " . htmlspecialchars($e->getMessage()) . "</p>";
        echo "<p><strong>Stack Trace:</strong></p>";
        echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    }
} else {
    echo "<p style='color: red;'>❌ create_google_calendar_event Funktion ist nicht verfügbar</p>";
}

// 3. Teste Meldungsanzeige
echo "<h2>3. Meldungsanzeige testen</h2>";

$test_message = "Reservierung erfolgreich genehmigt. Google Calendar Event wurde erstellt.";
echo "<p><strong>Test-Meldung:</strong> $test_message</p>";

// Simuliere Bootstrap Alert
echo "<div class='alert alert-success' role='alert'>";
echo "<i class='fas fa-check-circle'></i> $test_message";
echo "</div>";

echo "<hr>";
echo "<p><strong>Test abgeschlossen um:</strong> " . date('d.m.Y H:i:s') . "</p>";
?>
