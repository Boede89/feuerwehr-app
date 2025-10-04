<?php
/**
 * Debug für manuellen Google Calendar Button
 */

session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';

echo "<h1>Debug: Manueller Google Calendar Button</h1>";
echo "<p>Zeitstempel: " . date('d.m.Y H:i:s') . "</p>";

// 1. Prüfe Session
echo "<h2>1. Session prüfen</h2>";
if (isset($_SESSION['user_id'])) {
    echo "<p style='color: green;'>✅ Eingeloggt als User ID: " . $_SESSION['user_id'] . "</p>";
} else {
    echo "<p style='color: red;'>❌ Nicht eingeloggt</p>";
    exit;
}

// 2. Simuliere manuellen Button Klick
echo "<h2>2. Simuliere manuellen Button Klick</h2>";

// Test-Reservierung erstellen
try {
    $stmt = $db->prepare("SELECT id, name FROM vehicles WHERE is_active = 1 LIMIT 1");
    $stmt->execute();
    $vehicle = $stmt->fetch();
    
    if ($vehicle) {
        $test_start = date('Y-m-d H:i:s', strtotime('+1 hour'));
        $test_end = date('Y-m-d H:i:s', strtotime('+2 hours'));
        
        $stmt = $db->prepare("INSERT INTO reservations (vehicle_id, requester_name, requester_email, reason, start_datetime, end_datetime, status, created_at) VALUES (?, ?, ?, ?, ?, ?, 'approved', NOW())");
        $stmt->execute([$vehicle['id'], 'Manual Button Test', 'manual@test.com', 'Manual Button Test für Google Calendar', $test_start, $test_end]);
        $test_reservation_id = $db->lastInsertId();
        
        echo "<p>✅ Test-Reservierung erstellt (ID: $test_reservation_id)</p>";
        
        // Simuliere manuellen Button Klick (exakt wie in admin/reservations.php)
        $_POST['action'] = 'create_calendar_event';
        $_POST['reservation_id'] = $test_reservation_id;
        $_POST['csrf_token'] = generate_csrf_token();
        
        echo "<p><strong>POST-Daten:</strong></p>";
        echo "<ul>";
        echo "<li><strong>action:</strong> " . htmlspecialchars($_POST['action']) . "</li>";
        echo "<li><strong>reservation_id:</strong> " . htmlspecialchars($_POST['reservation_id']) . "</li>";
        echo "<li><strong>csrf_token:</strong> " . htmlspecialchars($_POST['csrf_token']) . "</li>";
        echo "</ul>";
        
        // Simuliere die EXAKTE admin/reservations.php Logik für manuellen Button
        $reservation_id = (int)$_POST['reservation_id'];
        $action = $_POST['action'];
        
        echo "<p><strong>Simuliere manuellen Button für Reservierung #$reservation_id</strong></p>";
        
        // Prüfe CSRF Token
        if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
            echo "<p style='color: red;'>❌ Ungültiger Sicherheitstoken</p>";
            exit;
        } else {
            echo "<p style='color: green;'>✅ CSRF Token ist gültig</p>";
        }
        
        try {
            if ($action == 'create_calendar_event') {
                echo "<p><strong>Manueller Google Calendar Event erstellen...</strong></p>";
                
                // Manueller Google Calendar Event erstellen (exakt wie in admin/reservations.php)
                try {
                    $stmt = $db->prepare("SELECT r.*, v.name as vehicle_name FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id WHERE r.id = ?");
                    $stmt->execute([$reservation_id]);
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
                        
                        if (function_exists('create_google_calendar_event')) {
                            echo "<p style='color: green;'>✅ create_google_calendar_event Funktion ist verfügbar</p>";
                            
                            echo "<p><strong>Versuche Google Calendar Event zu erstellen...</strong></p>";
                            
                            // Detailliertes Logging
                            error_log("MANUAL CALENDAR: Versuche Event für Reservierung #$reservation_id zu erstellen");
                            error_log("MANUAL CALENDAR: Parameter - Fahrzeug: " . $reservation['vehicle_name'] . ", Grund: " . $reservation['reason']);
                            error_log("MANUAL CALENDAR: Parameter - Start: " . $reservation['start_datetime'] . ", Ende: " . $reservation['end_datetime']);
                            error_log("MANUAL CALENDAR: Parameter - Reservation ID: " . $reservation['id']);
                            
                            $event_id = create_google_calendar_event(
                                $reservation['vehicle_name'],
                                $reservation['reason'],
                                $reservation['start_datetime'],
                                $reservation['end_datetime'],
                                $reservation['id']
                            );
                            
                            error_log("MANUAL CALENDAR: Google Calendar Event Erstellung abgeschlossen. Event ID: " . ($event_id ?: 'NULL'));
                            
                            if ($event_id) {
                                echo "<p style='color: green;'>✅ Google Calendar Event erfolgreich erstellt! Event ID: " . htmlspecialchars($event_id) . "</p>";
                                $message = "Google Calendar Event wurde manuell erstellt.";
                                
                                // Prüfe ob Event in der Datenbank gespeichert wurde
                                $stmt = $db->prepare("SELECT * FROM calendar_events WHERE reservation_id = ?");
                                $stmt->execute([$reservation_id]);
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
                                $message = "Google Calendar Event konnte nicht erstellt werden.";
                            }
                        } else {
                            echo "<p style='color: red;'>❌ create_google_calendar_event Funktion ist NICHT verfügbar</p>";
                            $message = "Google Calendar Funktion nicht verfügbar.";
                        }
                    } else {
                        echo "<p style='color: red;'>❌ Reservierung nicht gefunden</p>";
                        $message = "Reservierung nicht gefunden.";
                    }
                } catch (Exception $e) {
                    echo "<p style='color: red;'>❌ Fehler bei der Google Calendar Integration: " . htmlspecialchars($e->getMessage()) . "</p>";
                    echo "<p><strong>Stack Trace:</strong></p>";
                    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
                    $message = "Google Calendar Fehler - " . $e->getMessage();
                }
                
                echo "<p><strong>Finale Meldung:</strong> $message</p>";
            }
        } catch (Exception $e) {
            echo "<p style='color: red;'>❌ Fehler bei der manuellen Event Erstellung: " . htmlspecialchars($e->getMessage()) . "</p>";
            echo "<p><strong>Stack Trace:</strong></p>";
            echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
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

// 3. Prüfe Error Logs
echo "<h2>3. Error Logs prüfen</h2>";

$log_files = [
    '/var/log/apache2/error.log',
    '/var/log/nginx/error.log',
    '/var/log/php_errors.log',
    '/tmp/php_errors.log',
    ini_get('error_log')
];

foreach ($log_files as $log_file) {
    if ($log_file && file_exists($log_file)) {
        $log_content = file_get_contents($log_file);
        $google_calendar_errors = [];
        
        $lines = explode("\n", $log_content);
        foreach ($lines as $line) {
            if (strpos($line, 'Google Calendar') !== false || strpos($line, 'create_google_calendar_event') !== false || strpos($line, 'MANUAL CALENDAR:') !== false) {
                $google_calendar_errors[] = $line;
            }
        }
        
        if (!empty($google_calendar_errors)) {
            echo "<p><strong>Log-Datei:</strong> " . htmlspecialchars($log_file) . "</p>";
            echo "<div style='background-color: #f8f9fa; padding: 10px; border-radius: 4px; max-height: 200px; overflow-y: auto;'>";
            foreach (array_slice($google_calendar_errors, -10) as $error) {
                echo "<p style='margin: 2px 0; font-family: monospace; font-size: 12px;'>" . htmlspecialchars($error) . "</p>";
            }
            echo "</div>";
        } else {
            echo "<p style='color: orange;'>⚠️ Keine Google Calendar Fehler in dieser Log-Datei gefunden</p>";
        }
    } else {
        echo "<p style='color: orange;'>⚠️ Log-Datei nicht gefunden: " . htmlspecialchars($log_file) . "</p>";
    }
}

echo "<hr>";
echo "<p><strong>Debug abgeschlossen um:</strong> " . date('d.m.Y H:i:s') . "</p>";
?>
