<?php
/**
 * Fix: Google Calendar Timeout Problem beheben
 */

echo "<!DOCTYPE html>";
echo "<html><head><title>Fix Google Calendar Timeout</title></head><body>";
echo "<h1>🔧 Fix: Google Calendar Timeout Problem</h1>";
echo "<p>Zeitstempel: " . date('d.m.Y H:i:s') . "</p>";

try {
    echo "<h2>1. Problem identifiziert</h2>";
    echo "Das Problem ist, dass die Google Calendar API-Abfrage hängt.<br>";
    echo "Dies passiert oft bei:<br>";
    echo "- Netzwerk-Problemen<br>";
    echo "- Google API Timeouts<br>";
    echo "- Service Account Berechtigungsproblemen<br>";
    
    echo "<h2>2. Erstelle verbesserte Google Calendar Funktion</h2>";
    
    // Lade die aktuelle functions.php
    require_once 'config/database.php';
    require_once 'includes/functions.php';
    
    // Erstelle eine verbesserte Version der Funktion
    $improved_function = '
/**
 * Google Kalender API - Event erstellen (Verbesserte Version mit Timeout)
 */
function create_google_calendar_event($vehicle_name, $reason, $start_datetime, $end_datetime, $reservation_id = null, $location = null) {
    global $db;
    
    try {
        // Google Calendar Einstellungen laden
        $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE \'google_calendar_%\'");
        $stmt->execute();
        $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        $auth_type = $settings[\'google_calendar_auth_type\'] ?? \'service_account\';
        $calendar_id = $settings[\'google_calendar_id\'] ?? \'primary\';
        
        if ($auth_type === \'service_account\') {
            // Service Account verwenden
            $service_account_file = $settings[\'google_calendar_service_account_file\'] ?? \'\';
            $service_account_json = $settings[\'google_calendar_service_account_json\'] ?? \'\';
            
            // Prüfe ob Service Account Klasse verfügbar ist
            if (class_exists(\'GoogleCalendarServiceAccount\')) {
                // JSON-Inhalt hat Priorität über Datei
                if (!empty($service_account_json)) {
                    // JSON-Inhalt verwenden
                    $google_calendar = new GoogleCalendarServiceAccount($service_account_json, $calendar_id, true);
                } elseif (!empty($service_account_file) && file_exists($service_account_file)) {
                    // Datei verwenden
                    $google_calendar = new GoogleCalendarServiceAccount($service_account_file, $calendar_id, false);
                } else {
                    error_log(\'Google Calendar Service Account nicht konfiguriert (weder Datei noch JSON-Inhalt)\');
                    return false;
                }
            } else {
                error_log(\'Google Calendar Service Account Klasse nicht verfügbar - Google Calendar deaktiviert\');
                return false;
            }
        } else {
            // API Key verwenden (Fallback)
            $api_key = $settings[\'google_calendar_api_key\'] ?? \'\';
            
            if (empty($api_key)) {
                error_log(\'Google Calendar API Key nicht konfiguriert\');
                return false;
            }
            
            if (class_exists(\'GoogleCalendar\')) {
                $google_calendar = new GoogleCalendar($api_key, $calendar_id);
            } else {
                error_log(\'Google Calendar Klasse nicht verfügbar - Google Calendar deaktiviert\');
                return false;
            }
        }
        
        // Event-Details erstellen
        $title = $vehicle_name . \' - \' . $reason;
        $description = "Fahrzeugreservierung über Feuerwehr App\\nFahrzeug: $vehicle_name\\nGrund: $reason\\nOrt: " . ($location ?? \'Nicht angegeben\');
        
        // Setze aggressive Timeouts
        set_time_limit(15); // 15 Sekunden Timeout
        ini_set(\'default_socket_timeout\', 10); // 10 Sekunden Socket Timeout
        
        // Event erstellen mit Timeout-Schutz
        $event_id = false;
        $start_time = microtime(true);
        
        try {
            $event_id = $google_calendar->createEvent($title, $start_datetime, $end_datetime, $description);
        } catch (Exception $e) {
            error_log(\'Google Calendar Event Erstellung Fehler: \' . $e->getMessage());
            return false;
        }
        
        $end_time = microtime(true);
        $execution_time = ($end_time - $start_time) * 1000;
        
        // Logge Ausführungszeit
        error_log("Google Calendar Event erstellt in " . round($execution_time, 2) . " ms");
        
        if ($event_id && $reservation_id) {
            // Event ID in der Datenbank speichern
            $stmt = $db->prepare("INSERT INTO calendar_events (reservation_id, google_event_id, title, start_datetime, end_datetime) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$reservation_id, $event_id, $title, $start_datetime, $end_datetime]);
        }
        
        return $event_id;
    } catch (Exception $e) {
        error_log(\'Google Calendar Fehler: \' . $e->getMessage());
        return false;
    }
}';
    
    echo "✅ Verbesserte Funktion erstellt<br>";
    
    echo "<h2>3. Ersetze Funktion in includes/functions.php</h2>";
    
    // Lade die aktuelle functions.php
    $current_content = file_get_contents('includes/functions.php');
    
    // Finde und ersetze die create_google_calendar_event Funktion
    $pattern = '/\/\*\*[\s\S]*?Google Kalender API - Event erstellen[\s\S]*?\*\/\s*function create_google_calendar_event\([^}]+\}/';
    
    if (preg_match($pattern, $current_content)) {
        $new_content = preg_replace($pattern, $improved_function, $current_content);
        
        if ($new_content !== $current_content) {
            file_put_contents('includes/functions.php', $new_content);
            echo "✅ Funktion erfolgreich ersetzt<br>";
        } else {
            echo "❌ Funktion konnte nicht ersetzt werden<br>";
        }
    } else {
        echo "❌ Funktion nicht gefunden<br>";
    }
    
    echo "<h2>4. Teste die verbesserte Funktion</h2>";
    
    // Lade die aktualisierte functions.php
    require_once 'includes/functions.php';
    
    if (function_exists('create_google_calendar_event')) {
        echo "✅ create_google_calendar_event Funktion ist verfügbar<br>";
        
        // Teste mit echten Daten
        $test_vehicle = 'MTF';
        $test_reason = 'Timeout Fix Test - ' . date('Y-m-d H:i:s');
        $test_start = date('Y-m-d H:i:s', strtotime('+1 day'));
        $test_end = date('Y-m-d H:i:s', strtotime('+1 day +2 hours'));
        $test_location = 'Test Ort';
        
        echo "Teste Funktion mit Timeout-Schutz...<br>";
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
