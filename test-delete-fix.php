<?php
/**
 * Test: Delete Fix - Korrekte Service Account JSON Einstellung
 */

require_once 'config/database.php';
require_once 'includes/functions.php';

echo "<h1>🔧 Test: Delete Fix - Service Account JSON</h1>";

// 1. Prüfe Google Calendar Einstellungen
echo "<h2>1. Google Calendar Einstellungen prüfen</h2>";

try {
    $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'google_calendar_%'");
    $stmt->execute();
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    echo "<table border='1' cellpadding='5' style='width: 100%;'>";
    echo "<tr><th>Einstellung</th><th>Wert</th></tr>";
    
    foreach ($settings as $key => $value) {
        $display_value = $value;
        if (strpos($key, 'json') !== false || strpos($key, 'key') !== false) {
            $display_value = !empty($value) ? 'Vorhanden (' . strlen($value) . ' Zeichen)' : 'Leer';
        }
        echo "<tr><td>$key</td><td>$display_value</td></tr>";
    }
    echo "</table>";
    
    $service_account_json = $settings['google_calendar_service_account_json'] ?? '';
    $calendar_id = $settings['google_calendar_id'] ?? 'primary';
    
    if (empty($service_account_json)) {
        echo "<p style='color: red;'>❌ google_calendar_service_account_json ist leer!</p>";
    } else {
        echo "<p style='color: green;'>✅ google_calendar_service_account_json ist vorhanden</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim Laden der Einstellungen: " . $e->getMessage() . "</p>";
}

// 2. Erstelle Test-Event
echo "<h2>2. Erstelle Test-Event</h2>";

try {
    $test_event_id = create_google_calendar_event(
        'Delete Fix Test',
        'Test Event für Delete Fix - ' . date('Y-m-d H:i:s'),
        date('Y-m-d H:i:s'),
        date('Y-m-d H:i:s', strtotime('+1 hour')),
        null,
        'Test Ort'
    );
    
    if ($test_event_id) {
        echo "<p style='color: green;'>✅ Test-Event erstellt: $test_event_id</p>";
        
        // 3. Teste delete_google_calendar_event Funktion
        echo "<h2>3. Teste delete_google_calendar_event Funktion</h2>";
        
        $start_time = microtime(true);
        $result = delete_google_calendar_event($test_event_id);
        $end_time = microtime(true);
        
        $duration = round(($end_time - $start_time) * 1000, 2);
        
        echo "<p><strong>Lösch-Dauer:</strong> {$duration}ms</p>";
        
        if ($result) {
            echo "<p style='color: green; font-weight: bold;'>🎉 delete_google_calendar_event funktioniert!</p>";
        } else {
            echo "<p style='color: red;'>❌ delete_google_calendar_event schlägt fehl</p>";
        }
        
        // 4. Prüfe Event Status
        echo "<h2>4. Prüfe Event Status</h2>";
        
        try {
            if (class_exists('GoogleCalendarServiceAccount')) {
                $calendar_service = new GoogleCalendarServiceAccount($service_account_json, $calendar_id, true);
                
                $is_deleted = $calendar_service->isEventDeleted($test_event_id);
                
                if ($is_deleted) {
                    echo "<p style='color: green; font-weight: bold;'>🎉 Event wird als gelöscht betrachtet!</p>";
                } else {
                    echo "<p style='color: orange;'>⚠️ Event wird noch als aktiv betrachtet</p>";
                }
                
                // Detaillierte Status-Prüfung
                try {
                    $event = $calendar_service->getEvent($test_event_id);
                    echo "<p><strong>Event Status:</strong> " . ($event['status'] ?? 'Unbekannt') . "</p>";
                    echo "<p><strong>Event Summary:</strong> " . ($event['summary'] ?? 'Unbekannt') . "</p>";
                    
                } catch (Exception $e) {
                    if (strpos($e->getMessage(), '404') !== false) {
                        echo "<p style='color: green; font-weight: bold;'>🎉 Event wurde vollständig gelöscht (404 Not Found)!</p>";
                    } else {
                        echo "<p style='color: red;'>❌ Fehler beim Abrufen des Events: " . $e->getMessage() . "</p>";
                    }
                }
            }
            
        } catch (Exception $e) {
            echo "<p style='color: red;'>❌ Fehler beim Status-Check: " . $e->getMessage() . "</p>";
        }
        
    } else {
        echo "<p style='color: red;'>❌ Test-Event konnte nicht erstellt werden</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim Test: " . $e->getMessage() . "</p>";
    echo "<p><strong>Stack Trace:</strong></p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}

// 5. Teste mit echtem Event aus Reservierungen
echo "<h2>5. Teste mit echtem Event aus Reservierungen</h2>";

$real_event_id = '02d4ivjg67pqgf92o0knhk6m9c'; // Das Event aus Ihrer Meldung

echo "<p><strong>Teste mit echtem Event:</strong> $real_event_id</p>";

try {
    $start_time = microtime(true);
    $result = delete_google_calendar_event($real_event_id);
    $end_time = microtime(true);
    
    $duration = round(($end_time - $start_time) * 1000, 2);
    
    echo "<p><strong>Lösch-Dauer:</strong> {$duration}ms</p>";
    
    if ($result) {
        echo "<p style='color: green; font-weight: bold;'>🎉 Echtes Event erfolgreich gelöscht!</p>";
    } else {
        echo "<p style='color: red;'>❌ Echtes Event konnte nicht gelöscht werden</p>";
    }
    
    // Prüfe Status des echten Events
    try {
        if (class_exists('GoogleCalendarServiceAccount')) {
            $calendar_service = new GoogleCalendarServiceAccount($service_account_json, $calendar_id, true);
            
            $is_deleted = $calendar_service->isEventDeleted($real_event_id);
            
            if ($is_deleted) {
                echo "<p style='color: green; font-weight: bold;'>🎉 Echtes Event wird als gelöscht betrachtet!</p>";
            } else {
                echo "<p style='color: orange;'>⚠️ Echtes Event wird noch als aktiv betrachtet</p>";
            }
        }
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Fehler beim Status-Check des echten Events: " . $e->getMessage() . "</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim echten Event Test: " . $e->getMessage() . "</p>";
}

echo "<hr>";
echo "<p><a href='admin/reservations.php'>→ Zur Reservierungen-Übersicht</a></p>";
echo "<p><a href='debug-reservations-delete.php'>→ Reservierungen Debug</a></p>";
echo "<p><small>Delete Fix Test abgeschlossen: " . date('Y-m-d H:i:s') . "</small></p>";
?>
