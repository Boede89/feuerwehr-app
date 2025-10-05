<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

echo "<h1>🔍 Debug: Prüfe Funktion-Version und Cache</h1>";

// Prüfe ob die Funktion die aktualisierte Version ist
echo "<h2>1. Prüfe Funktion-Code</h2>";

$reflection = new ReflectionFunction('create_or_update_google_calendar_event');
$filename = $reflection->getFileName();
$start_line = $reflection->getStartLine();
$end_line = $reflection->getEndLine();

echo "<p><strong>Funktion-Datei:</strong> $filename</p>";
echo "<p><strong>Zeilen:</strong> $start_line - $end_line</p>";

// Lese den aktuellen Code der Funktion
$lines = file($filename);
$function_code = implode('', array_slice($lines, $start_line - 1, $end_line - $start_line + 1));

echo "<h3>Funktion-Code (Zeilen $start_line-$end_line):</h3>";
echo "<pre style='background: #f5f5f5; padding: 10px; max-height: 300px; overflow-y: auto;'>";
echo htmlspecialchars($function_code);
echo "</pre>";

// Prüfe ob die Logging-Zeilen enthalten sind
if (strpos($function_code, 'create_google_calendar_event Rückgabe:') !== false) {
    echo "<p style='color: green;'>✅ Erweiterte Logging-Ausgaben sind vorhanden</p>";
} else {
    echo "<p style='color: red;'>❌ Erweiterte Logging-Ausgaben fehlen - Funktion ist nicht aktualisiert</p>";
}

// Teste die Funktion mit einem einfachen Fall
echo "<h2>2. Einfacher Funktionstest</h2>";

try {
    // Hole das erste verfügbare Fahrzeug
    $stmt = $db->prepare("SELECT id, name FROM vehicles LIMIT 1");
    $stmt->execute();
    $vehicle = $stmt->fetch();
    
    if (!$vehicle) {
        echo "<p style='color: red;'>❌ Kein Fahrzeug verfügbar</p>";
        exit;
    }
    
    // Erstelle Test-Reservierung
    $test_data = [
        'vehicle_id' => $vehicle['id'],
        'requester_name' => 'Version Test',
        'requester_email' => 'version@example.com',
        'reason' => 'Version Test - ' . date('Y-m-d H:i:s'),
        'start_datetime' => date('Y-m-d H:i:s', strtotime('+2 days 10:00')),
        'end_datetime' => date('Y-m-d H:i:s', strtotime('+2 days 12:00')),
        'location' => 'Version-Ort',
        'status' => 'approved'
    ];
    
    $stmt = $db->prepare("
        INSERT INTO reservations (vehicle_id, requester_name, requester_email, reason, start_datetime, end_datetime, location, status, created_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $test_data['vehicle_id'],
        $test_data['requester_name'],
        $test_data['requester_email'],
        $test_data['reason'],
        $test_data['start_datetime'],
        $test_data['end_datetime'],
        $test_data['location'],
        $test_data['status']
    ]);
    
    $reservation_id = $db->lastInsertId();
    echo "<p style='color: green;'>✅ Test-Reservierung erstellt (ID: $reservation_id)</p>";
    
    // Teste die Funktion
    echo "<h3>2.1 Teste create_or_update_google_calendar_event</h3>";
    
    // Aktiviere detailliertes Logging
    error_log('=== VERSION DEBUG: Starte create_or_update_google_calendar_event ===');
    
    $result = create_or_update_google_calendar_event(
        $vehicle['name'],
        $test_data['reason'],
        $test_data['start_datetime'],
        $test_data['end_datetime'],
        $reservation_id,
        $test_data['location']
    );
    
    echo "<p><strong>Ergebnis:</strong> " . ($result ? $result : 'FALSE') . "</p>";
    
    // Prüfe Error Logs
    echo "<h3>2.2 Error Logs prüfen</h3>";
    $error_log_file = ini_get('error_log');
    if ($error_log_file && file_exists($error_log_file)) {
        $logs = file_get_contents($error_log_file);
        $recent_logs = array_slice(explode("\n", $logs), -20);
        echo "<h4>Letzte 20 Error Log Einträge:</h4>";
        echo "<pre style='background: #f5f5f5; padding: 10px; max-height: 300px; overflow-y: auto;'>";
        foreach ($recent_logs as $log) {
            if (strpos($log, 'Google Calendar') !== false || 
                strpos($log, 'create_or_update') !== false || 
                strpos($log, 'VERSION DEBUG') !== false ||
                strpos($log, 'Intelligente') !== false) {
                echo htmlspecialchars($log) . "\n";
            }
        }
        echo "</pre>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler: " . $e->getMessage() . "</p>";
}

// Prüfe ob es ein Caching-Problem gibt
echo "<h2>3. Prüfe Caching und Opcache</h2>";

if (function_exists('opcache_get_status')) {
    $opcache_status = opcache_get_status();
    if ($opcache_status && $opcache_status['opcache_enabled']) {
        echo "<p style='color: orange;'>⚠️ Opcache ist aktiviert - könnte veraltete Version cachen</p>";
        echo "<p><strong>Opcache Status:</strong></p>";
        echo "<ul>";
        echo "<li>Enabled: " . ($opcache_status['opcache_enabled'] ? 'Ja' : 'Nein') . "</li>";
        echo "<li>Cache Full: " . ($opcache_status['cache_full'] ? 'Ja' : 'Nein') . "</li>";
        echo "<li>Memory Usage: " . round($opcache_status['memory_usage']['used_memory'] / 1024 / 1024, 2) . " MB</li>";
        echo "</ul>";
        
        // Versuche Opcache zu leeren
        if (function_exists('opcache_reset')) {
            echo "<p>Versuche Opcache zu leeren...</p>";
            if (opcache_reset()) {
                echo "<p style='color: green;'>✅ Opcache erfolgreich geleert</p>";
            } else {
                echo "<p style='color: red;'>❌ Opcache konnte nicht geleert werden</p>";
            }
        }
    } else {
        echo "<p style='color: green;'>✅ Opcache ist deaktiviert</p>";
    }
} else {
    echo "<p style='color: green;'>✅ Opcache ist nicht verfügbar</p>";
}

// Prüfe Datei-Zeitstempel
echo "<h2>4. Prüfe Datei-Zeitstempel</h2>";
$functions_file = 'includes/functions.php';
if (file_exists($functions_file)) {
    $file_time = filemtime($functions_file);
    echo "<p><strong>functions.php letzte Änderung:</strong> " . date('Y-m-d H:i:s', $file_time) . "</p>";
    echo "<p><strong>Jetzt:</strong> " . date('Y-m-d H:i:s') . "</p>";
    
    $time_diff = time() - $file_time;
    if ($time_diff < 300) { // Weniger als 5 Minuten
        echo "<p style='color: green;'>✅ Datei wurde vor kurzem geändert ($time_diff Sekunden)</p>";
    } else {
        echo "<p style='color: orange;'>⚠️ Datei wurde vor $time_diff Sekunden geändert</p>";
    }
} else {
    echo "<p style='color: red;'>❌ functions.php nicht gefunden</p>";
}

echo "<hr>";
echo "<p><a href='debug-function-step-by-step.php'>← Zurück zum Step Debug</a></p>";
echo "<p><a href='admin/dashboard.php'>← Zum Dashboard</a></p>";
?>
