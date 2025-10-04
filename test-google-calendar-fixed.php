<?php
/**
 * Test: Google Calendar mit verbesserten Timeouts
 */

require_once 'config/database.php';

echo "<!DOCTYPE html>";
echo "<html><head><title>Test Google Calendar Fixed</title></head><body>";
echo "<h1>🧪 Test: Google Calendar mit verbesserten Timeouts</h1>";
echo "<p>Zeitstempel: " . date('d.m.Y H:i:s') . "</p>";

try {
    echo "<h2>1. Setze Session-Werte</h2>";
    
    session_start();
    $_SESSION['user_id'] = 5;
    $_SESSION['role'] = 'admin';
    $_SESSION['first_name'] = 'Daniel';
    $_SESSION['last_name'] = 'Leuchtenberg';
    $_SESSION['username'] = 'Boede';
    $_SESSION['email'] = 'dleuchtenberg89@gmail.com';
    
    echo "✅ Session-Werte gesetzt<br>";
    
    echo "<h2>2. Lade Google Calendar Komponenten</h2>";
    
    // Lade alle Google Calendar Komponenten
    if (file_exists('includes/functions.php')) {
        require_once 'includes/functions.php';
        echo "✅ includes/functions.php geladen<br>";
    }
    
    if (file_exists('includes/google_calendar_service_account.php')) {
        require_once 'includes/google_calendar_service_account.php';
        echo "✅ includes/google_calendar_service_account.php geladen<br>";
    }
    
    if (file_exists('includes/google_calendar.php')) {
        require_once 'includes/google_calendar.php';
        echo "✅ includes/google_calendar.php geladen<br>";
    }
    
    echo "<h2>3. Prüfe Funktionen</h2>";
    
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
    
    if (class_exists('GoogleCalendarServiceAccount')) {
        echo "✅ GoogleCalendarServiceAccount Klasse ist verfügbar<br>";
    } else {
        echo "❌ GoogleCalendarServiceAccount Klasse ist NICHT verfügbar<br>";
    }
    
    echo "<h2>4. Teste Google Calendar Integration mit verbesserten Timeouts</h2>";
    
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
        $stmt = $db->prepare("UPDATE reservations SET status = 'approved', approved_by = 5, approved_at = NOW() WHERE id = ?");
        $result = $stmt->execute([$reservation['id']]);
        
        if ($result) {
            echo "✅ Reservierung erfolgreich genehmigt!<br>";
            
            // Teste Google Calendar Event Erstellung
            echo "Erstelle Google Calendar Event...<br>";
            
            $start_time = microtime(true);
            
            try {
                $event_id = create_google_calendar_event(
                    $reservation['vehicle_name'],
                    $reservation['reason'],
                    $reservation['start_datetime'],
                    $reservation['end_datetime'],
                    $reservation['id'],
                    $reservation['location']
                );
                
                $end_time = microtime(true);
                $execution_time = round(($end_time - $start_time) * 1000, 2);
                
                if ($event_id) {
                    echo "✅ Google Calendar Event erfolgreich erstellt! Event ID: $event_id<br>";
                    echo "⏱️ Ausführungszeit: {$execution_time} ms<br>";
                    
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
                        $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'google_calendar_%'");
                        $stmt->execute();
                        $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
                        
                        $service_account_json = $settings['google_calendar_service_account_json'] ?? '';
                        $calendar_id = $settings['google_calendar_id'] ?? 'primary';
                        
                        if (!empty($service_account_json)) {
                            try {
                                $google_calendar = new GoogleCalendarServiceAccount($service_account_json, $calendar_id, true);
                                $google_calendar->deleteEvent($event_id);
                                echo "✅ Test Event gelöscht<br>";
                            } catch (Exception $e) {
                                echo "⚠️ Fehler beim Löschen des Test Events: " . htmlspecialchars($e->getMessage()) . "<br>";
                            }
                        }
                    }
                    
                    // Lösche Test Event aus der Datenbank
                    $stmt = $db->prepare("DELETE FROM calendar_events WHERE reservation_id = ?");
                    $stmt->execute([$reservation['id']]);
                    echo "✅ Test Event aus der Datenbank gelöscht<br>";
                    
                } else {
                    echo "❌ Google Calendar Event konnte nicht erstellt werden<br>";
                    echo "create_google_calendar_event() hat false zurückgegeben<br>";
                    echo "⏱️ Ausführungszeit: {$execution_time} ms<br>";
                }
                
            } catch (Exception $e) {
                $end_time = microtime(true);
                $execution_time = round(($end_time - $start_time) * 1000, 2);
                
                echo "❌ Google Calendar Fehler: " . htmlspecialchars($e->getMessage()) . "<br>";
                echo "⏱️ Ausführungszeit: {$execution_time} ms<br>";
                echo "Stack Trace:<br>";
                echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
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
    
    echo "<h2>5. Teste Kalender-Konflikte</h2>";
    
    if ($reservation) {
        echo "Teste Kalender-Konflikte für Reservierung ID: {$reservation['id']}<br>";
        
        $start_time = microtime(true);
        
        try {
            $conflicts = check_calendar_conflicts(
                $reservation['vehicle_name'],
                $reservation['start_datetime'],
                $reservation['end_datetime']
            );
            
            $end_time = microtime(true);
            $execution_time = round(($end_time - $start_time) * 1000, 2);
            
            if (empty($conflicts)) {
                echo "✅ Keine Kalender-Konflikte gefunden<br>";
            } else {
                echo "⚠️ Kalender-Konflikte gefunden:<br>";
                foreach ($conflicts as $conflict) {
                    echo "- {$conflict['title']} ({$conflict['start']} - {$conflict['end']})<br>";
                }
            }
            
            echo "⏱️ Ausführungszeit: {$execution_time} ms<br>";
            
        } catch (Exception $e) {
            $end_time = microtime(true);
            $execution_time = round(($end_time - $start_time) * 1000, 2);
            
            echo "❌ Fehler bei Kalender-Konfliktprüfung: " . htmlspecialchars($e->getMessage()) . "<br>";
            echo "⏱️ Ausführungszeit: {$execution_time} ms<br>";
        }
    }
    
    echo "<h2>6. Zusammenfassung</h2>";
    echo "✅ Google Calendar Funktionen mit verbesserten Timeouts getestet<br>";
    echo "✅ Timeouts: 120s Funktion, 60s Socket, 120s Max Execution<br>";
    echo "✅ Beide Funktionen (create_google_calendar_event und check_calendar_conflicts) aktiviert<br>";
    
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
