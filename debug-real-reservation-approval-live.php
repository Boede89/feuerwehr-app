<?php
/**
 * Debug für echte Live-Reservierungsgenehmigung
 */

session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';

echo "<h1>Debug: Echte Live-Reservierungsgenehmigung</h1>";
echo "<p>Zeitstempel: " . date('d.m.Y H:i:s') . "</p>";

// 1. Prüfe Session
echo "<h2>1. Session prüfen</h2>";
if (isset($_SESSION['user_id'])) {
    echo "<p style='color: green;'>✅ Eingeloggt als User ID: " . $_SESSION['user_id'] . "</p>";
} else {
    echo "<p style='color: red;'>❌ Nicht eingeloggt</p>";
    exit;
}

// 2. Prüfe Admin-Rechte
echo "<h2>2. Admin-Rechte prüfen</h2>";
if (has_admin_access()) {
    echo "<p style='color: green;'>✅ Admin-Zugriff vorhanden</p>";
} else {
    echo "<p style='color: red;'>❌ Kein Admin-Zugriff</p>";
    exit;
}

// 3. Prüfe ausstehende Reservierungen
echo "<h2>3. Ausstehende Reservierungen prüfen</h2>";

try {
    $stmt = $db->prepare("SELECT r.*, v.name as vehicle_name FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id WHERE r.status = 'pending' ORDER BY r.created_at DESC LIMIT 5");
    $stmt->execute();
    $pending_reservations = $stmt->fetchAll();
    
    if (empty($pending_reservations)) {
        echo "<p style='color: orange;'>⚠️ Keine ausstehende Reservierung gefunden. Erstelle eine Test-Reservierung...</p>";
        
        // Test-Reservierung erstellen
        $stmt = $db->prepare("SELECT id, name FROM vehicles WHERE is_active = 1 LIMIT 1");
        $stmt->execute();
        $vehicle = $stmt->fetch();
        
        if ($vehicle) {
            $test_start = date('Y-m-d H:i:s', strtotime('+1 hour'));
            $test_end = date('Y-m-d H:i:s', strtotime('+2 hours'));
            
            $stmt = $db->prepare("INSERT INTO reservations (vehicle_id, requester_name, requester_email, reason, start_datetime, end_datetime, status, created_at) VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())");
            $stmt->execute([$vehicle['id'], 'Live Test', 'live@test.com', 'Live Test für echte Genehmigung', $test_start, $test_end]);
            $test_reservation_id = $db->lastInsertId();
            
            echo "<p>✅ Test-Reservierung erstellt (ID: $test_reservation_id)</p>";
            
            // Test-Reservierung zu pending_reservations hinzufügen
            $stmt = $db->prepare("SELECT r.*, v.name as vehicle_name FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id WHERE r.id = ?");
            $stmt->execute([$test_reservation_id]);
            $test_reservation = $stmt->fetch();
            $pending_reservations = [$test_reservation];
        }
    }
    
    if (!empty($pending_reservations)) {
        echo "<p style='color: green;'>✅ " . count($pending_reservations) . " ausstehende Reservierungen gefunden</p>";
        
        foreach ($pending_reservations as $reservation) {
            echo "<div style='border: 1px solid #ddd; padding: 10px; margin: 5px 0; border-radius: 4px;'>";
            echo "<p><strong>ID:</strong> " . htmlspecialchars($reservation['id']) . "</p>";
            echo "<p><strong>Fahrzeug:</strong> " . htmlspecialchars($reservation['vehicle_name']) . "</p>";
            echo "<p><strong>Grund:</strong> " . htmlspecialchars($reservation['reason']) . "</p>";
            echo "<p><strong>Start:</strong> " . htmlspecialchars($reservation['start_datetime']) . "</p>";
            echo "<p><strong>Ende:</strong> " . htmlspecialchars($reservation['end_datetime']) . "</p>";
            echo "<p><strong>Status:</strong> " . htmlspecialchars($reservation['status']) . "</p>";
            echo "</div>";
        }
    } else {
        echo "<p style='color: red;'>❌ Keine ausstehende Reservierung gefunden</p>";
        exit;
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim Laden der Reservierungen: " . htmlspecialchars($e->getMessage()) . "</p>";
    exit;
}

// 4. Simuliere echte Reservierungsgenehmigung
echo "<h2>4. Simuliere echte Reservierungsgenehmigung</h2>";

if (!empty($pending_reservations)) {
    $reservation = $pending_reservations[0];
    $reservation_id = $reservation['id'];
    
    echo "<p><strong>Genehmige Reservierung #$reservation_id</strong></p>";
    
    try {
        // 1. Reservierung genehmigen
        $stmt = $db->prepare("UPDATE reservations SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?");
        $stmt->execute([$_SESSION['user_id'], $reservation_id]);
        
        $message = "Reservierung erfolgreich genehmigt.";
        echo "<p style='color: green;'>✅ $message</p>";
        
        // 2. Google Calendar Event erstellen (exakt wie in admin/reservations.php)
        echo "<p><strong>Google Calendar Event erstellen...</strong></p>";
        
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
                    error_log("LIVE RESERVATION DEBUG: Starte Google Calendar Event Erstellung für Reservierung #$reservation_id");
                    error_log("LIVE RESERVATION DEBUG: Parameter - Fahrzeug: " . $reservation['vehicle_name'] . ", Grund: " . $reservation['reason']);
                    error_log("LIVE RESERVATION DEBUG: Parameter - Start: " . $reservation['start_datetime'] . ", Ende: " . $reservation['end_datetime']);
                    error_log("LIVE RESERVATION DEBUG: Parameter - Reservation ID: " . $reservation['id']);
                    
                    $event_id = create_google_calendar_event(
                        $reservation['vehicle_name'],
                        $reservation['reason'],
                        $reservation['start_datetime'],
                        $reservation['end_datetime'],
                        $reservation['id']
                    );
                    
                    error_log("LIVE RESERVATION DEBUG: Google Calendar Event Erstellung abgeschlossen. Event ID: " . ($event_id ?: 'NULL'));
                    
                    if ($event_id) {
                        echo "<p style='color: green;'>✅ Google Calendar Event erfolgreich erstellt! Event ID: " . htmlspecialchars($event_id) . "</p>";
                        $message .= " Google Calendar Event wurde erstellt.";
                        
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
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Fehler bei der Reservierungsgenehmigung: " . htmlspecialchars($e->getMessage()) . "</p>";
        echo "<p><strong>Stack Trace:</strong></p>";
        echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    }
}

// 5. Prüfe Error Logs
echo "<h2>5. Error Logs prüfen</h2>";

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
            if (strpos($line, 'Google Calendar') !== false || strpos($line, 'create_google_calendar_event') !== false || strpos($line, 'LIVE RESERVATION DEBUG:') !== false) {
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
        }
    }
}

echo "<hr>";
echo "<p><strong>Debug abgeschlossen um:</strong> " . date('d.m.Y H:i:s') . "</p>";
?>
