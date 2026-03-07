<?php
require_once __DIR__ . '/includes/debug-auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

echo "<h1>🔍 Debug: create_or_update_google_calendar_event Funktion</h1>";

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
        'requester_name' => 'Debug Test',
        'requester_email' => 'debug@example.com',
        'reason' => 'Debug Test - ' . date('Y-m-d H:i:s'),
        'start_datetime' => date('Y-m-d H:i:s', strtotime('+1 day 14:00')),
        'end_datetime' => date('Y-m-d H:i:s', strtotime('+1 day 16:00')),
        'location' => 'Debug-Ort',
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

// Debug die SQL-Abfrage der neuen Funktion
echo "<h2>2. Debug SQL-Abfrage</h2>";

try {
    $stmt = $db->prepare("
        SELECT ce.google_event_id, ce.title 
        FROM calendar_events ce 
        JOIN reservations r ON ce.reservation_id = r.id 
        WHERE r.start_datetime = ? AND r.end_datetime = ? AND r.reason = ? AND r.status = 'approved'
        LIMIT 1
    ");
    $stmt->execute([$test_data['start_datetime'], $test_data['end_datetime'], $test_data['reason']]);
    $existing_event = $stmt->fetch();
    
    if ($existing_event) {
        echo "<p style='color: orange;'>⚠️ Bestehendes Event gefunden:</p>";
        echo "<ul>";
        echo "<li>Google Event ID: " . $existing_event['google_event_id'] . "</li>";
        echo "<li>Titel: " . $existing_event['title'] . "</li>";
        echo "</ul>";
    } else {
        echo "<p style='color: green;'>✅ Kein bestehendes Event gefunden - sollte neues erstellen</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler bei SQL-Abfrage: " . $e->getMessage() . "</p>";
}

// Teste die neue Funktion mit detailliertem Logging
echo "<h2>3. Teste neue Funktion mit Logging</h2>";

// Aktiviere detailliertes Logging
error_log('=== DEBUG: Starte create_or_update_google_calendar_event Test ===');

$result = create_or_update_google_calendar_event(
    $vehicle['name'],
    $test_data['reason'],
    $test_data['start_datetime'],
    $test_data['end_datetime'],
    $reservation_id,
    $test_data['location']
);

echo "<p>Ergebnis: " . ($result ? $result : 'FALSE') . "</p>";

// Prüfe Error Logs
echo "<h2>4. Error Logs nach Test</h2>";
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
            strpos($log, 'Intelligente') !== false) {
            echo htmlspecialchars($log) . "\n";
        }
    }
    echo "</pre>";
} else {
    echo "<p style='color: orange;'>⚠️ Error Log nicht verfügbar</p>";
}

// Prüfe ob calendar_events Eintrag erstellt wurde
echo "<h2>5. Prüfe calendar_events Tabelle</h2>";
try {
    $stmt = $db->prepare("SELECT * FROM calendar_events WHERE reservation_id = ?");
    $stmt->execute([$reservation_id]);
    $calendar_event = $stmt->fetch();
    
    if ($calendar_event) {
        echo "<p style='color: green;'>✅ Calendar Event erstellt:</p>";
        echo "<ul>";
        echo "<li>ID: " . $calendar_event['id'] . "</li>";
        echo "<li>Reservation ID: " . $calendar_event['reservation_id'] . "</li>";
        echo "<li>Google Event ID: " . $calendar_event['google_event_id'] . "</li>";
        echo "<li>Titel: " . $calendar_event['title'] . "</li>";
        echo "<li>Start: " . $calendar_event['start_datetime'] . "</li>";
        echo "<li>Ende: " . $calendar_event['end_datetime'] . "</li>";
        echo "<li>Erstellt: " . $calendar_event['created_at'] . "</li>";
        echo "</ul>";
    } else {
        echo "<p style='color: red;'>❌ Kein Calendar Event erstellt</p>";
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim Prüfen der calendar_events Tabelle: " . $e->getMessage() . "</p>";
}

echo "<hr>";
echo "<p><a href='test-calendar-approval.php'>← Zurück zum Test</a></p>";
echo "<p><a href='admin/dashboard.php'>← Zum Dashboard</a></p>";
?>
