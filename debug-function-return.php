<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

echo "<h1>🔍 Debug: Warum gibt create_or_update_google_calendar_event FALSE zurück?</h1>";

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
        'requester_name' => 'Return Debug',
        'requester_email' => 'return@example.com',
        'reason' => 'Return Debug - ' . date('Y-m-d H:i:s'),
        'start_datetime' => date('Y-m-d H:i:s', strtotime('+1 day 18:00')),
        'end_datetime' => date('Y-m-d H:i:s', strtotime('+1 day 20:00')),
        'location' => 'Return-Ort',
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
echo "<h2>2. Schritt-für-Schritt Debug</h2>";

// Schritt 1: Prüfe SQL-Abfrage
echo "<h3>2.1 SQL-Abfrage testen</h3>";
try {
    $stmt = $db->prepare("
        SELECT ce.google_event_id, ce.title 
        FROM calendar_events ce 
        JOIN reservations r ON ce.reservation_id = r.id 
        WHERE r.start_datetime = ? AND r.end_datetime = ? AND r.reason = ?
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
        echo "<p style='color: green;'>✅ Kein bestehendes Event gefunden</p>";
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler bei SQL-Abfrage: " . $e->getMessage() . "</p>";
}

// Schritt 2: Teste create_google_calendar_event direkt
echo "<h3>2.2 create_google_calendar_event direkt testen</h3>";
try {
    $title = $vehicle['name'] . ' - ' . $test_data['reason'];
    $result = create_google_calendar_event(
        $title,
        $test_data['reason'],
        $test_data['start_datetime'],
        $test_data['end_datetime'],
        $reservation_id,
        $test_data['location']
    );
    
    if ($result) {
        echo "<p style='color: green;'>✅ create_google_calendar_event erfolgreich: $result</p>";
    } else {
        echo "<p style='color: red;'>❌ create_google_calendar_event fehlgeschlagen</p>";
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler bei create_google_calendar_event: " . $e->getMessage() . "</p>";
}

// Schritt 3: Teste die neue Funktion mit detailliertem Logging
echo "<h3>2.3 create_or_update_google_calendar_event mit Logging</h3>";

// Aktiviere detailliertes Logging
error_log('=== DEBUG RETURN: Starte create_or_update_google_calendar_event ===');
error_log('DEBUG RETURN: vehicle_name=' . $vehicle['name']);
error_log('DEBUG RETURN: reason=' . $test_data['reason']);
error_log('DEBUG RETURN: start=' . $test_data['start_datetime']);
error_log('DEBUG RETURN: end=' . $test_data['end_datetime']);
error_log('DEBUG RETURN: reservation_id=' . $reservation_id);

$result = create_or_update_google_calendar_event(
    $vehicle['name'],
    $test_data['reason'],
    $test_data['start_datetime'],
    $test_data['end_datetime'],
    $reservation_id,
    $test_data['location']
);

echo "<p><strong>Ergebnis der Funktion:</strong> " . ($result ? $result : 'FALSE') . "</p>";

// Prüfe Error Logs
echo "<h2>3. Error Logs nach Test</h2>";
$error_log_file = ini_get('error_log');
if ($error_log_file && file_exists($error_log_file)) {
    $logs = file_get_contents($error_log_file);
    $recent_logs = array_slice(explode("\n", $logs), -50);
    echo "<h3>Letzte 50 Error Log Einträge (Google Calendar + DEBUG RETURN):</h3>";
    echo "<pre style='background: #f5f5f5; padding: 10px; max-height: 500px; overflow-y: auto;'>";
    foreach ($recent_logs as $log) {
        if (strpos($log, 'Google Calendar') !== false || 
            strpos($log, 'create_or_update') !== false || 
            strpos($log, 'DEBUG:') !== false ||
            strpos($log, 'Intelligente') !== false ||
            strpos($log, 'DEBUG RETURN') !== false) {
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
    $stmt = $db->prepare("SELECT * FROM calendar_events WHERE reservation_id = ? ORDER BY id DESC");
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
echo "<p><a href='debug-new-function.php'>← Zurück zum Debug</a></p>";
echo "<p><a href='admin/dashboard.php'>← Zum Dashboard</a></p>";
?>
