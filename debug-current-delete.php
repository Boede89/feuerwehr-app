<?php
/**
 * Debug: Aktuelle delete_google_calendar_event Funktion testen
 */

require_once 'config/database.php';
require_once 'includes/functions.php';

echo "<h1>🔍 Debug: Aktuelle delete_google_calendar_event Funktion</h1>";

// 1. Prüfe ob die Funktion existiert
echo "<h2>1. Funktion existiert?</h2>";

if (function_exists('delete_google_calendar_event')) {
    echo "<p style='color: green;'>✅ delete_google_calendar_event Funktion ist verfügbar</p>";
} else {
    echo "<p style='color: red;'>❌ delete_google_calendar_event Funktion ist NICHT verfügbar</p>";
}

// 2. Prüfe die aktuelle Version der Funktion
echo "<h2>2. Funktion Code prüfen</h2>";

$reflection = new ReflectionFunction('delete_google_calendar_event');
$filename = $reflection->getFileName();
$start_line = $reflection->getStartLine();
$end_line = $reflection->getEndLine();

echo "<p><strong>Datei:</strong> $filename</p>";
echo "<p><strong>Zeilen:</strong> $start_line - $end_line</p>";

// Lese den relevanten Teil der Funktion
$lines = file($filename);
$function_lines = array_slice($lines, $start_line - 1, $end_line - $start_line + 1);

echo "<h3>2.1 Relevanter Code (Service Account JSON):</h3>";
echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ddd;'>";

$in_function = false;
foreach ($function_lines as $line_num => $line) {
    $actual_line = $start_line + $line_num;
    
    if (strpos($line, 'google_calendar_service_account') !== false) {
        echo "<strong>Zeile $actual_line:</strong> " . htmlspecialchars($line);
        
        if (strpos($line, 'google_calendar_service_account_json') !== false) {
            echo " <span style='color: green;'>✅ KORREKT</span>";
        } elseif (strpos($line, 'google_calendar_service_account') !== false) {
            echo " <span style='color: red;'>❌ FALSCH - sollte _json sein</span>";
        }
    }
}

echo "</pre>";

// 3. Teste die Funktion direkt
echo "<h2>3. Teste Funktion direkt</h2>";

try {
    // Erstelle Test-Event
    $test_event_id = create_google_calendar_event(
        'Debug Test',
        'Debug Test Event - ' . date('Y-m-d H:i:s'),
        date('Y-m-d H:i:s'),
        date('Y-m-d H:i:s', strtotime('+1 hour')),
        null,
        'Debug Ort'
    );
    
    if ($test_event_id) {
        echo "<p style='color: green;'>✅ Test-Event erstellt: $test_event_id</p>";
        
        // Teste Löschen
        $start_time = microtime(true);
        $result = delete_google_calendar_event($test_event_id);
        $end_time = microtime(true);
        
        $duration = round(($end_time - $start_time) * 1000, 2);
        
        echo "<p><strong>Lösch-Dauer:</strong> {$duration}ms</p>";
        
        if ($result) {
            echo "<p style='color: green; font-weight: bold;'>🎉 Funktion funktioniert korrekt!</p>";
        } else {
            echo "<p style='color: red;'>❌ Funktion schlägt fehl</p>";
        }
        
    } else {
        echo "<p style='color: red;'>❌ Test-Event konnte nicht erstellt werden</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim Test: " . $e->getMessage() . "</p>";
    echo "<p><strong>Stack Trace:</strong></p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}

// 4. Teste mit dem echten Event
echo "<h2>4. Teste mit echtem Event</h2>";

$real_event_id = '6lu3icbt1ketrk3tp8kujqs4fs'; // Das Event aus Ihrer Meldung

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
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim echten Event Test: " . $e->getMessage() . "</p>";
}

// 5. Prüfe ob admin/reservations.php die richtige Version lädt
echo "<h2>5. Prüfe admin/reservations.php</h2>";

$reservations_file = 'admin/reservations.php';
if (file_exists($reservations_file)) {
    $content = file_get_contents($reservations_file);
    
    if (strpos($content, 'delete_google_calendar_event') !== false) {
        echo "<p style='color: green;'>✅ admin/reservations.php verwendet delete_google_calendar_event</p>";
        
        // Prüfe die Meldung
        if (strpos($content, 'Der Google Calendar Eintrag muss manuell gelöscht werden') !== false) {
            echo "<p style='color: orange;'>⚠️ admin/reservations.php hat noch die alte Meldung</p>";
        } else {
            echo "<p style='color: green;'>✅ admin/reservations.php hat die neue Meldung</p>";
        }
        
    } else {
        echo "<p style='color: red;'>❌ admin/reservations.php verwendet delete_google_calendar_event nicht</p>";
    }
} else {
    echo "<p style='color: red;'>❌ admin/reservations.php nicht gefunden</p>";
}

echo "<hr>";
echo "<p><a href='admin/reservations.php'>→ Zur Reservierungen-Übersicht</a></p>";
echo "<p><a href='test-delete-fix.php'>→ Delete Fix Test</a></p>";
echo "<p><small>Debug abgeschlossen: " . date('Y-m-d H:i:s') . "</small></p>";
?>
