<?php
/**
 * Debug: Google Calendar Problem detailliert analysieren
 */

session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';

// Simuliere Admin-Session
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'admin';

echo "<!DOCTYPE html>";
echo "<html><head><title>Debug Google Calendar</title></head><body>";
echo "<h1>🔍 Debug: Google Calendar Problem</h1>";
echo "<p>Zeitstempel: " . date('d.m.Y H:i:s') . "</p>";

try {
    echo "<h2>1. Prüfe Google Calendar Einstellungen</h2>";
    
    $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'google_calendar_%'");
    $stmt->execute();
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    $auth_type = $settings['google_calendar_auth_type'] ?? 'service_account';
    $calendar_id = $settings['google_calendar_id'] ?? 'primary';
    $service_account_json = $settings['google_calendar_service_account_json'] ?? '';
    
    echo "Authentifizierungstyp: $auth_type<br>";
    echo "Kalender ID: $calendar_id<br>";
    echo "Service Account JSON: " . (empty($service_account_json) ? 'Nicht konfiguriert' : 'Konfiguriert (' . strlen($service_account_json) . ' Zeichen)') . "<br>";
    
    echo "<h2>2. Prüfe Google Calendar Klassen</h2>";
    
    if (class_exists('GoogleCalendarServiceAccount')) {
        echo "✅ GoogleCalendarServiceAccount Klasse ist verfügbar<br>";
    } else {
        echo "❌ GoogleCalendarServiceAccount Klasse ist NICHT verfügbar<br>";
    }
    
    if (class_exists('GoogleCalendar')) {
        echo "✅ GoogleCalendar Klasse ist verfügbar<br>";
    } else {
        echo "❌ GoogleCalendar Klasse ist NICHT verfügbar<br>";
    }
    
    echo "<h2>3. Teste Service Account Initialisierung</h2>";
    
    if ($auth_type === 'service_account' && !empty($service_account_json)) {
        try {
            echo "Initialisiere GoogleCalendarServiceAccount...<br>";
            $google_calendar = new GoogleCalendarServiceAccount($service_account_json, $calendar_id, true);
            echo "✅ GoogleCalendarServiceAccount erfolgreich initialisiert<br>";
            
            echo "<h2>4. Teste API-Verbindung</h2>";
            
            // Teste einfache API-Abfrage
            echo "Teste API-Verbindung...<br>";
            $test_start = date('Y-m-d H:i:s');
            $test_end = date('Y-m-d H:i:s', strtotime('+1 day'));
            
            $start_time = microtime(true);
            $events = $google_calendar->getEvents($test_start, $test_end);
            $end_time = microtime(true);
            $execution_time = ($end_time - $start_time) * 1000;
            
            echo "API-Abfrage abgeschlossen in " . round($execution_time, 2) . " ms<br>";
            
            if (is_array($events)) {
                echo "✅ API-Verbindung erfolgreich! Gefundene Events: " . count($events) . "<br>";
            } else {
                echo "❌ API-Verbindung fehlgeschlagen - Rückgabe: " . var_export($events, true) . "<br>";
            }
            
            echo "<h2>5. Teste Event-Erstellung</h2>";
            
            // Teste Event-Erstellung
            $test_title = 'Test Event - ' . date('Y-m-d H:i:s');
            $test_description = 'Dies ist ein Test-Event der Feuerwehr App';
            $test_start = date('Y-m-d H:i:s', strtotime('+1 hour'));
            $test_end = date('Y-m-d H:i:s', strtotime('+2 hours'));
            
            echo "Test-Event Details:<br>";
            echo "- Titel: $test_title<br>";
            echo "- Beschreibung: $test_description<br>";
            echo "- Start: $test_start<br>";
            echo "- Ende: $test_end<br>";
            
            $start_time = microtime(true);
            $event_id = $google_calendar->createEvent($test_title, $test_start, $test_end, $test_description);
            $end_time = microtime(true);
            $execution_time = ($end_time - $start_time) * 1000;
            
            echo "Event-Erstellung abgeschlossen in " . round($execution_time, 2) . " ms<br>";
            
            if ($event_id) {
                echo "✅ Event erfolgreich erstellt! Event ID: $event_id<br>";
                
                // Teste Event löschen
                echo "Lösche Test-Event...<br>";
                $delete_result = $google_calendar->deleteEvent($event_id);
                if ($delete_result) {
                    echo "✅ Test-Event erfolgreich gelöscht<br>";
                } else {
                    echo "⚠️ Test-Event konnte nicht gelöscht werden<br>";
                }
            } else {
                echo "❌ Event konnte nicht erstellt werden<br>";
            }
            
        } catch (Exception $e) {
            echo "❌ Fehler bei Service Account Test: " . $e->getMessage() . "<br>";
            echo "Stack Trace: " . $e->getTraceAsString() . "<br>";
        }
    } else {
        echo "⚠️ Service Account nicht konfiguriert oder leer<br>";
    }
    
    echo "<h2>6. Teste create_google_calendar_event Funktion</h2>";
    
    if (function_exists('create_google_calendar_event')) {
        echo "✅ create_google_calendar_event Funktion ist verfügbar<br>";
        
        // Teste mit echten Daten
        $test_vehicle = 'MTF';
        $test_reason = 'Debug Test - ' . date('Y-m-d H:i:s');
        $test_start = date('Y-m-d H:i:s', strtotime('+1 day'));
        $test_end = date('Y-m-d H:i:s', strtotime('+1 day +2 hours'));
        $test_location = 'Test Ort';
        
        echo "Test-Parameter:<br>";
        echo "- Fahrzeug: $test_vehicle<br>";
        echo "- Grund: $test_reason<br>";
        echo "- Start: $test_start<br>";
        echo "- Ende: $test_end<br>";
        echo "- Ort: $test_location<br>";
        
        $start_time = microtime(true);
        $event_id = create_google_calendar_event($test_vehicle, $test_reason, $test_start, $test_end, null, $test_location);
        $end_time = microtime(true);
        $execution_time = ($end_time - $start_time) * 1000;
        
        echo "Funktion abgeschlossen in " . round($execution_time, 2) . " ms<br>";
        
        if ($event_id) {
            echo "✅ create_google_calendar_event erfolgreich! Event ID: $event_id<br>";
        } else {
            echo "❌ create_google_calendar_event fehlgeschlagen<br>";
        }
    } else {
        echo "❌ create_google_calendar_event Funktion ist NICHT verfügbar<br>";
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
