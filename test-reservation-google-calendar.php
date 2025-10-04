<?php
/**
 * Test für Google Calendar Integration in Reservierungen
 */

require_once 'config/database.php';
require_once 'includes/functions.php';

echo "<h1>Google Calendar Integration Test für Reservierungen</h1>";
echo "<p>Zeitstempel: " . date('d.m.Y H:i:s') . "</p>";

// Test 1: Funktion verfügbar?
echo "<h2>1. Funktion Verfügbarkeit</h2>";
if (function_exists('create_google_calendar_event')) {
    echo "<p style='color: green;'>✅ create_google_calendar_event Funktion ist verfügbar</p>";
} else {
    echo "<p style='color: red;'>❌ create_google_calendar_event Funktion ist NICHT verfügbar</p>";
    exit;
}

// Test 2: Einstellungen laden
echo "<h2>2. Google Calendar Einstellungen</h2>";
try {
    $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'google_calendar_%'");
    $stmt->execute();
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    $auth_type = $settings['google_calendar_auth_type'] ?? 'service_account';
    $calendar_id = $settings['google_calendar_id'] ?? 'primary';
    $service_account_json = $settings['google_calendar_service_account_json'] ?? '';
    
    echo "<p><strong>Authentifizierungstyp:</strong> " . htmlspecialchars($auth_type) . "</p>";
    echo "<p><strong>Kalender ID:</strong> " . htmlspecialchars($calendar_id) . "</p>";
    echo "<p><strong>Service Account JSON:</strong> " . (empty($service_account_json) ? "❌ Nicht konfiguriert" : "✅ Konfiguriert (" . strlen($service_account_json) . " Zeichen)") . "</p>";
    
} catch(PDOException $e) {
    echo "<p style='color: red;'>❌ Fehler beim Laden der Einstellungen: " . $e->getMessage() . "</p>";
    exit;
}

// Test 3: Test Event erstellen
echo "<h2>3. Test Event erstellen</h2>";

$test_vehicle_name = "Test Fahrzeug - " . date('H:i:s');
$test_reason = "Google Calendar Integration Test";
$test_start = date('Y-m-d H:i:s', strtotime('+1 hour'));
$test_end = date('Y-m-d H:i:s', strtotime('+2 hours'));

echo "<p><strong>Test Parameter:</strong></p>";
echo "<ul>";
echo "<li><strong>Fahrzeug:</strong> " . htmlspecialchars($test_vehicle_name) . "</li>";
echo "<li><strong>Grund:</strong> " . htmlspecialchars($test_reason) . "</li>";
echo "<li><strong>Start:</strong> " . htmlspecialchars($test_start) . "</li>";
echo "<li><strong>Ende:</strong> " . htmlspecialchars($test_end) . "</li>";
echo "</ul>";

echo "<p><strong>Versuche Event zu erstellen...</strong></p>";

try {
    $event_id = create_google_calendar_event(
        $test_vehicle_name,
        $test_reason,
        $test_start,
        $test_end,
        null // Keine Reservation ID für Test
    );
    
    if ($event_id) {
        echo "<p style='color: green;'>✅ Event erfolgreich erstellt! Event ID: " . htmlspecialchars($event_id) . "</p>";
        
        // Event in der Datenbank prüfen
        echo "<h3>Event in der Datenbank prüfen</h3>";
        try {
            $stmt = $db->prepare("SELECT * FROM calendar_events WHERE google_event_id = ?");
            $stmt->execute([$event_id]);
            $event_record = $stmt->fetch();
            
            if ($event_record) {
                echo "<p style='color: green;'>✅ Event in der Datenbank gefunden</p>";
                echo "<ul>";
                echo "<li><strong>ID:</strong> " . htmlspecialchars($event_record['id']) . "</li>";
                echo "<li><strong>Google Event ID:</strong> " . htmlspecialchars($event_record['google_event_id']) . "</li>";
                echo "<li><strong>Titel:</strong> " . htmlspecialchars($event_record['title']) . "</li>";
                echo "<li><strong>Start:</strong> " . htmlspecialchars($event_record['start_datetime']) . "</li>";
                echo "<li><strong>Ende:</strong> " . htmlspecialchars($event_record['end_datetime']) . "</li>";
                echo "</ul>";
            } else {
                echo "<p style='color: orange;'>⚠️ Event nicht in der Datenbank gefunden (normal bei Test ohne Reservation ID)</p>";
            }
        } catch(PDOException $e) {
            echo "<p style='color: orange;'>⚠️ Fehler beim Prüfen der Datenbank: " . $e->getMessage() . "</p>";
        }
        
        // Event löschen
        echo "<h3>Test Event löschen</h3>";
        try {
            require_once 'includes/google_calendar_service_account.php';
            $google_calendar = new GoogleCalendarServiceAccount($service_account_json, $calendar_id, true);
            
            if ($google_calendar->deleteEvent($event_id)) {
                echo "<p style='color: green;'>✅ Test Event erfolgreich gelöscht</p>";
            } else {
                echo "<p style='color: orange;'>⚠️ Test Event konnte nicht gelöscht werden (muss manuell entfernt werden)</p>";
            }
        } catch (Exception $e) {
            echo "<p style='color: orange;'>⚠️ Fehler beim Löschen: " . htmlspecialchars($e->getMessage()) . "</p>";
        }
        
    } else {
        echo "<p style='color: red;'>❌ Event konnte nicht erstellt werden</p>";
        
        // Fehler-Logs prüfen
        echo "<h3>Mögliche Fehlerquellen:</h3>";
        echo "<ul>";
        echo "<li>Google Calendar Service Account nicht korrekt konfiguriert</li>";
        echo "<li>Kalender ID falsch</li>";
        echo "<li>Service Account hat keine Berechtigung für den Kalender</li>";
        echo "<li>Google Calendar API nicht aktiviert</li>";
        echo "</ul>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim Erstellen des Events: " . htmlspecialchars($e->getMessage()) . "</p>";
    
    // Detaillierte Fehleranalyse
    echo "<h3>Fehleranalyse:</h3>";
    $error_message = $e->getMessage();
    
    if (strpos($error_message, 'HTTP 401') !== false) {
        echo "<p style='color: red;'>🔍 Authentifizierungsfehler (401): Service Account ist möglicherweise nicht korrekt konfiguriert</p>";
    } elseif (strpos($error_message, 'HTTP 403') !== false) {
        echo "<p style='color: red;'>🔍 Berechtigungsfehler (403): Service Account hat keine Berechtigung für diesen Kalender</p>";
    } elseif (strpos($error_message, 'HTTP 404') !== false) {
        echo "<p style='color: red;'>🔍 Kalender nicht gefunden (404): Kalender ID ist möglicherweise falsch</p>";
    } elseif (strpos($error_message, 'JWT') !== false) {
        echo "<p style='color: red;'>🔍 JWT-Fehler: Private Key ist möglicherweise beschädigt</p>";
    } elseif (strpos($error_message, 'JSON') !== false) {
        echo "<p style='color: red;'>🔍 JSON-Fehler: Service Account JSON ist möglicherweise beschädigt</p>";
    }
}

// Test 4: Mit echter Reservierung testen
echo "<h2>4. Test mit echter Reservierung</h2>";

// Test-Reservierung erstellen
try {
    $stmt = $db->prepare("INSERT INTO reservations (user_id, vehicle_id, reason, start_datetime, end_datetime, status, created_at) VALUES (?, ?, ?, ?, ?, 'approved', NOW())");
    $stmt->execute([1, 1, 'Google Calendar Test Reservierung', $test_start, $test_end]);
    $test_reservation_id = $db->lastInsertId();
    
    echo "<p>✅ Test-Reservierung erstellt (ID: $test_reservation_id)</p>";
    
    // Fahrzeug-Name holen
    $stmt = $db->prepare("SELECT v.name FROM vehicles v WHERE v.id = 1");
    $stmt->execute();
    $vehicle_name = $stmt->fetchColumn();
    
    if ($vehicle_name) {
        echo "<p><strong>Fahrzeug:</strong> " . htmlspecialchars($vehicle_name) . "</p>";
        
        // Google Calendar Event mit Reservierung erstellen
        echo "<p><strong>Versuche Event mit Reservierung zu erstellen...</strong></p>";
        
        $event_id = create_google_calendar_event(
            $vehicle_name,
            'Google Calendar Test Reservierung',
            $test_start,
            $test_end,
            $test_reservation_id
        );
        
        if ($event_id) {
            echo "<p style='color: green;'>✅ Event mit Reservierung erfolgreich erstellt! Event ID: " . htmlspecialchars($event_id) . "</p>";
            
            // Event in der Datenbank prüfen
            $stmt = $db->prepare("SELECT * FROM calendar_events WHERE reservation_id = ?");
            $stmt->execute([$test_reservation_id]);
            $event_record = $stmt->fetch();
            
            if ($event_record) {
                echo "<p style='color: green;'>✅ Event in der Datenbank mit Reservierung verknüpft</p>";
            } else {
                echo "<p style='color: orange;'>⚠️ Event nicht in der Datenbank gefunden</p>";
            }
            
            // Event löschen
            try {
                require_once 'includes/google_calendar_service_account.php';
                $google_calendar = new GoogleCalendarServiceAccount($service_account_json, $calendar_id, true);
                $google_calendar->deleteEvent($event_id);
                echo "<p style='color: green;'>✅ Test Event gelöscht</p>";
            } catch (Exception $e) {
                echo "<p style='color: orange;'>⚠️ Fehler beim Löschen: " . htmlspecialchars($e->getMessage()) . "</p>";
            }
        } else {
            echo "<p style='color: red;'>❌ Event mit Reservierung konnte nicht erstellt werden</p>";
        }
    } else {
        echo "<p style='color: red;'>❌ Kein Fahrzeug mit ID 1 gefunden</p>";
    }
    
    // Test-Reservierung löschen
    $stmt = $db->prepare("DELETE FROM reservations WHERE id = ?");
    $stmt->execute([$test_reservation_id]);
    echo "<p>✅ Test-Reservierung gelöscht</p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim Test mit Reservierung: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<hr>";
echo "<p><strong>Test abgeschlossen um:</strong> " . date('d.m.Y H:i:s') . "</p>";
?>
