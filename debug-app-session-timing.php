<?php
/**
 * Debug: App Session und Timing - Warum funktioniert es nicht in der echten App?
 */

session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';

echo "<!DOCTYPE html>";
echo "<html><head><title>Debug App Session Timing</title></head><body>";
echo "<h1>🔍 Debug: App Session und Timing - Warum funktioniert es nicht in der echten App?</h1>";
echo "<p>Zeitstempel: " . date('d.m.Y H:i:s') . "</p>";

try {
    echo "<h2>1. Simuliere exakte App-Umgebung mit Timing</h2>";
    
    // Session-Fix für die App (wie im Dashboard)
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
        $stmt = $db->query("SELECT id, username, email, user_role, is_admin, role, first_name, last_name FROM users WHERE user_role = 'admin' OR role = 'admin' OR is_admin = 1 LIMIT 1");
        $admin_user = $stmt->fetch();
        
        if ($admin_user) {
            $_SESSION['user_id'] = $admin_user['id'];
            $_SESSION['role'] = 'admin';
            $_SESSION['first_name'] = $admin_user['first_name'];
            $_SESSION['last_name'] = $admin_user['last_name'];
            $_SESSION['username'] = $admin_user['username'];
            $_SESSION['email'] = $admin_user['email'];
            echo "✅ Session-Werte gesetzt<br>";
        }
    } else {
        echo "✅ Session-Werte bereits gesetzt<br>";
    }
    
    echo "Session-Werte:<br>";
    echo "- user_id: " . ($_SESSION['user_id'] ?? 'Nicht gesetzt') . "<br>";
    echo "- role: " . ($_SESSION['role'] ?? 'Nicht gesetzt') . "<br>";
    
    echo "<h2>2. Teste Google Calendar Funktion direkt</h2>";
    
    // Teste Google Calendar Funktion direkt
    if (function_exists('create_google_calendar_event')) {
        echo "✅ create_google_calendar_event Funktion ist verfügbar<br>";
        
        // Teste mit einfachen Parametern
        $test_vehicle = 'Test Fahrzeug';
        $test_reason = 'Debug Test - ' . date('H:i:s');
        $test_start = date('Y-m-d H:i:s', strtotime('+1 hour'));
        $test_end = date('Y-m-d H:i:s', strtotime('+2 hours'));
        $test_location = 'Test Ort';
        
        echo "Teste Google Calendar Event Erstellung mit:<br>";
        echo "- vehicle_name: $test_vehicle<br>";
        echo "- reason: $test_reason<br>";
        echo "- start_datetime: $test_start<br>";
        echo "- end_datetime: $test_end<br>";
        echo "- reservation_id: 999999 (Test)<br>";
        echo "- location: $test_location<br>";
        
        $start_time = microtime(true);
        
        try {
            $event_id = create_google_calendar_event(
                $test_vehicle,
                $test_reason,
                $test_start,
                $test_end,
                999999, // Test ID
                $test_location
            );
            
            $end_time = microtime(true);
            $execution_time = round(($end_time - $start_time) * 1000, 2);
            
            if ($event_id) {
                echo "✅ Google Calendar Event erfolgreich erstellt! Event ID: $event_id<br>";
                echo "⏱️ Ausführungszeit: {$execution_time} ms<br>";
                
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
                
            } else {
                echo "❌ Google Calendar Event konnte nicht erstellt werden<br>";
                echo "create_google_calendar_event() hat false zurückgegeben<br>";
                echo "⏱️ Ausführungszeit: {$execution_time} ms<br>";
                
                // Detaillierte Fehleranalyse
                echo "<h4>Detaillierte Fehleranalyse:</h4>";
                
                // Teste Google Calendar Einstellungen
                $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'google_calendar_%'");
                $stmt->execute();
                $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
                
                echo "Google Calendar Einstellungen:<br>";
                echo "- auth_type: " . ($settings['google_calendar_auth_type'] ?? 'Nicht gesetzt') . "<br>";
                echo "- calendar_id: " . ($settings['google_calendar_id'] ?? 'Nicht gesetzt') . "<br>";
                echo "- service_account_json: " . (isset($settings['google_calendar_service_account_json']) ? 'Gesetzt (' . strlen($settings['google_calendar_service_account_json']) . ' Zeichen)' : 'Nicht gesetzt') . "<br>";
                
                // Teste Service Account Initialisierung
                if (class_exists('GoogleCalendarServiceAccount')) {
                    $service_account_json = $settings['google_calendar_service_account_json'] ?? '';
                    $calendar_id = $settings['google_calendar_id'] ?? 'primary';
                    
                    if (!empty($service_account_json)) {
                        echo "Teste Service Account Initialisierung...<br>";
                        try {
                            $google_calendar = new GoogleCalendarServiceAccount($service_account_json, $calendar_id, true);
                            echo "✅ Service Account initialisiert<br>";
                            
                            // Teste Access Token
                            echo "Teste Access Token...<br>";
                            $access_token = $google_calendar->getAccessToken();
                            if ($access_token) {
                                echo "✅ Access Token erhalten: " . substr($access_token, 0, 20) . "...<br>";
                            } else {
                                echo "❌ Access Token konnte nicht erhalten werden<br>";
                            }
                            
                        } catch (Exception $e) {
                            echo "❌ Fehler bei Service Account: " . htmlspecialchars($e->getMessage()) . "<br>";
                        }
                    } else {
                        echo "❌ Service Account JSON ist leer<br>";
                    }
                } else {
                    echo "❌ GoogleCalendarServiceAccount Klasse ist nicht verfügbar<br>";
                }
            }
            
        } catch (Exception $e) {
            $end_time = microtime(true);
            $execution_time = round(($end_time - $start_time) * 1000, 2);
            
            echo "❌ Google Calendar Fehler: " . htmlspecialchars($e->getMessage()) . "<br>";
            echo "⏱️ Ausführungszeit: {$execution_time} ms<br>";
            echo "Stack Trace:<br>";
            echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
        }
        
    } else {
        echo "❌ create_google_calendar_event Funktion ist NICHT verfügbar<br>";
    }
    
    echo "<h2>3. Teste mit echter Reservierung</h2>";
    
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
        echo "Teste mit echter Reservierung ID: {$reservation['id']}<br>";
        echo "Fahrzeug: {$reservation['vehicle_name']}<br>";
        echo "Grund: {$reservation['reason']}<br>";
        echo "Start: {$reservation['start_datetime']}<br>";
        echo "Ende: {$reservation['end_datetime']}<br>";
        echo "Ort: {$reservation['location']}<br>";
        
        // Simuliere exakte App-Genehmigung
        $reservation_id = $reservation['id'];
        
        echo "<h3>Schritt 1: Reservierung genehmigen</h3>";
        $stmt = $db->prepare("UPDATE reservations SET status = 'approved', approved_by = 5, approved_at = NOW() WHERE id = ?");
        $result = $stmt->execute([$reservation_id]);
        
        if ($result) {
            echo "✅ Reservierung erfolgreich genehmigt!<br>";
            
            echo "<h3>Schritt 2: Reservierung aus Datenbank laden</h3>";
            $stmt = $db->prepare("SELECT r.*, v.name as vehicle_name FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id WHERE r.id = ?");
            $stmt->execute([$reservation_id]);
            $reservation_data = $stmt->fetch();
            
            if ($reservation_data) {
                echo "✅ Reservierung aus Datenbank geladen<br>";
                
                echo "<h3>Schritt 3: Google Calendar Event erstellen (mit Timing)</h3>";
                
                if (function_exists('create_google_calendar_event')) {
                    echo "✅ create_google_calendar_event Funktion ist verfügbar<br>";
                    
                    $start_time = microtime(true);
                    
                    try {
                        echo "Rufe create_google_calendar_event auf...<br>";
                        
                        $event_id = create_google_calendar_event(
                            $reservation_data['vehicle_name'],
                            $reservation_data['reason'],
                            $reservation_data['start_datetime'],
                            $reservation_data['end_datetime'],
                            $reservation_data['id'],
                            $reservation_data['location']
                        );
                        
                        $end_time = microtime(true);
                        $execution_time = round(($end_time - $start_time) * 1000, 2);
                        
                        if ($event_id) {
                            echo "✅ Google Calendar Event erfolgreich erstellt! Event ID: $event_id<br>";
                            echo "⏱️ Ausführungszeit: {$execution_time} ms<br>";
                            
                            // Prüfe ob Event in der Datenbank gespeichert wurde
                            $stmt = $db->prepare("SELECT * FROM calendar_events WHERE reservation_id = ?");
                            $stmt->execute([$reservation_data['id']]);
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
                            $stmt->execute([$reservation_data['id']]);
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
                    
                } else {
                    echo "❌ create_google_calendar_event Funktion ist NICHT verfügbar<br>";
                }
                
            } else {
                echo "❌ Reservierung konnte nicht aus der Datenbank geladen werden<br>";
            }
            
            // Setze zurück für weiteren Test
            $stmt = $db->prepare("UPDATE reservations SET status = 'pending', approved_by = NULL, approved_at = NULL WHERE id = ?");
            $stmt->execute([$reservation_id]);
            echo "✅ Reservierung zurückgesetzt für weiteren Test<br>";
            
        } else {
            echo "❌ Fehler bei der Genehmigung!<br>";
        }
    } else {
        echo "ℹ️ Keine ausstehenden Reservierungen zum Testen gefunden<br>";
    }
    
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
