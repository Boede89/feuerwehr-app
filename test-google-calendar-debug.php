<?php
/**
 * Debug für Google Calendar bei Reservierungsgenehmigung
 */

session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';

echo "<h1>Google Calendar Debug bei Reservierungsgenehmigung</h1>";
echo "<p>Zeitstempel: " . date('d.m.Y H:i:s') . "</p>";

// 1. Prüfe ob du eingeloggt bist
echo "<h2>1. Session prüfen</h2>";
if (isset($_SESSION['user_id'])) {
    echo "<p style='color: green;'>✅ Eingeloggt als User ID: " . $_SESSION['user_id'] . "</p>";
} else {
    echo "<p style='color: red;'>❌ Nicht eingeloggt - das ist das Problem!</p>";
    echo "<p><strong>Lösung:</strong> Logge dich zuerst in die Admin-App ein, dann teste die Reservierungsgenehmigung.</p>";
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

// 3. Prüfe Google Calendar Funktion
echo "<h2>3. Google Calendar Funktion prüfen</h2>";
if (function_exists('create_google_calendar_event')) {
    echo "<p style='color: green;'>✅ create_google_calendar_event Funktion ist verfügbar</p>";
} else {
    echo "<p style='color: red;'>❌ create_google_calendar_event Funktion ist NICHT verfügbar</p>";
    exit;
}

// 4. Prüfe Google Calendar Einstellungen
echo "<h2>4. Google Calendar Einstellungen prüfen</h2>";
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

// 5. Teste Google Calendar Integration direkt
echo "<h2>5. Google Calendar Integration testen</h2>";

try {
    // Test-Reservierung erstellen
    $stmt = $db->prepare("SELECT id, name FROM vehicles WHERE is_active = 1 LIMIT 1");
    $stmt->execute();
    $vehicle = $stmt->fetch();
    
    if (!$vehicle) {
        echo "<p style='color: red;'>❌ Kein Fahrzeug gefunden</p>";
        exit;
    }
    
    $vehicle_id = $vehicle['id'];
    $vehicle_name = $vehicle['name'];
    
    // Test-Reservierung erstellen
    $test_start = date('Y-m-d H:i:s', strtotime('+1 hour'));
    $test_end = date('Y-m-d H:i:s', strtotime('+2 hours'));
    
    $stmt = $db->prepare("INSERT INTO reservations (vehicle_id, requester_name, requester_email, reason, start_datetime, end_datetime, status, created_at) VALUES (?, ?, ?, ?, ?, ?, 'approved', NOW())");
    $stmt->execute([$vehicle_id, 'Debug Test', 'debug@test.com', 'Debug Test für Google Calendar', $test_start, $test_end]);
    $test_reservation_id = $db->lastInsertId();
    
    echo "<p>✅ Test-Reservierung erstellt (ID: $test_reservation_id)</p>";
    
    // Google Calendar Event erstellen (exakt wie in admin/reservations.php)
    echo "<p><strong>Versuche Google Calendar Event zu erstellen...</strong></p>";
    
    $event_id = create_google_calendar_event(
        $vehicle_name,
        'Debug Test für Google Calendar',
        $test_start,
        $test_end,
        $test_reservation_id
    );
    
    if ($event_id) {
        echo "<p style='color: green;'>✅ Google Calendar Event erfolgreich erstellt! Event ID: " . htmlspecialchars($event_id) . "</p>";
        
        // Prüfe ob Event in der Datenbank gespeichert wurde
        $stmt = $db->prepare("SELECT * FROM calendar_events WHERE reservation_id = ?");
        $stmt->execute([$test_reservation_id]);
        $event_record = $stmt->fetch();
        
        if ($event_record) {
            echo "<p style='color: green;'>✅ Event in der Datenbank gespeichert</p>";
        } else {
            echo "<p style='color: red;'>❌ Event NICHT in der Datenbank gespeichert</p>";
        }
        
        // Event löschen
        try {
            require_once 'includes/google_calendar_service_account.php';
            $google_calendar = new GoogleCalendarServiceAccount($service_account_json, $calendar_id, true);
            $google_calendar->deleteEvent($event_id);
            echo "<p>✅ Test Event gelöscht</p>";
        } catch (Exception $e) {
            echo "<p style='color: orange;'>⚠️ Fehler beim Löschen: " . htmlspecialchars($e->getMessage()) . "</p>";
        }
    } else {
        echo "<p style='color: red;'>❌ Google Calendar Event konnte NICHT erstellt werden</p>";
    }
    
    // Test-Reservierung löschen
    $stmt = $db->prepare("DELETE FROM reservations WHERE id = ?");
    $stmt->execute([$test_reservation_id]);
    echo "<p>✅ Test-Reservierung gelöscht</p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim Test: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><strong>Stack Trace:</strong></p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}

echo "<hr>";
echo "<p><strong>Debug abgeschlossen um:</strong> " . date('d.m.Y H:i:s') . "</p>";
echo "<p><strong>Wichtig:</strong> Du musst eingeloggt sein, damit die Google Calendar Integration funktioniert!</p>";
?>
