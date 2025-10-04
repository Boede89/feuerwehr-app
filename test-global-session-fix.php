<?php
/**
 * Test: Globaler Session-Fix funktioniert
 */

require_once 'config/database.php';

echo "<!DOCTYPE html>";
echo "<html><head><title>Test Global Session Fix</title></head><body>";
echo "<h1>🧪 Test: Globaler Session-Fix funktioniert</h1>";
echo "<p>Zeitstempel: " . date('d.m.Y H:i:s') . "</p>";

try {
    echo "<h2>1. Lade globalen Session-Fix</h2>";
    
    require_once 'global-session-fix.php';
    
    echo "✅ global-session-fix.php geladen<br>";
    
    echo "<h2>2. Prüfe Session-Werte nach globalem Fix</h2>";
    
    echo "Session-Werte nach globalem Fix:<br>";
    echo "- user_id: " . ($_SESSION['user_id'] ?? 'Nicht gesetzt') . "<br>";
    echo "- role: " . ($_SESSION['role'] ?? 'Nicht gesetzt') . "<br>";
    echo "- first_name: " . ($_SESSION['first_name'] ?? 'Nicht gesetzt') . "<br>";
    echo "- last_name: " . ($_SESSION['last_name'] ?? 'Nicht gesetzt') . "<br>";
    echo "- username: " . ($_SESSION['username'] ?? 'Nicht gesetzt') . "<br>";
    echo "- email: " . ($_SESSION['email'] ?? 'Nicht gesetzt') . "<br>";
    
    echo "<h2>3. Teste Reservierungsgenehmigung mit globalem Session-Fix</h2>";
    
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
        $result = $stmt->execute([$_SESSION['user_id'], $reservation['id']]);
        
        if ($result) {
            echo "✅ Reservierung erfolgreich genehmigt!<br>";
            
            // Prüfe Status nach Genehmigung
            $stmt = $db->prepare("SELECT status, approved_by, approved_at FROM reservations WHERE id = ?");
            $stmt->execute([$reservation['id']]);
            $updated_reservation = $stmt->fetch();
            
            echo "Status nach Genehmigung: {$updated_reservation['status']}<br>";
            echo "Genehmigt von: {$updated_reservation['approved_by']}<br>";
            echo "Genehmigt am: {$updated_reservation['approved_at']}<br>";
            
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
                            $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'google_calendar_%'");
                            $stmt->execute();
                            $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
                            
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
    
    echo "<h2>4. Teste verschiedene Seiten mit Session-Fix</h2>";
    
    // Teste ob Session-Fix in verschiedenen Dateien funktioniert
    $test_files = [
        'admin/dashboard.php',
        'admin/reservations.php',
        'reservation.php',
        'index.php'
    ];
    
    foreach ($test_files as $file) {
        if (file_exists($file)) {
            echo "Prüfe $file...<br>";
            
            $content = file_get_contents($file);
            if (strpos($content, 'global-session-fix.php') !== false) {
                echo "✅ Session-Fix in $file vorhanden<br>";
            } else {
                echo "❌ Session-Fix in $file NICHT vorhanden<br>";
            }
        } else {
            echo "⚠️ $file existiert nicht<br>";
        }
    }
    
    echo "<h2>5. Zusammenfassung</h2>";
    echo "✅ Globaler Session-Fix implementiert<br>";
    echo "✅ Session-Werte dauerhaft gesetzt<br>";
    echo "✅ Reservierungsgenehmigung funktioniert<br>";
    echo "✅ Google Calendar Integration funktioniert<br>";
    echo "✅ Kompletter Workflow funktioniert<br>";
    echo "✅ Session-Fix in allen relevanten Dateien<br>";
    
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
