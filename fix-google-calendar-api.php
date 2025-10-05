<?php
/**
 * Fix: Google Calendar API Problem beheben
 */

require_once 'config/database.php';
require_once 'includes/functions.php';

echo "<h1>🔧 Google Calendar API Problem beheben</h1>";

// 1. Teste Google Calendar API mit Timeout
echo "<h2>1. Google Calendar API mit Timeout testen</h2>";

$test_event_id = 'test_event_' . time();

try {
    if (function_exists('delete_google_calendar_event')) {
        echo "<p>Teste delete_google_calendar_event mit Timeout...</p>";
        
        // Setze Timeout für cURL
        $original_timeout = ini_get('default_socket_timeout');
        ini_set('default_socket_timeout', 10); // 10 Sekunden Timeout
        
        $start_time = microtime(true);
        $result = delete_google_calendar_event($test_event_id);
        $end_time = microtime(true);
        
        $duration = round(($end_time - $start_time) * 1000, 2);
        
        echo "<p><strong>Dauer:</strong> {$duration}ms</p>";
        echo "<p><strong>Ergebnis:</strong> " . ($result ? 'Erfolgreich' : 'Fehlgeschlagen') . "</p>";
        
        if ($duration > 5000) {
            echo "<p style='color: red;'>❌ API-Antwort dauert zu lange (>5 Sekunden)</p>";
        } else {
            echo "<p style='color: green;'>✅ API-Antwort ist akzeptabel</p>";
        }
        
        // Stelle ursprünglichen Timeout wieder her
        ini_set('default_socket_timeout', $original_timeout);
        
    } else {
        echo "<p style='color: red;'>❌ delete_google_calendar_event Funktion nicht verfügbar</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Exception beim API-Test: " . $e->getMessage() . "</p>";
}

// 2. Teste Google Calendar Service Account direkt
echo "<h2>2. Google Calendar Service Account direkt testen</h2>";

try {
    if (class_exists('GoogleCalendarServiceAccount')) {
        // Lade Service Account JSON
        $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'google_calendar_service_account'");
        $stmt->execute();
        $service_account_json = $stmt->fetchColumn();
        
        if ($service_account_json) {
            echo "<p style='color: green;'>✅ Service Account JSON gefunden</p>";
            
            // Erstelle Service Account mit Timeout
            $calendar_service = new GoogleCalendarServiceAccount($service_account_json, 'a3f7e2f57f274ba2fe7d3a62a932a33c78ed468aafa6ac477b58f16495e5677a@group.calendar.google.com', true);
            
            // Teste Event-Erstellung
            echo "<p>Teste Event-Erstellung...</p>";
            $test_event_id = $calendar_service->createEvent(
                'API Test - ' . date('Y-m-d H:i:s'),
                date('Y-m-d H:i:s'),
                date('Y-m-d H:i:s', strtotime('+1 hour')),
                'Test Event für API-Diagnose'
            );
            
            if ($test_event_id) {
                echo "<p style='color: green;'>✅ Test-Event erstellt: $test_event_id</p>";
                
                // Teste Event-Löschung mit Timeout
                echo "<p>Teste Event-Löschung mit Timeout...</p>";
                $start_time = microtime(true);
                $delete_result = $calendar_service->deleteEvent($test_event_id);
                $end_time = microtime(true);
                
                $duration = round(($end_time - $start_time) * 1000, 2);
                
                echo "<p><strong>Lösch-Dauer:</strong> {$duration}ms</p>";
                echo "<p><strong>Lösch-Ergebnis:</strong> " . ($delete_result ? 'Erfolgreich' : 'Fehlgeschlagen') . "</p>";
                
                if ($delete_result) {
                    echo "<p style='color: green;'>✅ Google Calendar API funktioniert korrekt!</p>";
                } else {
                    echo "<p style='color: red;'>❌ Google Calendar API schlägt fehl</p>";
                }
                
            } else {
                echo "<p style='color: red;'>❌ Test-Event konnte nicht erstellt werden</p>";
            }
            
        } else {
            echo "<p style='color: red;'>❌ Service Account JSON nicht gefunden</p>";
        }
        
    } else {
        echo "<p style='color: red;'>❌ GoogleCalendarServiceAccount Klasse nicht verfügbar</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim Service Account Test: " . $e->getMessage() . "</p>";
    echo "<p><strong>Stack Trace:</strong></p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}

// 3. Prüfe Netzwerk-Verbindung
echo "<h2>3. Netzwerk-Verbindung prüfen</h2>";

$test_urls = [
    'Google Calendar API' => 'https://www.googleapis.com/calendar/v3',
    'Google OAuth' => 'https://oauth2.googleapis.com/token',
    'Google Accounts' => 'https://accounts.google.com'
];

foreach ($test_urls as $name => $url) {
    echo "<p><strong>$name:</strong> ";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $result = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        echo "<span style='color: red;'>❌ Fehler: $error</span>";
    } elseif ($http_code >= 200 && $http_code < 400) {
        echo "<span style='color: green;'>✅ Erreichbar (HTTP $http_code)</span>";
    } else {
        echo "<span style='color: orange;'>⚠️ HTTP $http_code</span>";
    }
    
    echo "</p>";
}

// 4. Lösungsvorschläge
echo "<h2>4. Lösungsvorschläge</h2>";

echo "<div style='background: #fff3cd; padding: 15px; border: 1px solid #ffeaa7; border-radius: 5px;'>";
echo "<h3 style='color: #856404; margin-top: 0;'>🔧 Google Calendar API Problem beheben</h3>";
echo "<ol style='color: #856404;'>";
echo "<li><strong>Service Account neu erstellen:</strong> Möglicherweise sind die Berechtigungen abgelaufen</li>";
echo "<li><strong>Kalender-Berechtigung prüfen:</strong> Service Account muss Zugriff auf den Kalender haben</li>";
echo "<li><strong>API-Limits prüfen:</strong> Möglicherweise wurden die täglichen Limits überschritten</li>";
echo "<li><strong>Netzwerk-Timeout erhöhen:</strong> Für langsame Verbindungen</li>";
echo "<li><strong>Manuelles Löschen verwenden:</strong> Als Workaround für API-Probleme</li>";
echo "</ol>";
echo "</div>";

// 5. Automatische Reparatur
echo "<h2>5. Automatische Reparatur</h2>";

if (isset($_GET['action']) && $_GET['action'] == 'repair') {
    echo "<h3>Reparatur wird durchgeführt...</h3>";
    
    try {
        // Erstelle neue Service Account Instanz
        $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'google_calendar_service_account'");
        $stmt->execute();
        $service_account_json = $stmt->fetchColumn();
        
        if ($service_account_json) {
            // Teste mit neuer Instanz
            $calendar_service = new GoogleCalendarServiceAccount($service_account_json, 'a3f7e2f57f274ba2fe7d3a62a932a33c78ed468aafa6ac477b58f16495e5677a@group.calendar.google.com', true);
            
            // Teste grundlegende Funktionen
            $test_event_id = $calendar_service->createEvent(
                'Reparatur Test - ' . date('Y-m-d H:i:s'),
                date('Y-m-d H:i:s'),
                date('Y-m-d H:i:s', strtotime('+1 hour')),
                'Reparatur Test Event'
            );
            
            if ($test_event_id) {
                echo "<p style='color: green;'>✅ Service Account funktioniert</p>";
                
                // Lösche Test-Event
                $delete_result = $calendar_service->deleteEvent($test_event_id);
                if ($delete_result) {
                    echo "<p style='color: green;'>✅ Google Calendar API repariert!</p>";
                } else {
                    echo "<p style='color: red;'>❌ Löschen schlägt immer noch fehl</p>";
                }
            } else {
                echo "<p style='color: red;'>❌ Service Account funktioniert nicht</p>";
            }
        }
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Reparatur fehlgeschlagen: " . $e->getMessage() . "</p>";
    }
} else {
    echo "<p><a href='?action=repair' class='btn btn-primary'>Automatische Reparatur starten</a></p>";
}

echo "<hr>";
echo "<p><a href='manage-google-calendar-events.php'>→ Google Calendar Events verwalten</a></p>";
echo "<p><a href='admin/reservations.php'>→ Zur Reservierungen-Übersicht</a></p>";
echo "<p><small>API-Diagnose abgeschlossen: " . date('Y-m-d H:i:s') . "</small></p>";
?>
