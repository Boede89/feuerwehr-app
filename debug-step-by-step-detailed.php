<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

echo "<h1>🔍 Debug: Schritt-für-Schritt detaillierte Analyse</h1>";

// Erstelle eine frische Test-Reservierung
echo "<h2>1. Erstelle frische Test-Reservierung</h2>";

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
        'requester_name' => 'Detailed Debug',
        'requester_email' => 'detailed@example.com',
        'reason' => 'Detailed Debug - ' . date('Y-m-d H:i:s'),
        'start_datetime' => date('Y-m-d H:i:s', strtotime('+7 days 10:00')),
        'end_datetime' => date('Y-m-d H:i:s', strtotime('+7 days 12:00')),
        'location' => 'Detailed-Ort',
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

// Teste die Funktion Schritt für Schritt
echo "<h2>2. Schritt-für-Schritt Test der create_or_update_google_calendar_event Funktion</h2>";

$vehicle_name = $vehicle['name'];
$reason = $test_data['reason'];
$start_datetime = $test_data['start_datetime'];
$end_datetime = $test_data['end_datetime'];
$reservation_id = $reservation_id;
$location = $test_data['location'];

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

echo "<h3>2.3 Teste create_google_calendar_event direkt</h3>";

try {
    $title = $vehicle_name . ' - ' . $reason;
    echo "<p><strong>Titel:</strong> $title</p>";
    
    error_log('DETAILED DEBUG: Teste create_google_calendar_event direkt');
    
    $google_event_id = create_google_calendar_event($title, $reason, $start_datetime, $end_datetime, $reservation_id, $location);
    
    if ($google_event_id) {
        echo "<p style='color: green;'>✅ create_google_calendar_event erfolgreich: $google_event_id</p>";
    } else {
        echo "<p style='color: red;'>❌ create_google_calendar_event fehlgeschlagen</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Exception bei create_google_calendar_event: " . $e->getMessage() . "</p>";
}

echo "<h3>2.4 Teste create_or_update_google_calendar_event mit try-catch</h3>";

try {
    error_log('DETAILED DEBUG: Teste create_or_update_google_calendar_event mit try-catch');
    
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
echo "<h2>3. Error Logs nach allen Tests</h2>";

$error_log_file = ini_get('error_log');
if ($error_log_file && file_exists($error_log_file)) {
    $logs = file_get_contents($error_log_file);
    $recent_logs = array_slice(explode("\n", $logs), -50);
    echo "<h3>Letzte 50 Error Log Einträge:</h3>";
    echo "<pre style='background: #f5f5f5; padding: 10px; max-height: 500px; overflow-y: auto;'>";
    foreach ($recent_logs as $log) {
        if (strpos($log, 'Google Calendar') !== false || 
            strpos($log, 'create_or_update') !== false || 
            strpos($log, 'DETAILED DEBUG') !== false ||
            strpos($log, 'Intelligente') !== false ||
            strpos($log, 'create_google_calendar_event') !== false) {
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

// Teste Google Calendar Einstellungen
echo "<h2>5. Teste Google Calendar Einstellungen</h2>";

try {
    $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'google_calendar_%'");
    $stmt->execute();
    $settings = [];
    while ($row = $stmt->fetch()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    
    echo "<table border='1' style='border-collapse: collapse;'>";
    echo "<tr><th>Einstellung</th><th>Wert</th></tr>";
    foreach ($settings as $key => $value) {
        if ($key === 'google_calendar_service_account_json') {
            $value = $value ? 'Vorhanden (' . strlen($value) . ' Zeichen)' : 'Nicht gesetzt';
        }
        echo "<tr><td>$key</td><td>$value</td></tr>";
    }
    echo "</table>";
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim Laden der Einstellungen: " . $e->getMessage() . "</p>";
}

echo "<hr>";
echo "<p><a href='admin/dashboard.php'>← Zum Dashboard</a></p>";
echo "<p><a href='debug-approval-message.php'>← Zurück zum Approval Debug</a></p>";
?>
