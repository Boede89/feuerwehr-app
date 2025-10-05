<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

echo "<h1>🔍 Debug: Funktion intern analysieren</h1>";

// Erstelle Test-Reservierung
echo "<h2>1. Erstelle Test-Reservierung</h2>";

try {
    $stmt = $db->prepare("SELECT id, name FROM vehicles LIMIT 1");
    $stmt->execute();
    $vehicle = $stmt->fetch();
    
    $stmt = $db->prepare("
        INSERT INTO reservations (vehicle_id, requester_name, requester_email, reason, start_datetime, end_datetime, location, status, created_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $vehicle['id'],
        'Function Internal Debug',
        'function-internal@example.com',
        'Function Internal Debug - ' . date('Y-m-d H:i:s'),
        date('Y-m-d H:i:s', strtotime('+8 days 11:00')),
        date('Y-m-d H:i:s', strtotime('+8 days 13:00')),
        'Function-Internal-Ort',
        'approved'
    ]);
    
    $reservation_id = $db->lastInsertId();
    
    // Lade die Reservierung
    $stmt = $db->prepare("SELECT r.*, v.name as vehicle_name FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id WHERE r.id = ?");
    $stmt->execute([$reservation_id]);
    $reservation = $stmt->fetch();
    
    echo "<p style='color: green;'>✅ Test-Reservierung erstellt (ID: $reservation_id)</p>";
    echo "<p><strong>Fahrzeug:</strong> " . $reservation['vehicle_name'] . "</p>";
    echo "<p><strong>Grund:</strong> " . $reservation['reason'] . "</p>";
    echo "<p><strong>Zeitraum:</strong> " . $reservation['start_datetime'] . " - " . $reservation['end_datetime'] . "</p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim Erstellen der Test-Reservierung: " . $e->getMessage() . "</p>";
    exit;
}

// Teste die Funktion Schritt für Schritt
echo "<h2>2. Teste create_or_update_google_calendar_event Schritt für Schritt</h2>";

$vehicle_name = $reservation['vehicle_name'];
$reason = $reservation['reason'];
$start_datetime = $reservation['start_datetime'];
$end_datetime = $reservation['end_datetime'];
$reservation_id = $reservation_id;
$location = $reservation['location'];

echo "<h3>2.1 Parameter prüfen</h3>";
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

echo "<h3>2.3 Teste create_google_calendar_event direkt (vor create_or_update)</h3>";

try {
    $title = $vehicle_name . ' - ' . $reason;
    echo "<p><strong>Titel:</strong> $title</p>";
    
    error_log('FUNCTION INTERNAL DEBUG: Teste create_google_calendar_event direkt vor create_or_update');
    
    $google_event_id = create_google_calendar_event($title, $reason, $start_datetime, $end_datetime, $reservation_id, $location);
    
    if ($google_event_id) {
        echo "<p style='color: green;'>✅ create_google_calendar_event erfolgreich: $google_event_id</p>";
    } else {
        echo "<p style='color: red;'>❌ create_google_calendar_event fehlgeschlagen</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Exception bei create_google_calendar_event: " . $e->getMessage() . "</p>";
}

echo "<h3>2.4 Teste create_or_update_google_calendar_event mit detailliertem Logging</h3>";

try {
    error_log('FUNCTION INTERNAL DEBUG: Teste create_or_update_google_calendar_event mit detailliertem Logging');
    
    // Setze error_reporting auf maximum
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    
    $result = create_or_update_google_calendar_event(
        $vehicle_name,
        $reason,
        $start_datetime,
        $end_datetime,
        $reservation_id,
        $location
    );
    
    if ($result) {
        echo "<p style='color: green;'>✅ create_or_update_google_calendar_event erfolgreich: $result</p>";
    } else {
        echo "<p style='color: red;'>❌ create_or_update_google_calendar_event fehlgeschlagen</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Exception bei create_or_update_google_calendar_event: " . $e->getMessage() . "</p>";
    echo "<p style='color: red;'>Stack Trace:</p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}

// Prüfe Error Logs
echo "<h2>3. Error Logs nach detailliertem Test</h2>";

$error_log_file = ini_get('error_log');
if ($error_log_file && file_exists($error_log_file)) {
    $logs = file_get_contents($error_log_file);
    $recent_logs = array_slice(explode("\n", $logs), -100);
    echo "<h3>Letzte 100 Error Log Einträge:</h3>";
    echo "<pre style='background: #f5f5f5; padding: 10px; max-height: 600px; overflow-y: auto;'>";
    foreach ($recent_logs as $log) {
        if (strpos($log, 'Google Calendar') !== false || 
            strpos($log, 'create_or_update') !== false || 
            strpos($log, 'FUNCTION INTERNAL DEBUG') !== false ||
            strpos($log, 'Intelligente') !== false ||
            strpos($log, 'create_google_calendar_event') !== false ||
            strpos($log, 'Service Account') !== false ||
            strpos($log, 'Error') !== false ||
            strpos($log, 'Exception') !== false) {
            echo htmlspecialchars($log) . "\n";
        }
    }
    echo "</pre>";
} else {
    echo "<p style='color: orange;'>⚠️ Error Log nicht verfügbar</p>";
}

// Prüfe calendar_events Tabelle
echo "<h2>4. Prüfe calendar_events Tabelle</h2>";

try {
    $stmt = $db->prepare("SELECT * FROM calendar_events WHERE reservation_id = ?");
    $stmt->execute([$reservation_id]);
    $calendar_events = $stmt->fetchAll();
    
    if ($calendar_events) {
        echo "<p style='color: green;'>✅ " . count($calendar_events) . " Calendar Event(s) gefunden:</p>";
        foreach ($calendar_events as $i => $event) {
            echo "<h4>Event " . ($i + 1) . ":</h4>";
            echo "<ul>";
            echo "<li>ID: " . $event['id'] . "</li>";
            echo "<li>Reservation ID: " . $event['reservation_id'] . "</li>";
            echo "<li>Google Event ID: " . $event['google_event_id'] . "</li>";
            echo "<li>Titel: " . $event['title'] . "</li>";
            echo "<li>Start: " . $event['start_datetime'] . "</li>";
            echo "<li>Ende: " . $event['end_datetime'] . "</li>";
            echo "<li>Erstellt: " . $event['created_at'] . "</li>";
            echo "</ul>";
        }
    } else {
        echo "<p style='color: red;'>❌ Keine Calendar Events gefunden</p>";
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim Prüfen der calendar_events Tabelle: " . $e->getMessage() . "</p>";
}

echo "<hr>";
echo "<p><a href='admin/dashboard.php'>← Zum Dashboard</a></p>";
echo "<p><a href='debug-dashboard-exact.php'>← Zurück zur exakten Dashboard-Simulation</a></p>";
?>
