<?php
/**
 * Prüfe ob alle Funktionen verfügbar sind
 */

session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';

echo "<h1>Funktionen Verfügbarkeit prüfen</h1>";
echo "<p>Zeitstempel: " . date('d.m.Y H:i:s') . "</p>";

// 1. Prüfe ob du eingeloggt bist
echo "<h2>1. Session prüfen</h2>";
if (isset($_SESSION['user_id'])) {
    echo "<p style='color: green;'>✅ Eingeloggt als User ID: " . $_SESSION['user_id'] . "</p>";
} else {
    echo "<p style='color: red;'>❌ Nicht eingeloggt</p>";
    exit;
}

// 2. Prüfe includes/functions.php
echo "<h2>2. includes/functions.php prüfen</h2>";

if (file_exists('includes/functions.php')) {
    echo "<p style='color: green;'>✅ includes/functions.php existiert</p>";
    
    $functions_content = file_get_contents('includes/functions.php');
    
    if (strpos($functions_content, 'function create_google_calendar_event') !== false) {
        echo "<p style='color: green;'>✅ create_google_calendar_event Funktion in includes/functions.php gefunden</p>";
    } else {
        echo "<p style='color: red;'>❌ create_google_calendar_event Funktion NICHT in includes/functions.php gefunden</p>";
    }
    
    if (strpos($functions_content, 'function has_admin_access') !== false) {
        echo "<p style='color: green;'>✅ has_admin_access Funktion in includes/functions.php gefunden</p>";
    } else {
        echo "<p style='color: red;'>❌ has_admin_access Funktion NICHT in includes/functions.php gefunden</p>";
    }
    
} else {
    echo "<p style='color: red;'>❌ includes/functions.php existiert NICHT</p>";
}

// 3. Prüfe ob Funktionen verfügbar sind
echo "<h2>3. Funktionen Verfügbarkeit prüfen</h2>";

$functions_to_check = [
    'create_google_calendar_event',
    'has_admin_access',
    'validate_csrf_token',
    'generate_csrf_token',
    'sanitize_input',
    'send_email',
    'log_activity'
];

foreach ($functions_to_check as $function) {
    if (function_exists($function)) {
        echo "<p style='color: green;'>✅ $function Funktion ist verfügbar</p>";
    } else {
        echo "<p style='color: red;'>❌ $function Funktion ist NICHT verfügbar</p>";
    }
}

// 4. Prüfe Google Calendar Service Account
echo "<h2>4. Google Calendar Service Account prüfen</h2>";

if (file_exists('includes/google_calendar_service_account.php')) {
    echo "<p style='color: green;'>✅ includes/google_calendar_service_account.php existiert</p>";
    
    if (class_exists('GoogleCalendarServiceAccount')) {
        echo "<p style='color: green;'>✅ GoogleCalendarServiceAccount Klasse ist verfügbar</p>";
    } else {
        echo "<p style='color: red;'>❌ GoogleCalendarServiceAccount Klasse ist NICHT verfügbar</p>";
    }
} else {
    echo "<p style='color: red;'>❌ includes/google_calendar_service_account.php existiert NICHT</p>";
}

// 5. Prüfe Google Calendar Einstellungen
echo "<h2>5. Google Calendar Einstellungen prüfen</h2>";

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
}

// 6. Teste Google Calendar Integration direkt
echo "<h2>6. Google Calendar Integration direkt testen</h2>";

if (function_exists('create_google_calendar_event')) {
    try {
        // Test-Reservierung erstellen
        $stmt = $db->prepare("SELECT id, name FROM vehicles WHERE is_active = 1 LIMIT 1");
        $stmt->execute();
        $vehicle = $stmt->fetch();
        
        if ($vehicle) {
            $test_start = date('Y-m-d H:i:s', strtotime('+1 hour'));
            $test_end = date('Y-m-d H:i:s', strtotime('+2 hours'));
            
            $stmt = $db->prepare("INSERT INTO reservations (vehicle_id, requester_name, requester_email, reason, start_datetime, end_datetime, status, created_at) VALUES (?, ?, ?, ?, ?, ?, 'approved', NOW())");
            $stmt->execute([$vehicle['id'], 'Function Test', 'function@test.com', 'Function Test für Google Calendar', $test_start, $test_end]);
            $test_reservation_id = $db->lastInsertId();
            
            echo "<p>✅ Test-Reservierung erstellt (ID: $test_reservation_id)</p>";
            
            // Google Calendar Event erstellen
            $event_id = create_google_calendar_event(
                $vehicle['name'],
                'Function Test für Google Calendar',
                $test_start,
                $test_end,
                $test_reservation_id
            );
            
            if ($event_id) {
                echo "<p style='color: green;'>✅ Google Calendar Event erfolgreich erstellt! Event ID: " . htmlspecialchars($event_id) . "</p>";
                
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
} else {
    echo "<p style='color: red;'>❌ create_google_calendar_event Funktion ist nicht verfügbar - Test nicht möglich</p>";
}

echo "<hr>";
echo "<p><strong>Prüfung abgeschlossen um:</strong> " . date('d.m.Y H:i:s') . "</p>";
?>
