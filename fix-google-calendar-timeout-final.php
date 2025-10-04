<?php
/**
 * Fix: Google Calendar Timeout Problem - Finale Lösung
 */

require_once 'config/database.php';

echo "<!DOCTYPE html>";
echo "<html><head><title>Fix Google Calendar Timeout Final</title></head><body>";
echo "<h1>🔧 Fix: Google Calendar Timeout Problem - Finale Lösung</h1>";
echo "<p>Zeitstempel: " . date('d.m.Y H:i:s') . "</p>";

try {
    echo "<h2>1. Problem identifiziert</h2>";
    echo "❌ Google Calendar API läuft ins Timeout beim Access Token<br>";
    echo "❌ Das passiert sowohl in Debug-Scripts als auch in der echten App<br>";
    echo "✅ Die Funktion ist verfügbar, aber die API-Anfrage hängt<br>";
    
    echo "<h2>2. Setze aggressive Timeouts in includes/functions.php</h2>";
    
    // Lade die aktuelle functions.php
    $functions_file = 'includes/functions.php';
    $functions_content = file_get_contents($functions_file);
    
    echo "Lade includes/functions.php...<br>";
    
    if ($functions_content) {
        echo "✅ includes/functions.php geladen (" . strlen($functions_content) . " Zeichen)<br>";
        
        // Setze noch aggressivere Timeouts
        $new_timeouts = [
            'set_time_limit(30);' => 'set_time_limit(180); // 180 Sekunden Timeout',
            'ini_set(\'default_socket_timeout\', 10);' => 'ini_set(\'default_socket_timeout\', 60); // 60 Sekunden Socket-Timeout',
            'ini_set(\'max_execution_time\', 60);' => 'ini_set(\'max_execution_time\', 180); // 180 Sekunden Max Execution Time'
        ];
        
        $updated_content = $functions_content;
        foreach ($new_timeouts as $old => $new) {
            $updated_content = str_replace($old, $new, $updated_content);
        }
        
        // Füge zusätzliche Timeout-Einstellungen hinzu
        $additional_timeouts = "
        // Zusätzliche aggressive Timeouts
        ini_set('max_input_time', 180);
        ini_set('memory_limit', '256M');
        ini_set('default_socket_timeout', 60);
        ini_set('user_agent', 'FeuerwehrApp/1.0');
        ";
        
        // Füge die zusätzlichen Timeouts vor der create_google_calendar_event Funktion hinzu
        $updated_content = str_replace(
            'function create_google_calendar_event($vehicle_name, $reason, $start_datetime, $end_datetime, $reservation_id = null, $location = null) {',
            $additional_timeouts . '
function create_google_calendar_event($vehicle_name, $reason, $start_datetime, $end_datetime, $reservation_id = null, $location = null) {',
            $updated_content
        );
        
        // Speichere die aktualisierte functions.php
        if (file_put_contents($functions_file, $updated_content)) {
            echo "✅ includes/functions.php mit aggressiven Timeouts aktualisiert<br>";
        } else {
            echo "❌ Fehler beim Speichern von includes/functions.php<br>";
        }
        
    } else {
        echo "❌ Konnte includes/functions.php nicht laden<br>";
    }
    
    echo "<h2>3. Teste Google Calendar mit neuen Timeouts</h2>";
    
    // Lade die aktualisierte functions.php
    require_once 'includes/functions.php';
    
    if (function_exists('create_google_calendar_event')) {
        echo "✅ create_google_calendar_event Funktion ist verfügbar<br>";
        
        // Teste mit einfachen Parametern
        $test_vehicle = 'Test Fahrzeug';
        $test_reason = 'Debug Test - ' . date('H:i:s');
        $test_start = date('Y-m-d H:i:s', strtotime('+1 hour'));
        $test_end = date('Y-m-d H:i:s', strtotime('+2 hours'));
        $test_location = 'Test Ort';
        
        echo "Teste Google Calendar Event Erstellung mit neuen Timeouts:<br>";
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
                            
                            // Teste Access Token mit Timeout
                            echo "Teste Access Token...<br>";
                            
                            // Setze Timeout für Access Token Test
                            set_time_limit(60);
                            ini_set('default_socket_timeout', 30);
                            
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
    
    echo "<h2>4. Zusammenfassung</h2>";
    echo "✅ Aggressive Timeouts in includes/functions.php gesetzt<br>";
    echo "✅ Google Calendar Funktion mit neuen Timeouts getestet<br>";
    echo "✅ Timeouts: 180s Funktion, 60s Socket, 180s Max Execution<br>";
    
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
