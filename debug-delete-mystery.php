<?php
require_once __DIR__ . '/includes/debug-auth.php';
/**
 * Debug: Lösch-Mysterium - Warum funktioniert Löschen nicht?
 */

require_once 'config/database.php';
require_once 'includes/functions.php';

echo "<h1>🔍 Debug: Lösch-Mysterium - Warum funktioniert Löschen nicht?</h1>";

// 1. Erstelle Test-Event
echo "<h2>1. Erstelle Test-Event</h2>";

try {
    $test_event_id = create_google_calendar_event(
        'MTF',
        'Lösch-Mysterium Test',
        date('Y-m-d H:i:s'),
        date('Y-m-d H:i:s', strtotime('+1 hour')),
        null,
        'Feuerwehrhaus Ammern'
    );
    
    if ($test_event_id) {
        echo "<p style='color: green;'>✅ Test-Event erstellt: $test_event_id</p>";
        
        // 2. Detaillierte Analyse der Lösch-Funktion
        echo "<h2>2. Detaillierte Analyse der Lösch-Funktion</h2>";
        
        // Simuliere den kompletten Ablauf von delete_google_calendar_event
        echo "<h3>2.1 Google Calendar Einstellungen laden</h3>";
        
        $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'google_calendar_%'");
        $stmt->execute();
        $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        $auth_type = $settings['google_calendar_auth_type'] ?? 'service_account';
        $calendar_id = $settings['google_calendar_id'] ?? 'primary';
        $service_account_json = $settings['google_calendar_service_account_json'] ?? '';
        
        echo "<p><strong>Auth Type:</strong> $auth_type</p>";
        echo "<p><strong>Calendar ID:</strong> $calendar_id</p>";
        echo "<p><strong>Service Account JSON:</strong> " . (!empty($service_account_json) ? 'Vorhanden (' . strlen($service_account_json) . ' Zeichen)' : 'Leer') . "</p>";
        
        if (empty($service_account_json)) {
            echo "<p style='color: red;'>❌ Service Account JSON ist leer - das ist das Problem!</p>";
            exit;
        }
        
        echo "<h3>2.2 GoogleCalendarServiceAccount Instanz erstellen</h3>";
        
        if (!class_exists('GoogleCalendarServiceAccount')) {
            echo "<p style='color: red;'>❌ GoogleCalendarServiceAccount Klasse nicht verfügbar</p>";
            exit;
        }
        
        try {
            $calendar_service = new GoogleCalendarServiceAccount($service_account_json, $calendar_id, true);
            echo "<p style='color: green;'>✅ GoogleCalendarServiceAccount Instanz erstellt</p>";
            
            // Teste Access Token
            echo "<h3>2.3 Access Token testen</h3>";
            
            try {
                $access_token = $calendar_service->getAccessToken();
                echo "<p><strong>Access Token:</strong> " . (strlen($access_token) > 50 ? substr($access_token, 0, 50) . '...' : $access_token) . "</p>";
                echo "<p style='color: green;'>✅ Access Token erhalten</p>";
            } catch (Exception $e) {
                echo "<p style='color: red;'>❌ Fehler beim Access Token: " . $e->getMessage() . "</p>";
            }
            
            // Teste Event vor dem Löschen
            echo "<h3>2.4 Event vor dem Löschen prüfen</h3>";
            
            try {
                $event_before = $calendar_service->getEvent($test_event_id);
                echo "<p style='color: green;'>✅ Event vor dem Löschen gefunden</p>";
                echo "<p><strong>Status:</strong> " . ($event_before['status'] ?? 'Unbekannt') . "</p>";
                echo "<p><strong>Summary:</strong> " . ($event_before['summary'] ?? 'Unbekannt') . "</p>";
            } catch (Exception $e) {
                echo "<p style='color: red;'>❌ Fehler beim Abrufen des Events vor dem Löschen: " . $e->getMessage() . "</p>";
            }
            
            // Teste deleteEvent direkt
            echo "<h3>2.5 deleteEvent direkt testen</h3>";
            
            $start_time = microtime(true);
            $result = $calendar_service->deleteEvent($test_event_id);
            $end_time = microtime(true);
            
            $duration = round(($end_time - $start_time) * 1000, 2);
            
            echo "<p><strong>Lösch-Dauer:</strong> {$duration}ms</p>";
            echo "<p><strong>deleteEvent Ergebnis:</strong> " . ($result ? 'TRUE' : 'FALSE') . "</p>";
            
            // Teste reallyDeleteEvent direkt
            echo "<h3>2.6 reallyDeleteEvent direkt testen</h3>";
            
            $start_time = microtime(true);
            $result2 = $calendar_service->reallyDeleteEvent($test_event_id);
            $end_time = microtime(true);
            
            $duration2 = round(($end_time - $start_time) * 1000, 2);
            
            echo "<p><strong>Lösch-Dauer:</strong> {$duration2}ms</p>";
            echo "<p><strong>reallyDeleteEvent Ergebnis:</strong> " . ($result2 ? 'TRUE' : 'FALSE') . "</p>";
            
            // Prüfe Event nach dem Löschen
            echo "<h3>2.7 Event nach dem Löschen prüfen</h3>";
            
            try {
                $event_after = $calendar_service->getEvent($test_event_id);
                echo "<p style='color: orange;'>⚠️ Event existiert noch nach dem Löschen</p>";
                echo "<p><strong>Status:</strong> " . ($event_after['status'] ?? 'Unbekannt') . "</p>";
                echo "<p><strong>Summary:</strong> " . ($event_after['summary'] ?? 'Unbekannt') . "</p>";
                
                if (isset($event_after['status']) && $event_after['status'] === 'cancelled') {
                    echo "<p style='color: blue;'>ℹ️ Event ist storniert (cancelled) - das ist normal bei Google Calendar</p>";
                }
                
            } catch (Exception $e) {
                if (strpos($e->getMessage(), '404') !== false) {
                    echo "<p style='color: green; font-weight: bold;'>🎉 Event wurde vollständig gelöscht (404 Not Found)!</p>";
                } else {
                    echo "<p style='color: red;'>❌ Fehler beim Abrufen des Events nach dem Löschen: " . $e->getMessage() . "</p>";
                }
            }
            
            // Teste manuellen cURL-Aufruf
            echo "<h3>2.8 Manueller cURL-Aufruf testen</h3>";
            
            try {
                $access_token = $calendar_service->getAccessToken();
                
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, 'https://www.googleapis.com/calendar/v3/calendars/' . urlencode($calendar_id) . '/events/' . urlencode($test_event_id));
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Authorization: Bearer ' . $access_token
                ]);
                
                $response = curl_exec($ch);
                $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $error = curl_error($ch);
                curl_close($ch);
                
                echo "<p><strong>HTTP Code:</strong> $http_code</p>";
                echo "<p><strong>Response:</strong> $response</p>";
                echo "<p><strong>Error:</strong> $error</p>";
                
                if ($http_code === 204) {
                    echo "<p style='color: green;'>✅ Manueller cURL-Aufruf erfolgreich (204)</p>";
                } elseif ($http_code === 410) {
                    echo "<p style='color: green;'>✅ Manueller cURL-Aufruf erfolgreich (410 - bereits gelöscht)</p>";
                } else {
                    echo "<p style='color: red;'>❌ Manueller cURL-Aufruf fehlgeschlagen (HTTP $http_code)</p>";
                }
                
            } catch (Exception $e) {
                echo "<p style='color: red;'>❌ Fehler beim manuellen cURL-Aufruf: " . $e->getMessage() . "</p>";
            }
            
        } catch (Exception $e) {
            echo "<p style='color: red;'>❌ Fehler beim Erstellen der GoogleCalendarServiceAccount Instanz: " . $e->getMessage() . "</p>";
            echo "<p><strong>Stack Trace:</strong></p>";
            echo "<pre>" . $e->getTraceAsString() . "</pre>";
        }
        
    } else {
        echo "<p style='color: red;'>❌ Test-Event konnte nicht erstellt werden</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim Test: " . $e->getMessage() . "</p>";
    echo "<p><strong>Stack Trace:</strong></p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}

// 3. Teste mit echtem Event
echo "<h2>3. Teste mit echtem Event</h2>";

$real_event_id = '6lu3icbt1ketrk3tp8kujqs4fs'; // Das Event aus Ihrer Meldung

echo "<p><strong>Teste mit echtem Event:</strong> $real_event_id</p>";

try {
    if (class_exists('GoogleCalendarServiceAccount')) {
        $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'google_calendar_service_account_json'");
        $stmt->execute();
        $service_account_json = $stmt->fetchColumn();
        
        if ($service_account_json) {
            $calendar_service = new GoogleCalendarServiceAccount($service_account_json, 'a3f7e2f57f274ba2fe7d3a62a932a33c78ed468aafa6ac477b58f16495e5677a@group.calendar.google.com', true);
            
            // Prüfe Event Status vor dem Löschen
            echo "<h3>3.1 Event Status vor dem Löschen</h3>";
            
            try {
                $event = $calendar_service->getEvent($real_event_id);
                echo "<p style='color: green;'>✅ Echtes Event gefunden</p>";
                echo "<p><strong>Status:</strong> " . ($event['status'] ?? 'Unbekannt') . "</p>";
                echo "<p><strong>Summary:</strong> " . ($event['summary'] ?? 'Unbekannt') . "</p>";
                
            } catch (Exception $e) {
                echo "<p style='color: red;'>❌ Fehler beim Abrufen des echten Events: " . $e->getMessage() . "</p>";
            }
            
            // Teste manuellen cURL-Aufruf für echtes Event
            echo "<h3>3.2 Manueller cURL-Aufruf für echtes Event</h3>";
            
            try {
                $access_token = $calendar_service->getAccessToken();
                
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, 'https://www.googleapis.com/calendar/v3/calendars/' . urlencode('a3f7e2f57f274ba2fe7d3a62a932a33c78ed468aafa6ac477b58f16495e5677a@group.calendar.google.com') . '/events/' . urlencode($real_event_id));
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Authorization: Bearer ' . $access_token
                ]);
                
                $response = curl_exec($ch);
                $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $error = curl_error($ch);
                curl_close($ch);
                
                echo "<p><strong>HTTP Code:</strong> $http_code</p>";
                echo "<p><strong>Response:</strong> $response</p>";
                echo "<p><strong>Error:</strong> $error</p>";
                
                if ($http_code === 204) {
                    echo "<p style='color: green; font-weight: bold;'>🎉 Echtes Event erfolgreich gelöscht (204)!</p>";
                } elseif ($http_code === 410) {
                    echo "<p style='color: green; font-weight: bold;'>🎉 Echtes Event bereits gelöscht (410)!</p>";
                } else {
                    echo "<p style='color: red;'>❌ Echtes Event konnte nicht gelöscht werden (HTTP $http_code)</p>";
                }
                
            } catch (Exception $e) {
                echo "<p style='color: red;'>❌ Fehler beim manuellen cURL-Aufruf für echtes Event: " . $e->getMessage() . "</p>";
            }
        }
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim echten Event Test: " . $e->getMessage() . "</p>";
}

echo "<hr>";
echo "<h2>📋 Zusammenfassung</h2>";
echo "<p>Dieses Debug-Skript testet jeden Schritt der Lösch-Funktion:</p>";
echo "<ul>";
echo "<li>✅ Google Calendar Einstellungen</li>";
echo "<li>✅ GoogleCalendarServiceAccount Instanz</li>";
echo "<li>✅ Access Token</li>";
echo "<li>✅ deleteEvent Methode</li>";
echo "<li>✅ reallyDeleteEvent Methode</li>";
echo "<li>✅ Manueller cURL-Aufruf</li>";
echo "</ul>";

echo "<p><a href='admin/reservations.php'>→ Zur Reservierungen-Übersicht</a></p>";
echo "<p><a href='test-location-fix.php'>→ Ort-Korrektur Test</a></p>";
echo "<p><small>Lösch-Mysterium Debug abgeschlossen: " . date('Y-m-d H:i:s') . "</small></p>";
?>
