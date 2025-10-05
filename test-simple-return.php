<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

echo "<h1>🧪 Test: Einfache Rückgabe-Test</h1>";

// Erstelle eine einfache Test-Funktion
function test_simple_return($value) {
    error_log('TEST SIMPLE: Eingabe: ' . ($value ? $value : 'FALSE'));
    error_log('TEST SIMPLE: Rückgabe: ' . ($value ? $value : 'FALSE'));
    return $value;
}

// Teste die einfache Funktion
echo "<h2>1. Teste einfache Rückgabe-Funktion</h2>";

$test_value = 'test123';
$result = test_simple_return($test_value);
echo "<p>Eingabe: $test_value</p>";
echo "<p>Rückgabe: " . ($result ? $result : 'FALSE') . "</p>";

// Teste mit FALSE
$test_false = false;
$result_false = test_simple_return($test_false);
echo "<p>Eingabe: " . ($test_false ? $test_false : 'FALSE') . "</p>";
echo "<p>Rückgabe: " . ($result_false ? $result_false : 'FALSE') . "</p>";

// Teste create_google_calendar_event direkt
echo "<h2>2. Teste create_google_calendar_event direkt</h2>";

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
        'requester_name' => 'Simple Test',
        'requester_email' => 'simple@example.com',
        'reason' => 'Simple Test - ' . date('Y-m-d H:i:s'),
        'start_datetime' => date('Y-m-d H:i:s', strtotime('+3 days 14:00')),
        'end_datetime' => date('Y-m-d H:i:s', strtotime('+3 days 16:00')),
        'location' => 'Simple-Ort',
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
    
    // Teste create_google_calendar_event
    $title = $vehicle['name'] . ' - ' . $test_data['reason'];
    echo "<p><strong>Titel:</strong> $title</p>";
    
    error_log('SIMPLE TEST: Rufe create_google_calendar_event auf');
    $google_event_id = create_google_calendar_event($title, $test_data['reason'], $test_data['start_datetime'], $test_data['end_datetime'], $reservation_id, $test_data['location']);
    error_log('SIMPLE TEST: create_google_calendar_event Rückgabe: ' . ($google_event_id ? $google_event_id : 'FALSE'));
    
    if ($google_event_id) {
        echo "<p style='color: green;'>✅ create_google_calendar_event erfolgreich: $google_event_id</p>";
    } else {
        echo "<p style='color: red;'>❌ create_google_calendar_event fehlgeschlagen</p>";
    }
    
    // Teste create_or_update_google_calendar_event
    echo "<h2>3. Teste create_or_update_google_calendar_event</h2>";
    
    error_log('SIMPLE TEST: Rufe create_or_update_google_calendar_event auf');
    $result = create_or_update_google_calendar_event(
        $vehicle['name'],
        $test_data['reason'],
        $test_data['start_datetime'],
        $test_data['end_datetime'],
        $reservation_id,
        $test_data['location']
    );
    error_log('SIMPLE TEST: create_or_update_google_calendar_event Rückgabe: ' . ($result ? $result : 'FALSE'));
    
    echo "<p><strong>Ergebnis:</strong> " . ($result ? $result : 'FALSE') . "</p>";
    
    // Prüfe Error Logs
    echo "<h2>4. Error Logs</h2>";
    $error_log_file = ini_get('error_log');
    if ($error_log_file && file_exists($error_log_file)) {
        $logs = file_get_contents($error_log_file);
        $recent_logs = array_slice(explode("\n", $logs), -30);
        echo "<h3>Letzte 30 Error Log Einträge:</h3>";
        echo "<pre style='background: #f5f5f5; padding: 10px; max-height: 400px; overflow-y: auto;'>";
        foreach ($recent_logs as $log) {
            if (strpos($log, 'Google Calendar') !== false || 
                strpos($log, 'create_or_update') !== false || 
                strpos($log, 'SIMPLE TEST') !== false ||
                strpos($log, 'Intelligente') !== false) {
                echo htmlspecialchars($log) . "\n";
            }
        }
        echo "</pre>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler: " . $e->getMessage() . "</p>";
}

echo "<hr>";
echo "<p><a href='debug-function-version.php'>← Prüfe Funktion-Version</a></p>";
echo "<p><a href='admin/dashboard.php'>← Zum Dashboard</a></p>";
?>
