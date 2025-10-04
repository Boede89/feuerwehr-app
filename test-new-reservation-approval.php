<?php
/**
 * Test für neue Reservierungsgenehmigung mit Google Calendar
 */

require_once 'config/database.php';
require_once 'includes/functions.php';

echo "<h1>Test: Neue Reservierungsgenehmigung mit Google Calendar</h1>";
echo "<p>Zeitstempel: " . date('d.m.Y H:i:s') . "</p>";

// 1. Test-Reservierung erstellen
echo "<h2>1. Test-Reservierung erstellen</h2>";

try {
    // Fahrzeug finden
    $stmt = $db->prepare("SELECT id, name FROM vehicles WHERE is_active = 1 LIMIT 1");
    $stmt->execute();
    $vehicle = $stmt->fetch();
    
    if (!$vehicle) {
        echo "<p style='color: red;'>❌ Kein Fahrzeug gefunden</p>";
        exit;
    }
    
    $vehicle_id = $vehicle['id'];
    $vehicle_name = $vehicle['name'];
    
    echo "<p><strong>Verwende Fahrzeug:</strong> " . htmlspecialchars($vehicle_name) . " (ID: $vehicle_id)</p>";
    
    // Test-Reservierung erstellen (Status: pending)
    $test_start = date('Y-m-d H:i:s', strtotime('+1 hour'));
    $test_end = date('Y-m-d H:i:s', strtotime('+2 hours'));
    
    $stmt = $db->prepare("INSERT INTO reservations (vehicle_id, requester_name, requester_email, reason, start_datetime, end_datetime, status, created_at) VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())");
    $stmt->execute([$vehicle_id, 'Test Benutzer', 'test@example.com', 'Test für Google Calendar Integration', $test_start, $test_end]);
    $test_reservation_id = $db->lastInsertId();
    
    echo "<p>✅ Test-Reservierung erstellt (ID: $test_reservation_id, Status: pending)</p>";
    
    // 2. Reservierung genehmigen (simuliert den Admin-Prozess)
    echo "<h2>2. Reservierung genehmigen</h2>";
    
    // Prüfe ob es einen Admin-Benutzer gibt
    $stmt = $db->prepare("SELECT id FROM users WHERE is_admin = 1 LIMIT 1");
    $stmt->execute();
    $admin_user = $stmt->fetch();
    
    if (!$admin_user) {
        echo "<p style='color: orange;'>⚠️ Kein Admin-Benutzer gefunden. Erstelle einen Test-Admin...</p>";
        
        // Test-Admin erstellen
        $stmt = $db->prepare("INSERT INTO users (username, email, password_hash, first_name, last_name, is_admin, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $password_hash = password_hash('test123', PASSWORD_DEFAULT);
        $stmt->execute(['testadmin', 'admin@test.com', $password_hash, 'Test', 'Admin', 1, 1]);
        $admin_user_id = $db->lastInsertId();
        
        echo "<p>✅ Test-Admin erstellt (ID: $admin_user_id)</p>";
    } else {
        $admin_user_id = $admin_user['id'];
        echo "<p>✅ Admin-Benutzer gefunden (ID: $admin_user_id)</p>";
    }
    
    // Status auf approved setzen
    $stmt = $db->prepare("UPDATE reservations SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?");
    $stmt->execute([$admin_user_id, $test_reservation_id]);
    
    echo "<p>✅ Reservierung genehmigt</p>";
    
    // 3. Google Calendar Event erstellen (wie in admin/reservations.php)
    echo "<h2>3. Google Calendar Event erstellen</h2>";
    
    try {
        $stmt = $db->prepare("SELECT r.*, v.name as vehicle_name FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id WHERE r.id = ?");
        $stmt->execute([$test_reservation_id]);
        $reservation = $stmt->fetch();
        
        if ($reservation) {
            echo "<p><strong>Reservierung gefunden:</strong></p>";
            echo "<ul>";
            echo "<li><strong>ID:</strong> " . htmlspecialchars($reservation['id']) . "</li>";
            echo "<li><strong>Fahrzeug:</strong> " . htmlspecialchars($reservation['vehicle_name']) . "</li>";
            echo "<li><strong>Grund:</strong> " . htmlspecialchars($reservation['reason']) . "</li>";
            echo "<li><strong>Start:</strong> " . htmlspecialchars($reservation['start_datetime']) . "</li>";
            echo "<li><strong>Ende:</strong> " . htmlspecialchars($reservation['end_datetime']) . "</li>";
            echo "<li><strong>Status:</strong> " . htmlspecialchars($reservation['status']) . "</li>";
            echo "</ul>";
            
            // Prüfe ob Google Calendar Funktion verfügbar ist
            if (function_exists('create_google_calendar_event')) {
                echo "<p style='color: green;'>✅ create_google_calendar_event Funktion ist verfügbar</p>";
                
                echo "<p><strong>Versuche Google Calendar Event zu erstellen...</strong></p>";
                
                $event_id = create_google_calendar_event(
                    $reservation['vehicle_name'],
                    $reservation['reason'],
                    $reservation['start_datetime'],
                    $reservation['end_datetime'],
                    $reservation_id
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
                } else {
                    echo "<p style='color: red;'>❌ Google Calendar Event konnte NICHT erstellt werden</p>";
                }
            } else {
                echo "<p style='color: red;'>❌ create_google_calendar_event Funktion ist NICHT verfügbar</p>";
            }
        } else {
            echo "<p style='color: red;'>❌ Reservierung nicht gefunden</p>";
        }
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Fehler beim Erstellen des Google Calendar Events: " . htmlspecialchars($e->getMessage()) . "</p>";
        echo "<p><strong>Stack Trace:</strong></p>";
        echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    }
    
    // 4. Kurz warten, damit das Event in der Datenbank gespeichert wird
    echo "<h2>4. Warten auf Datenbank-Speicherung</h2>";
    echo "<p>Warte 2 Sekunden, damit das Event in der Datenbank gespeichert wird...</p>";
    sleep(2);
    
    // Prüfe nochmal ob Event in der Datenbank gespeichert wurde
    $stmt = $db->prepare("SELECT * FROM calendar_events WHERE reservation_id = ?");
    $stmt->execute([$test_reservation_id]);
    $event_record = $stmt->fetch();
    
    if ($event_record) {
        echo "<p style='color: green;'>✅ Event in der Datenbank gespeichert (nach Wartezeit)</p>";
    } else {
        echo "<p style='color: red;'>❌ Event immer noch nicht in der Datenbank</p>";
    }
    
    // 5. Aufräumen
    echo "<h2>5. Aufräumen</h2>";
    
    if (isset($event_id) && $event_id) {
        try {
            // Google Calendar Event löschen
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
            echo "<p style='color: orange;'>⚠️ Fehler beim Löschen des Google Calendar Events: " . htmlspecialchars($e->getMessage()) . "</p>";
        }
    }
    
    // Test-Reservierung löschen
    $stmt = $db->prepare("DELETE FROM reservations WHERE id = ?");
    $stmt->execute([$test_reservation_id]);
    echo "<p>✅ Test-Reservierung gelöscht</p>";
    
    // Test-Admin löschen (falls erstellt)
    if (isset($admin_user_id) && $admin_user_id) {
        $stmt = $db->prepare("DELETE FROM users WHERE id = ? AND username = 'testadmin'");
        $stmt->execute([$admin_user_id]);
        echo "<p>✅ Test-Admin gelöscht</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim Test: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><strong>Stack Trace:</strong></p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}

echo "<hr>";
echo "<p><strong>Test abgeschlossen um:</strong> " . date('d.m.Y H:i:s') . "</p>";
?>
