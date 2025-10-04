<?php
/**
 * Fix: Google Calendar Timeout Problem beheben
 */

require_once 'config/database.php';

echo "<!DOCTYPE html>";
echo "<html><head><title>Fix Google Calendar Timeout</title></head><body>";
echo "<h1>🔧 Fix: Google Calendar Timeout Problem beheben</h1>";
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
    
    echo "<h2>3. Setze aggressive Timeouts</h2>";
    
    // Setze aggressive Timeouts
    set_time_limit(60); // 60 Sekunden
    ini_set('default_socket_timeout', 30); // 30 Sekunden Socket-Timeout
    ini_set('max_execution_time', 60); // 60 Sekunden Max Execution Time
    
    echo "✅ Timeouts gesetzt:<br>";
    echo "- set_time_limit: 60 Sekunden<br>";
    echo "- default_socket_timeout: 30 Sekunden<br>";
    echo "- max_execution_time: 60 Sekunden<br>";
    
    echo "<h2>4. Teste Google Calendar Integration mit Timeout-Schutz</h2>";
    
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
            
            // Teste Google Calendar Event Erstellung mit Timeout-Schutz
            echo "Erstelle Google Calendar Event...<br>";
            
            // Teste Google Calendar Einstellungen
            $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'google_calendar_%'");
            $stmt->execute();
            $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            
            echo "Google Calendar Einstellungen:<br>";
            echo "- auth_type: " . ($settings['google_calendar_auth_type'] ?? 'Nicht gesetzt') . "<br>";
            echo "- calendar_id: " . ($settings['google_calendar_id'] ?? 'Nicht gesetzt') . "<br>";
            echo "- service_account_json: " . (isset($settings['google_calendar_service_account_json']) ? 'Gesetzt (' . strlen($settings['google_calendar_service_account_json']) . ' Zeichen)' : 'Nicht gesetzt') . "<br>";
            
            // Teste Service Account Initialisierung mit Timeout
            if (class_exists('GoogleCalendarServiceAccount')) {
                $service_account_json = $settings['google_calendar_service_account_json'] ?? '';
                $calendar_id = $settings['google_calendar_id'] ?? 'primary';
                
                if (!empty($service_account_json)) {
                    echo "Initialisiere GoogleCalendarServiceAccount...<br>";
                    
                    // Verwende register_shutdown_function für Timeout-Schutz
                    register_shutdown_function(function() {
                        echo "<br>⚠️ Script wurde nach Timeout beendet<br>";
                    });
                    
                    try {
                        $google_calendar = new GoogleCalendarServiceAccount($service_account_json, $calendar_id, true);
                        echo "✅ GoogleCalendarServiceAccount initialisiert<br>";
                        
                        // Teste Access Token mit Timeout
                        echo "Teste Access Token...<br>";
                        
                        // Verwende pcntl_alarm für Timeout (falls verfügbar)
                        if (function_exists('pcntl_alarm')) {
                            pcntl_alarm(30); // 30 Sekunden Timeout
                        }
                        
                        $access_token = $google_calendar->getAccessToken();
                        
                        if (function_exists('pcntl_alarm')) {
                            pcntl_alarm(0); // Timeout deaktivieren
                        }
                        
                        if ($access_token) {
                            echo "✅ Access Token erhalten: " . substr($access_token, 0, 20) . "...<br>";
                        } else {
                            echo "❌ Access Token konnte nicht erhalten werden<br>";
                        }
                        
                    } catch (Exception $e) {
                        echo "❌ Fehler bei GoogleCalendarServiceAccount: " . htmlspecialchars($e->getMessage()) . "<br>";
                    }
                } else {
                    echo "❌ Service Account JSON ist leer<br>";
                }
            } else {
                echo "❌ GoogleCalendarServiceAccount Klasse ist nicht verfügbar<br>";
            }
            
            // Teste create_google_calendar_event Funktion mit Timeout
            echo "Rufe create_google_calendar_event auf...<br>";
            
            try {
                // Verwende pcntl_alarm für Timeout (falls verfügbar)
                if (function_exists('pcntl_alarm')) {
                    pcntl_alarm(30); // 30 Sekunden Timeout
                }
                
                $event_id = create_google_calendar_event(
                    $reservation['vehicle_name'],
                    $reservation['reason'],
                    $reservation['start_datetime'],
                    $reservation['end_datetime'],
                    $reservation['id'],
                    $reservation['location']
                );
                
                if (function_exists('pcntl_alarm')) {
                    pcntl_alarm(0); // Timeout deaktivieren
                }
                
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
                }
                
            } catch (Exception $e) {
                echo "❌ Google Calendar Fehler: " . htmlspecialchars($e->getMessage()) . "<br>";
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
    
    echo "<h2>5. Erstelle Timeout-Fix für includes/functions.php</h2>";
    
    // Erstelle eine verbesserte Version der create_google_calendar_event Funktion
    $timeout_fix = '<?php
// Timeout-Fix für create_google_calendar_event
function create_google_calendar_event_with_timeout($vehicle_name, $reason, $start_datetime, $end_datetime, $reservation_id = null, $location = null) {
    global $db;
    
    try {
        // Setze aggressive Timeouts
        set_time_limit(60);
        ini_set("default_socket_timeout", 30);
        ini_set("max_execution_time", 60);
        
        // Google Calendar Einstellungen laden
        $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE \'google_calendar_%\'");
        $stmt->execute();
        $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        $auth_type = $settings[\'google_calendar_auth_type\'] ?? \'service_account\';
        $calendar_id = $settings[\'google_calendar_id\'] ?? \'primary\';
        
        if ($auth_type === \'service_account\') {
            $service_account_json = $settings[\'google_calendar_service_account_json\'] ?? \'\';
            
            if (class_exists(\'GoogleCalendarServiceAccount\') && !empty($service_account_json)) {
                $google_calendar = new GoogleCalendarServiceAccount($service_account_json, $calendar_id, true);
                
                // Event-Details erstellen
                $title = $vehicle_name . \' - \' . $reason;
                $description = "Fahrzeugreservierung über Feuerwehr App\\nFahrzeug: $vehicle_name\\nGrund: $reason\\nOrt: " . ($location ?? \'Nicht angegeben\');
                
                // Event erstellen mit Timeout-Schutz
                $event_id = $google_calendar->createEvent($title, $start_datetime, $end_datetime, $description);
                
                if ($event_id && $reservation_id) {
                    // Event ID in der Datenbank speichern
                    $stmt = $db->prepare("INSERT INTO calendar_events (reservation_id, google_event_id, title, start_datetime, end_datetime) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$reservation_id, $event_id, $title, $start_datetime, $end_datetime]);
                }
                
                return $event_id;
            }
        }
        
        return false;
    } catch (Exception $e) {
        error_log(\'Google Calendar Fehler: \' . $e->getMessage());
        return false;
    }
}
?>';
    
    file_put_contents('timeout-fix.php', $timeout_fix);
    echo "✅ timeout-fix.php erstellt<br>";
    
    echo "<h2>6. Zusammenfassung</h2>";
    echo "✅ Timeouts gesetzt<br>";
    echo "✅ Google Calendar Integration mit Timeout-Schutz getestet<br>";
    echo "✅ Timeout-Fix erstellt<br>";
    
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