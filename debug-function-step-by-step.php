<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

echo "<h1>🔍 Debug: Schritt-für-Schritt Analyse der Funktion</h1>";

// Erstelle eine Test-Reservierung
echo "<h2>1. Erstelle Test-Reservierung</h2>";

try {
    // Hole das erste verfügbare Fahrzeug
    $stmt = $db->prepare("SELECT id, name FROM vehicles LIMIT 1");
    $stmt->execute();
    $vehicle = $stmt->fetch();
    
    if (!$vehicle) {
        echo "<p style='color: red;'>❌ Kein Fahrzeug verfügbar</p>";
        exit;
    }
    
    echo "<p>Verwende Fahrzeug: " . $vehicle['name'] . " (ID: " . $vehicle['id'] . ")</p>";
    
    // Erstelle Test-Reservierung
    $test_data = [
        'vehicle_id' => $vehicle['id'],
        'requester_name' => 'Step Debug',
        'requester_email' => 'step@example.com',
        'reason' => 'Step Debug - ' . date('Y-m-d H:i:s'),
        'start_datetime' => date('Y-m-d H:i:s', strtotime('+1 day 22:00')),
        'end_datetime' => date('Y-m-d H:i:s', strtotime('+1 day 24:00')),
        'location' => 'Step-Ort',
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
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim Erstellen der Test-Reservierung: " . $e->getMessage() . "</p>";
    exit;
}

// Simuliere die Funktion Schritt für Schritt
echo "<h2>2. Simuliere create_or_update_google_calendar_event Schritt für Schritt</h2>";

$vehicle_name = $vehicle['name'];
$reason = $test_data['reason'];
$start_datetime = $test_data['start_datetime'];
$end_datetime = $test_data['end_datetime'];
$reservation_id = $reservation_id;
$location = $test_data['location'];

echo "<h3>2.1 Parameter</h3>";
echo "<ul>";
echo "<li>vehicle_name: $vehicle_name</li>";
echo "<li>reason: $reason</li>";
echo "<li>start_datetime: $start_datetime</li>";
echo "<li>end_datetime: $end_datetime</li>";
echo "<li>reservation_id: $reservation_id</li>";
echo "<li>location: $location</li>";
echo "</ul>";

echo "<h3>2.2 SQL-Abfrage für bestehende Events</h3>";
try {
    $stmt = $db->prepare("
        SELECT ce.google_event_id, ce.title 
        FROM calendar_events ce 
        JOIN reservations r ON ce.reservation_id = r.id 
        WHERE r.start_datetime = ? AND r.end_datetime = ? AND r.reason = ?
        LIMIT 1
    ");
    $stmt->execute([$start_datetime, $end_datetime, $reason]);
    $existing_event = $stmt->fetch();
    
    if ($existing_event) {
        echo "<p style='color: orange;'>⚠️ Bestehendes Event gefunden:</p>";
        echo "<ul>";
        echo "<li>Google Event ID: " . $existing_event['google_event_id'] . "</li>";
        echo "<li>Titel: " . $existing_event['title'] . "</li>";
        echo "</ul>";
    } else {
        echo "<p style='color: green;'>✅ Kein bestehendes Event gefunden - gehe zu 'else' Zweig</p>";
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler bei SQL-Abfrage: " . $e->getMessage() . "</p>";
}

echo "<h3>2.3 Erstelle neues Event (else Zweig)</h3>";
echo "<p>Da kein bestehendes Event gefunden wurde, sollte der 'else' Zweig ausgeführt werden:</p>";
echo "<pre>";
echo "// Kein Event existiert - erstelle neues\n";
echo "error_log('Kein bestehendes Event gefunden - erstelle neues');\n";
echo "\$title = \$vehicle_name . ' - ' . \$reason;\n";
echo "\$google_event_id = create_google_calendar_event(\$title, \$reason, \$start_datetime, \$end_datetime, \$reservation_id, \$location);\n";
echo "error_log('create_google_calendar_event Ergebnis: ' . (\$google_event_id ? \$google_event_id : 'FALSE'));\n";
echo "return \$google_event_id;\n";
echo "</pre>";

$title = $vehicle_name . ' - ' . $reason;
echo "<p><strong>Titel:</strong> $title</p>";

echo "<h3>2.4 Teste create_google_calendar_event</h3>";
try {
    $google_event_id = create_google_calendar_event($title, $reason, $start_datetime, $end_datetime, $reservation_id, $location);
    
    if ($google_event_id) {
        echo "<p style='color: green;'>✅ create_google_calendar_event erfolgreich: $google_event_id</p>";
    } else {
        echo "<p style='color: red;'>❌ create_google_calendar_event fehlgeschlagen</p>";
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Exception bei create_google_calendar_event: " . $e->getMessage() . "</p>";
}

echo "<h3>2.5 Teste die komplette Funktion</h3>";

// Aktiviere detailliertes Logging
error_log('=== STEP DEBUG: Starte create_or_update_google_calendar_event ===');

$result = create_or_update_google_calendar_event(
    $vehicle_name,
    $reason,
    $start_datetime,
    $end_datetime,
    $reservation_id,
    $location
);

echo "<p><strong>Ergebnis der Funktion:</strong> " . ($result ? $result : 'FALSE') . "</p>";

// Prüfe Error Logs
echo "<h2>3. Error Logs nach Test</h2>";
$error_log_file = ini_get('error_log');
if ($error_log_file && file_exists($error_log_file)) {
    $logs = file_get_contents($error_log_file);
    $recent_logs = array_slice(explode("\n", $logs), -30);
    echo "<h3>Letzte 30 Error Log Einträge:</h3>";
    echo "<pre style='background: #f5f5f5; padding: 10px; max-height: 400px; overflow-y: auto;'>";
    foreach ($recent_logs as $log) {
        if (strpos($log, 'Google Calendar') !== false || 
            strpos($log, 'create_or_update') !== false || 
            strpos($log, 'DEBUG:') !== false ||
            strpos($log, 'Intelligente') !== false ||
            strpos($log, 'STEP DEBUG') !== false) {
            echo htmlspecialchars($log) . "\n";
        }
    }
    echo "</pre>";
} else {
    echo "<p style='color: orange;'>⚠️ Error Log nicht verfügbar</p>";
}

// Prüfe ob die Funktion möglicherweise eine Exception wirft
echo "<h2>4. Teste mit try-catch</h2>";
try {
    $result_with_try_catch = create_or_update_google_calendar_event(
        $vehicle_name,
        $reason,
        $start_datetime,
        $end_datetime,
        $reservation_id,
        $location
    );
    
    echo "<p><strong>Ergebnis mit try-catch:</strong> " . ($result_with_try_catch ? $result_with_try_catch : 'FALSE') . "</p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Exception in der Funktion: " . $e->getMessage() . "</p>";
    echo "<p style='color: red;'>Stack Trace:</p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}

echo "<hr>";
echo "<p><a href='debug-function-return.php'>← Zurück zum Return Debug</a></p>";
echo "<p><a href='admin/dashboard.php'>← Zum Dashboard</a></p>";
?>
