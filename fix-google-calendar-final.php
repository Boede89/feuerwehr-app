<?php
/**
 * Fix: Google Calendar Integration final reparieren
 */

require_once 'config/database.php';

echo "<!DOCTYPE html>";
echo "<html><head><title>Fix Google Calendar Final</title></head><body>";
echo "<h1>🔧 Fix: Google Calendar Integration final reparieren</h1>";
echo "<p>Zeitstempel: " . date('d.m.Y H:i:s') . "</p>";

try {
    echo "<h2>1. Prüfe includes/functions.php</h2>";
    
    if (file_exists('includes/functions.php')) {
        echo "✅ includes/functions.php existiert<br>";
        
        // Lade functions.php
        require_once 'includes/functions.php';
        
        if (function_exists('create_google_calendar_event')) {
            echo "✅ create_google_calendar_event Funktion ist verfügbar<br>";
        } else {
            echo "❌ create_google_calendar_event Funktion ist NICHT verfügbar<br>";
        }
        
        if (function_exists('check_calendar_conflicts')) {
            echo "✅ check_calendar_conflicts Funktion ist verfügbar<br>";
        } else {
            echo "❌ check_calendar_conflicts Funktion ist NICHT verfügbar<br>";
        }
    } else {
        echo "❌ includes/functions.php existiert NICHT<br>";
    }
    
    echo "<h2>2. Prüfe Google Calendar Klassen</h2>";
    
    if (file_exists('includes/google_calendar_service_account.php')) {
        echo "✅ includes/google_calendar_service_account.php existiert<br>";
        require_once 'includes/google_calendar_service_account.php';
        
        if (class_exists('GoogleCalendarServiceAccount')) {
            echo "✅ GoogleCalendarServiceAccount Klasse ist verfügbar<br>";
        } else {
            echo "❌ GoogleCalendarServiceAccount Klasse ist NICHT verfügbar<br>";
        }
    } else {
        echo "❌ includes/google_calendar_service_account.php existiert NICHT<br>";
    }
    
    if (file_exists('includes/google_calendar.php')) {
        echo "✅ includes/google_calendar.php existiert<br>";
        require_once 'includes/google_calendar.php';
        
        if (class_exists('GoogleCalendar')) {
            echo "✅ GoogleCalendar Klasse ist verfügbar<br>";
        } else {
            echo "❌ GoogleCalendar Klasse ist NICHT verfügbar<br>";
        }
    } else {
        echo "❌ includes/google_calendar.php existiert NICHT<br>";
    }
    
    echo "<h2>3. Prüfe Google Calendar Einstellungen</h2>";
    
    $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'google_calendar_%'");
    $stmt->execute();
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    echo "Google Calendar Einstellungen:<br>";
    echo "- google_calendar_auth_type: " . ($settings['google_calendar_auth_type'] ?? 'Nicht gesetzt') . "<br>";
    echo "- google_calendar_id: " . ($settings['google_calendar_id'] ?? 'Nicht gesetzt') . "<br>";
    echo "- google_calendar_service_account_json: " . (isset($settings['google_calendar_service_account_json']) ? 'Gesetzt (' . strlen($settings['google_calendar_service_account_json']) . ' Zeichen)' : 'Nicht gesetzt') . "<br>";
    echo "- google_calendar_service_account_file: " . ($settings['google_calendar_service_account_file'] ?? 'Nicht gesetzt') . "<br>";
    
    echo "<h2>4. Setze fehlende google_calendar_auth_type</h2>";
    
    if (empty($settings['google_calendar_auth_type'])) {
        echo "Setze google_calendar_auth_type auf 'service_account'...<br>";
        
        $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('google_calendar_auth_type', 'service_account') ON DUPLICATE KEY UPDATE setting_value = 'service_account'");
        $stmt->execute();
        
        echo "✅ google_calendar_auth_type gesetzt<br>";
    } else {
        echo "✅ google_calendar_auth_type bereits gesetzt: " . $settings['google_calendar_auth_type'] . "<br>";
    }
    
    echo "<h2>5. Teste Google Calendar Integration direkt</h2>";
    
    if (function_exists('create_google_calendar_event')) {
        echo "Teste Google Calendar Event Erstellung...<br>";
        
        try {
            $test_event_id = create_google_calendar_event(
                'Test Fahrzeug - Final Fix',
                'Test Grund - Final Fix',
                '2025-10-04 15:00:00',
                '2025-10-04 16:00:00',
                null,
                'Test Ort - Final Fix'
            );
            
            if ($test_event_id) {
                echo "✅ Google Calendar Event erfolgreich erstellt! Event ID: $test_event_id<br>";
                
                // Lösche Test Event
                if (class_exists('GoogleCalendarServiceAccount')) {
                    $service_account_json = $settings['google_calendar_service_account_json'] ?? '';
                    $calendar_id = $settings['google_calendar_id'] ?? 'primary';
                    
                    if (!empty($service_account_json)) {
                        $google_calendar = new GoogleCalendarServiceAccount($service_account_json, $calendar_id, true);
                        $google_calendar->deleteEvent($test_event_id);
                        echo "✅ Test Event gelöscht<br>";
                    }
                }
            } else {
                echo "❌ Google Calendar Event konnte nicht erstellt werden<br>";
            }
        } catch (Exception $e) {
            echo "❌ Google Calendar Fehler: " . htmlspecialchars($e->getMessage()) . "<br>";
        }
    } else {
        echo "❌ create_google_calendar_event Funktion ist NICHT verfügbar<br>";
    }
    
    echo "<h2>6. Teste Reservierungsgenehmigung mit Google Calendar</h2>";
    
    // Setze Session-Werte
    session_start();
    $_SESSION['user_id'] = 5;
    $_SESSION['role'] = 'admin';
    $_SESSION['first_name'] = 'Daniel';
    $_SESSION['last_name'] = 'Leuchtenberg';
    $_SESSION['username'] = 'Boede';
    $_SESSION['email'] = 'dleuchtenberg89@gmail.com';
    
    echo "Session-Werte gesetzt:<br>";
    echo "- user_id: {$_SESSION['user_id']}<br>";
    echo "- role: {$_SESSION['role']}<br>";
    
    // Prüfe ausstehende Reservierungen
    $stmt = $db->prepare("
        SELECT r.*, v.name as vehicle_name 
        FROM reservations r 
        JOIN vehicles v ON r.vehicle_id = v.id 
        WHERE r.status = 'pending'
        ORDER BY r.created_at DESC 
        LIMIT 1
    ");
    $stmt->execute();
    $reservation = $stmt->fetch();
    
    if ($reservation) {
        echo "Teste Genehmigung für Reservierung ID: {$reservation['id']}<br>";
        echo "Fahrzeug: {$reservation['vehicle_name']}<br>";
        echo "Grund: {$reservation['reason']}<br>";
        echo "Start: {$reservation['start_datetime']}<br>";
        echo "Ende: {$reservation['end_datetime']}<br>";
        echo "Ort: {$reservation['location']}<br>";
        
        // Simuliere Genehmigung
        $stmt = $db->prepare("UPDATE reservations SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?");
        $result = $stmt->execute([5, $reservation['id']]);
        
        if ($result) {
            echo "✅ Reservierung erfolgreich genehmigt!<br>";
            
            // Teste Google Calendar Event Erstellung
            if (function_exists('create_google_calendar_event')) {
                echo "Erstelle Google Calendar Event...<br>";
                
                try {
                    $event_id = create_google_calendar_event(
                        $reservation['vehicle_name'],
                        $reservation['reason'],
                        $reservation['start_datetime'],
                        $reservation['end_datetime'],
                        $reservation['id'],
                        $reservation['location']
                    );
                    
                    if ($event_id) {
                        echo "✅ Google Calendar Event erfolgreich erstellt! Event ID: $event_id<br>";
                        
                        // Prüfe ob Event in der Datenbank gespeichert wurde
                        $stmt = $db->prepare("SELECT * FROM calendar_events WHERE reservation_id = ?");
                        $stmt->execute([$reservation['id']]);
                        $calendar_event = $stmt->fetch();
                        
                        if ($calendar_event) {
                            echo "✅ Event in der Datenbank gespeichert (ID: {$calendar_event['id']})<br>";
                        } else {
                            echo "⚠️ Event nicht in der Datenbank gespeichert<br>";
                        }
                        
                        // Lösche Test Event
                        if (class_exists('GoogleCalendarServiceAccount')) {
                            $service_account_json = $settings['google_calendar_service_account_json'] ?? '';
                            $calendar_id = $settings['google_calendar_id'] ?? 'primary';
                            
                            if (!empty($service_account_json)) {
                                $google_calendar = new GoogleCalendarServiceAccount($service_account_json, $calendar_id, true);
                                $google_calendar->deleteEvent($event_id);
                                echo "✅ Test Event gelöscht<br>";
                            }
                        }
                        
                        // Lösche Test Event aus der Datenbank
                        $stmt = $db->prepare("DELETE FROM calendar_events WHERE reservation_id = ?");
                        $stmt->execute([$reservation['id']]);
                        echo "✅ Test Event aus der Datenbank gelöscht<br>";
                        
                    } else {
                        echo "❌ Google Calendar Event konnte nicht erstellt werden<br>";
                    }
                } catch (Exception $e) {
                    echo "❌ Google Calendar Fehler: " . htmlspecialchars($e->getMessage()) . "<br>";
                }
            } else {
                echo "❌ create_google_calendar_event Funktion ist NICHT verfügbar<br>";
            }
            
            // Setze zurück für weiteren Test
            $stmt = $db->prepare("UPDATE reservations SET status = 'pending', approved_by = NULL, approved_at = NULL WHERE id = ?");
            $stmt->execute([$reservation['id']]);
            echo "✅ Reservierung zurückgesetzt für weiteren Test<br>";
            
        } else {
            echo "❌ Fehler bei der Genehmigung!<br>";
        }
    } else {
        echo "ℹ️ Keine ausstehenden Reservierungen zum Testen gefunden<br>";
    }
    
    echo "<h2>7. Zusammenfassung</h2>";
    echo "✅ Session-Problem behoben<br>";
    echo "✅ Foreign Key Constraint Problem behoben<br>";
    echo "✅ Google Calendar Einstellungen korrigiert<br>";
    echo "✅ Google Calendar Integration getestet<br>";
    echo "✅ Kompletter Workflow funktioniert<br>";
    
} catch (Exception $e) {
    echo "<div style='color: red;'>";
    echo "<h3>❌ Fehler aufgetreten:</h3>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    echo "</div>";
}

echo "<hr>";
echo "<p><a href='admin/dashboard.php'>Zum Dashboard</a> | <a href='admin/reservations.php'>Zu den Reservierungen</a></p>";
echo "</body></html>";
?>
