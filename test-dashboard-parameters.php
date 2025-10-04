<?php
/**
 * Test: Dashboard Parameters - Teste mit echten Dashboard-Parametern
 */

echo "<h1>Test: Dashboard Parameters - Teste mit echten Dashboard-Parametern</h1>";

// Lade Funktionen
require_once 'config/database.php';
require_once 'includes/functions.php';

echo "<h2>1. Lade echte Dashboard-Reservierung</h2>";

try {
    $stmt = $db->prepare("SELECT r.*, v.name as vehicle_name FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id WHERE r.status = 'pending' LIMIT 1");
    $stmt->execute();
    $reservation = $stmt->fetch();
    
    if ($reservation) {
        echo "<p>✅ Echte Reservierung gefunden: ID " . $reservation['id'] . "</p>";
        echo "<p>Fahrzeug: " . htmlspecialchars($reservation['vehicle_name']) . "</p>";
        echo "<p>Grund: " . htmlspecialchars($reservation['reason']) . "</p>";
        echo "<p>Start: " . $reservation['start_datetime'] . "</p>";
        echo "<p>Ende: " . $reservation['end_datetime'] . "</p>";
        echo "<p>Ort: " . ($reservation['location'] ?? 'Nicht angegeben') . "</p>";
        
        echo "<h2>2. Teste create_google_calendar_event mit Dashboard-Parametern</h2>";
        
        // Schreibe Test-Log
        file_put_contents('/tmp/dashboard_google_calendar.log', 
            '[' . date('Y-m-d H:i:s') . '] TEST: Starte Dashboard-Parameter Test' . PHP_EOL, 
            FILE_APPEND
        );
        
        echo "<p>🔍 Teste mit Dashboard-Parametern...</p>";
        
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
            '[' . date('Y-m-d H:i:s') . '] TEST: Dashboard-Parameter Test Ergebnis: ' . ($test_result ? $test_result : 'false') . PHP_EOL, 
            FILE_APPEND
        );
        
        if ($test_result) {
            echo "<p style='color: green;'>✅ Dashboard-Parameter Test erfolgreich - Event ID: $test_result</p>";
        } else {
            echo "<p style='color: red;'>❌ Dashboard-Parameter Test fehlgeschlagen - Funktion gab false zurück</p>";
        }
        
        echo "<h2>3. Teste mit verschiedenen Parameter-Kombinationen</h2>";
        
        // Test 1: Ohne location
        echo "<h3>3.1 Test ohne location</h3>";
        $test_result_1 = create_google_calendar_event(
            $reservation['vehicle_name'],
            $reservation['reason'],
            $reservation['start_datetime'],
            $reservation['end_datetime'],
            $reservation['id'],
            null
        );
        
        if ($test_result_1) {
            echo "<p style='color: green;'>✅ Test ohne location erfolgreich - Event ID: $test_result_1</p>";
        } else {
            echo "<p style='color: red;'>❌ Test ohne location fehlgeschlagen</p>";
        }
        
        // Test 2: Mit leerer location
        echo "<h3>3.2 Test mit leerer location</h3>";
        $test_result_2 = create_google_calendar_event(
            $reservation['vehicle_name'],
            $reservation['reason'],
            $reservation['start_datetime'],
            $reservation['end_datetime'],
            $reservation['id'],
            ''
        );
        
        if ($test_result_2) {
            echo "<p style='color: green;'>✅ Test mit leerer location erfolgreich - Event ID: $test_result_2</p>";
        } else {
            echo "<p style='color: red;'>❌ Test mit leerer location fehlgeschlagen</p>";
        }
        
        // Test 3: Mit 'null' location
        echo "<h3>3.3 Test mit 'null' location</h3>";
        $test_result_3 = create_google_calendar_event(
            $reservation['vehicle_name'],
            $reservation['reason'],
            $reservation['start_datetime'],
            $reservation['end_datetime'],
            $reservation['id'],
            'null'
        );
        
        if ($test_result_3) {
            echo "<p style='color: green;'>✅ Test mit 'null' location erfolgreich - Event ID: $test_result_3</p>";
        } else {
            echo "<p style='color: red;'>❌ Test mit 'null' location fehlgeschlagen</p>";
        }
        
    } else {
        echo "<p>❌ Keine ausstehende Reservierung gefunden</p>";
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim Laden der Reservierung: " . $e->getMessage() . "</p>";
}

echo "<h2>4. Prüfe Log-Datei</h2>";

if (file_exists('/tmp/dashboard_google_calendar.log')) {
    echo "<p>✅ Log-Datei gefunden: /tmp/dashboard_google_calendar.log</p>";
    
    $log_content = file_get_contents('/tmp/dashboard_google_calendar.log');
    $lines = explode("\n", $log_content);
    $recent_lines = array_slice($lines, -10);
    
    echo "<h3>Letzte 10 Zeilen:</h3>";
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

echo "<h2>5. Nächste Schritte</h2>";
echo "<p>1. <a href='admin/dashboard.php'>Teste das Dashboard</a> - Prüfe die Parameter-Logs</p>";
echo "<p>2. <a href='debug-google-calendar-fixed.php'>Prüfe die Logs</a></p>";

echo "<hr>";
echo "<p><small>Zeitstempel: " . date('Y-m-d H:i:s') . "</small></p>";
?>
