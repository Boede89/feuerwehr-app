<?php
/**
 * Debug für Error Handling in der echten App
 */

session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';

echo "<h1>Debug: Error Handling in der echten App</h1>";
echo "<p>Zeitstempel: " . date('d.m.Y H:i:s') . "</p>";

// 1. Prüfe Session
echo "<h2>1. Session prüfen</h2>";
if (isset($_SESSION['user_id'])) {
    echo "<p style='color: green;'>✅ Eingeloggt als User ID: " . $_SESSION['user_id'] . "</p>";
} else {
    echo "<p style='color: red;'>❌ Nicht eingeloggt</p>";
    exit;
}

// 2. Teste Error Handling mit verschiedenen Szenarien
echo "<h2>2. Teste Error Handling mit verschiedenen Szenarien</h2>";

// Test 1: Normale Parameter
echo "<h3>Test 1: Normale Parameter</h3>";
try {
    $stmt = $db->prepare("SELECT id, name FROM vehicles WHERE is_active = 1 LIMIT 1");
    $stmt->execute();
    $vehicle = $stmt->fetch();
    
    if ($vehicle) {
        $test_start = date('Y-m-d H:i:s', strtotime('+1 hour'));
        $test_end = date('Y-m-d H:i:s', strtotime('+2 hours'));
        
        $stmt = $db->prepare("INSERT INTO reservations (vehicle_id, requester_name, requester_email, reason, start_datetime, end_datetime, status, created_at) VALUES (?, ?, ?, ?, ?, ?, 'approved', NOW())");
        $stmt->execute([$vehicle['id'], 'Error Test 1', 'error1@test.com', 'Error Test 1 für Google Calendar', $test_start, $test_end]);
        $test_reservation_id = $db->lastInsertId();
        
        echo "<p>✅ Test-Reservierung erstellt (ID: $test_reservation_id)</p>";
        
        $event_id = create_google_calendar_event(
            $vehicle['name'],
            'Error Test 1 für Google Calendar',
            $test_start,
            $test_end,
            $test_reservation_id
        );
        
        if ($event_id) {
            echo "<p style='color: green;'>✅ Test 1 erfolgreich - Event ID: " . htmlspecialchars($event_id) . "</p>";
            
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
            echo "<p style='color: red;'>❌ Test 1 fehlgeschlagen - Event konnte nicht erstellt werden</p>";
        }
        
        // Test-Reservierung löschen
        $stmt = $db->prepare("DELETE FROM reservations WHERE id = ?");
        $stmt->execute([$test_reservation_id]);
        echo "<p>✅ Test-Reservierung gelöscht</p>";
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Exception in Test 1: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><strong>Stack Trace:</strong></p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}

// Test 2: Leere Parameter
echo "<h3>Test 2: Leere Parameter</h3>";
try {
    $event_id = create_google_calendar_event('', '', '', '', null);
    
    if ($event_id === false) {
        echo "<p style='color: orange;'>⚠️ Test 2 - Event konnte nicht erstellt werden (erwartet bei leeren Parametern)</p>";
    } else {
        echo "<p style='color: green;'>✅ Test 2 - Event erstellt: " . htmlspecialchars($event_id) . "</p>";
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Exception in Test 2: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><strong>Stack Trace:</strong></p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}

// Test 3: Ungültige Datumsformate
echo "<h3>Test 3: Ungültige Datumsformate</h3>";
try {
    $event_id = create_google_calendar_event(
        'Test Vehicle',
        'Test Reason',
        'invalid-date',
        'invalid-date',
        999999
    );
    
    if ($event_id === false) {
        echo "<p style='color: orange;'>⚠️ Test 3 - Event konnte nicht erstellt werden (erwartet bei ungültigen Datumsformaten)</p>";
    } else {
        echo "<p style='color: green;'>✅ Test 3 - Event erstellt: " . htmlspecialchars($event_id) . "</p>";
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Exception in Test 3: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><strong>Stack Trace:</strong></p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}

// 3. Teste die echte admin/reservations.php Logik
echo "<h2>3. Teste die echte admin/reservations.php Logik</h2>";

try {
    // Test-Reservierung erstellen
    $stmt = $db->prepare("SELECT id, name FROM vehicles WHERE is_active = 1 LIMIT 1");
    $stmt->execute();
    $vehicle = $stmt->fetch();
    
    if ($vehicle) {
        $test_start = date('Y-m-d H:i:s', strtotime('+1 hour'));
        $test_end = date('Y-m-d H:i:s', strtotime('+2 hours'));
        
        $stmt = $db->prepare("INSERT INTO reservations (vehicle_id, requester_name, requester_email, reason, start_datetime, end_datetime, status, created_at) VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())");
        $stmt->execute([$vehicle['id'], 'Admin Logic Test', 'admin@test.com', 'Admin Logic Test für Google Calendar', $test_start, $test_end]);
        $test_reservation_id = $db->lastInsertId();
        
        echo "<p>✅ Test-Reservierung erstellt (ID: $test_reservation_id)</p>";
        
        // Simuliere die echte admin/reservations.php Logik
        $reservation_id = $test_reservation_id;
        
        // 1. Reservierung genehmigen
        $stmt = $db->prepare("UPDATE reservations SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?");
        $stmt->execute([$_SESSION['user_id'], $reservation_id]);
        
        $message = "Reservierung erfolgreich genehmigt.";
        echo "<p>✅ $message</p>";
        
        // 2. Google Calendar Event erstellen (exakt wie in admin/reservations.php)
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
                    
                    $event_id = create_google_calendar_event(
                        $reservation['vehicle_name'],
                        $reservation['reason'],
                        $reservation['start_datetime'],
                        $reservation['end_datetime'],
                        $reservation['id']
                    );
                    
                    if ($event_id) {
                        echo "<p style='color: green;'>✅ Google Calendar Event erfolgreich erstellt! Event ID: " . htmlspecialchars($event_id) . "</p>";
                        $message .= " Google Calendar Event wurde erstellt.";
                        
                        // Prüfe ob Event in der Datenbank gespeichert wurde
                        $stmt = $db->prepare("SELECT * FROM calendar_events WHERE reservation_id = ?");
                        $stmt->execute([$reservation_id]);
                        $event_record = $stmt->fetch();
                        
                        if ($event_record) {
                            echo "<p style='color: green;'>✅ Event in der Datenbank gespeichert</p>";
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
                        $message .= " Warnung: Google Calendar Event konnte nicht erstellt werden.";
                    }
                } else {
                    echo "<p style='color: red;'>❌ create_google_calendar_event Funktion ist NICHT verfügbar</p>";
                    $message .= " Warnung: Google Calendar Funktion nicht verfügbar.";
                }
            } else {
                echo "<p style='color: red;'>❌ Reservierung nicht gefunden</p>";
                $message .= " Warnung: Reservierung nicht gefunden für Google Calendar.";
            }
        } catch (Exception $e) {
            echo "<p style='color: red;'>❌ Fehler bei der Google Calendar Integration: " . htmlspecialchars($e->getMessage()) . "</p>";
            echo "<p><strong>Stack Trace:</strong></p>";
            echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
            $message .= " Warnung: Google Calendar Fehler - " . $e->getMessage();
        }
        
        echo "<p><strong>Finale Meldung:</strong> $message</p>";
        
        // Test-Reservierung löschen
        $stmt = $db->prepare("DELETE FROM reservations WHERE id = ?");
        $stmt->execute([$test_reservation_id]);
        echo "<p>✅ Test-Reservierung gelöscht</p>";
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim Test: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><strong>Stack Trace:</strong></p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}

echo "<hr>";
echo "<p><strong>Debug abgeschlossen um:</strong> " . date('d.m.Y H:i:s') . "</p>";
?>
