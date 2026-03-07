<?php
require_once __DIR__ . '/includes/debug-auth.php';
/**
 * Debug: Dashboard Environment - Teste die Dashboard-Umgebung
 */

echo "<h1>Debug: Dashboard Environment - Teste die Dashboard-Umgebung</h1>";

// Lade Funktionen
require_once 'config/database.php';
require_once 'includes/functions.php';

echo "<h2>1. Prüfe Dashboard-Umgebung</h2>";

// Teste ob create_google_calendar_event verfügbar ist
if (function_exists('create_google_calendar_event')) {
    echo "<p style='color: green;'>✅ create_google_calendar_event Funktion ist verfügbar</p>";
} else {
    echo "<p style='color: red;'>❌ create_google_calendar_event Funktion ist NICHT verfügbar</p>";
}

// Teste ob Google Calendar Klassen verfügbar sind
if (class_exists('GoogleCalendarServiceAccount')) {
    echo "<p style='color: green;'>✅ GoogleCalendarServiceAccount Klasse ist verfügbar</p>";
} else {
    echo "<p style='color: red;'>❌ GoogleCalendarServiceAccount Klasse ist NICHT verfügbar</p>";
}

if (class_exists('GoogleCalendar')) {
    echo "<p style='color: green;'>✅ GoogleCalendar Klasse ist verfügbar</p>";
} else {
    echo "<p style='color: red;'>❌ GoogleCalendar Klasse ist NICHT verfügbar</p>";
}

echo "<h2>2. Teste Dashboard-Simulation</h2>";

try {
    // Lade echte Reservierung
    $stmt = $db->prepare("SELECT r.*, v.name as vehicle_name FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id WHERE r.status = 'pending' LIMIT 1");
    $stmt->execute();
    $reservation = $stmt->fetch();
    
    if ($reservation) {
        echo "<p>✅ Echte Reservierung gefunden: ID " . $reservation['id'] . "</p>";
        
        // Simuliere Dashboard-Aufruf
        echo "<h3>2.1 Simuliere Dashboard-Aufruf</h3>";
        
        // Schreibe Test-Log
        file_put_contents('/tmp/dashboard_google_calendar.log', 
            '[' . date('Y-m-d H:i:s') . '] DEBUG: Starte Dashboard-Simulation' . PHP_EOL, 
            FILE_APPEND
        );
        
        // Teste ob create_google_calendar_event verfügbar ist
        if (function_exists('create_google_calendar_event')) {
            file_put_contents('/tmp/dashboard_google_calendar.log', 
                '[' . date('Y-m-d H:i:s') . '] DEBUG: create_google_calendar_event Funktion ist verfügbar' . PHP_EOL, 
                FILE_APPEND
            );
        } else {
            file_put_contents('/tmp/dashboard_google_calendar.log', 
                '[' . date('Y-m-d H:i:s') . '] DEBUG: create_google_calendar_event Funktion ist NICHT verfügbar' . PHP_EOL, 
                FILE_APPEND
            );
        }
        
        // Teste ob Google Calendar Klassen verfügbar sind
        if (class_exists('GoogleCalendarServiceAccount')) {
            file_put_contents('/tmp/dashboard_google_calendar.log', 
                '[' . date('Y-m-d H:i:s') . '] DEBUG: GoogleCalendarServiceAccount Klasse ist verfügbar' . PHP_EOL, 
                FILE_APPEND
            );
        } else {
            file_put_contents('/tmp/dashboard_google_calendar.log', 
                '[' . date('Y-m-d H:i:s') . '] DEBUG: GoogleCalendarServiceAccount Klasse ist NICHT verfügbar' . PHP_EOL, 
                FILE_APPEND
            );
        }
        
        // Teste create_google_calendar_event
        echo "<p>🔍 Teste create_google_calendar_event...</p>";
        
        $test_result = create_google_calendar_event(
            $reservation['vehicle_name'],
            $reservation['reason'],
            $reservation['start_datetime'],
            $reservation['end_datetime'],
            $reservation['id'],
            $reservation['location'] ?? null
        );
        
        // Schreibe Ergebnis-Log
        file_put_contents('/tmp/dashboard_google_calendar.log', 
            '[' . date('Y-m-d H:i:s') . '] DEBUG: Dashboard-Simulation Ergebnis: ' . ($test_result ? $test_result : 'false') . PHP_EOL, 
            FILE_APPEND
        );
        
        if ($test_result) {
            echo "<p style='color: green;'>✅ Dashboard-Simulation erfolgreich - Event ID: $test_result</p>";
        } else {
            echo "<p style='color: red;'>❌ Dashboard-Simulation fehlgeschlagen - Funktion gab false zurück</p>";
        }
        
        echo "<h3>2.2 Teste mit try-catch</h3>";
        
        try {
            $test_result_2 = create_google_calendar_event(
                $reservation['vehicle_name'],
                $reservation['reason'],
                $reservation['start_datetime'],
                $reservation['end_datetime'],
                $reservation['id'],
                $reservation['location'] ?? null
            );
            
            if ($test_result_2) {
                echo "<p style='color: green;'>✅ Try-catch Test erfolgreich - Event ID: $test_result_2</p>";
            } else {
                echo "<p style='color: red;'>❌ Try-catch Test fehlgeschlagen - Funktion gab false zurück</p>";
            }
        } catch (Exception $e) {
            echo "<p style='color: red;'>❌ Try-catch Test Exception: " . $e->getMessage() . "</p>";
        }
        
    } else {
        echo "<p>❌ Keine ausstehende Reservierung gefunden</p>";
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim Laden der Reservierung: " . $e->getMessage() . "</p>";
}

echo "<h2>3. Prüfe Log-Datei</h2>";

if (file_exists('/tmp/dashboard_google_calendar.log')) {
    echo "<p>✅ Log-Datei gefunden: /tmp/dashboard_google_calendar.log</p>";
    
    $log_content = file_get_contents('/tmp/dashboard_google_calendar.log');
    $lines = explode("\n", $log_content);
    $recent_lines = array_slice($lines, -15);
    
    echo "<h3>Letzte 15 Zeilen:</h3>";
    echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ddd;'>";
    foreach ($recent_lines as $line) {
        if (!empty(trim($line))) {
            echo htmlspecialchars($line) . "\n";
        }
    }
    echo "</pre>";
} else {
    echo "<p>❌ Log-Datei nicht gefunden</p>";
}

echo "<h2>4. Nächste Schritte</h2>";
echo "<p>1. <a href='admin/dashboard.php'>Teste das Dashboard</a> - Prüfe die erweiterten Logs</p>";
echo "<p>2. <a href='debug-google-calendar-fixed.php'>Prüfe die Logs</a></p>";

echo "<hr>";
echo "<p><small>Zeitstempel: " . date('Y-m-d H:i:s') . "</small></p>";
?>
