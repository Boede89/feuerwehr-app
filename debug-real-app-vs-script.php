<?php
/**
 * Debug für Unterschied zwischen echter App und Debug-Script
 */

session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';

echo "<h1>Debug: Echte App vs Debug-Script</h1>";
echo "<p>Zeitstempel: " . date('d.m.Y H:i:s') . "</p>";

// 1. Prüfe Session
echo "<h2>1. Session prüfen</h2>";
if (isset($_SESSION['user_id'])) {
    echo "<p style='color: green;'>✅ Eingeloggt als User ID: " . $_SESSION['user_id'] . "</p>";
} else {
    echo "<p style='color: red;'>❌ Nicht eingeloggt</p>";
    exit;
}

// 2. Simuliere exakt die echte admin/reservations.php Umgebung
echo "<h2>2. Simuliere exakt die echte admin/reservations.php Umgebung</h2>";

// Wechsle in admin Verzeichnis (wie in der echten App)
$original_dir = getcwd();
chdir('admin');

echo "<p><strong>Nach chdir('admin'):</strong> " . getcwd() . "</p>";

// Lade Dateien wie in admin/reservations.php
try {
    require_once '../config/database.php';
    require_once '../includes/functions.php';
    
    echo "<p style='color: green;'>✅ admin/reservations.php Pfade erfolgreich geladen</p>";
    
    if (function_exists('create_google_calendar_event')) {
        echo "<p style='color: green;'>✅ create_google_calendar_event Funktion ist verfügbar</p>";
    } else {
        echo "<p style='color: red;'>❌ create_google_calendar_event Funktion ist NICHT verfügbar</p>";
    }
    
    if (class_exists('GoogleCalendarServiceAccount')) {
        echo "<p style='color: green;'>✅ GoogleCalendarServiceAccount Klasse ist verfügbar</p>";
    } else {
        echo "<p style='color: red;'>❌ GoogleCalendarServiceAccount Klasse ist NICHT verfügbar</p>";
    }
    
    // Teste Google Calendar Integration in admin Umgebung
    echo "<h3>Google Calendar Integration in admin Umgebung testen</h3>";
    
    // Test-Reservierung erstellen
    $stmt = $db->prepare("SELECT id, name FROM vehicles WHERE is_active = 1 LIMIT 1");
    $stmt->execute();
    $vehicle = $stmt->fetch();
    
    if ($vehicle) {
        $test_start = date('Y-m-d H:i:s', strtotime('+1 hour'));
        $test_end = date('Y-m-d H:i:s', strtotime('+2 hours'));
        
        $stmt = $db->prepare("INSERT INTO reservations (vehicle_id, requester_name, requester_email, reason, start_datetime, end_datetime, status, created_at) VALUES (?, ?, ?, ?, ?, ?, 'approved', NOW())");
        $stmt->execute([$vehicle['id'], 'Admin Test', 'admin@test.com', 'Admin Test für Google Calendar', $test_start, $test_end]);
        $test_reservation_id = $db->lastInsertId();
        
        echo "<p>✅ Test-Reservierung erstellt (ID: $test_reservation_id)</p>";
        
        // Google Calendar Event erstellen (exakt wie in admin/reservations.php)
        echo "<p><strong>Versuche Google Calendar Event zu erstellen...</strong></p>";
        
        $event_id = create_google_calendar_event(
            $vehicle['name'],
            'Admin Test für Google Calendar',
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
                require_once '../includes/google_calendar_service_account.php';
                
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

// Zurück zum ursprünglichen Verzeichnis
chdir($original_dir);

// 3. Prüfe ob es ein Problem mit der echten admin/reservations.php gibt
echo "<h2>3. Prüfe echte admin/reservations.php</h2>";

if (file_exists('admin/reservations.php')) {
    echo "<p style='color: green;'>✅ admin/reservations.php existiert</p>";
    
    $reservations_content = file_get_contents('admin/reservations.php');
    
    // Prüfe ob die Google Calendar Integration korrekt implementiert ist
    if (strpos($reservations_content, 'create_google_calendar_event') !== false) {
        echo "<p style='color: green;'>✅ create_google_calendar_event wird in admin/reservations.php aufgerufen</p>";
    } else {
        echo "<p style='color: red;'>❌ create_google_calendar_event wird NICHT in admin/reservations.php aufgerufen</p>";
    }
    
    if (strpos($reservations_content, 'Google Calendar Event wurde erstellt') !== false) {
        echo "<p style='color: green;'>✅ Erfolgsmeldung ist in admin/reservations.php vorhanden</p>";
    } else {
        echo "<p style='color: red;'>❌ Erfolgsmeldung ist NICHT in admin/reservations.php vorhanden</p>";
    }
    
    if (strpos($reservations_content, 'Google Calendar Event konnte nicht erstellt werden') !== false) {
        echo "<p style='color: green;'>✅ Fehlermeldung ist in admin/reservations.php vorhanden</p>";
    } else {
        echo "<p style='color: red;'>❌ Fehlermeldung ist NICHT in admin/reservations.php vorhanden</p>";
    }
    
} else {
    echo "<p style='color: red;'>❌ admin/reservations.php existiert NICHT</p>";
}

// 4. Teste ob es ein Problem mit dem Error Handling gibt
echo "<h2>4. Teste Error Handling</h2>";

// Simuliere einen Fehler in der create_google_calendar_event Funktion
echo "<p><strong>Teste Error Handling...</strong></p>";

try {
    // Teste mit ungültigen Parametern
    $result = create_google_calendar_event('', '', '', '', null);
    
    if ($result === false) {
        echo "<p style='color: orange;'>⚠️ create_google_calendar_event gibt false zurück (erwartet bei ungültigen Parametern)</p>";
    } else {
        echo "<p style='color: green;'>✅ create_google_calendar_event gibt nicht false zurück</p>";
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Exception in create_google_calendar_event: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<hr>";
echo "<p><strong>Debug abgeschlossen um:</strong> " . date('d.m.Y H:i:s') . "</p>";
?>
