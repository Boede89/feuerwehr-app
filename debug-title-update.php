<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

echo "<h1>🔍 Debug: Titel-Update Problem beim zweiten Fahrzeug</h1>";

// Erstelle zwei Test-Reservierungen mit gleichem Zeitraum/Grund
echo "<h2>1. Erstelle Test-Reservierungen</h2>";

try {
    // Hole zwei verschiedene Fahrzeuge
    $stmt = $db->prepare("SELECT id, name FROM vehicles LIMIT 2");
    $stmt->execute();
    $vehicles = $stmt->fetchAll();
    
    if (count($vehicles) < 2) {
        echo "<p style='color: red;'>❌ Nicht genügend Fahrzeuge verfügbar</p>";
        exit;
    }
    
    $vehicle1 = $vehicles[0];
    $vehicle2 = $vehicles[1];
    
    echo "<p>Fahrzeug 1: " . $vehicle1['name'] . " (ID: " . $vehicle1['id'] . ")</p>";
    echo "<p>Fahrzeug 2: " . $vehicle2['name'] . " (ID: " . $vehicle2['id'] . ")</p>";
    
    // Gleicher Zeitraum und Grund für beide
    $shared_reason = 'Titel-Update Test - ' . date('Y-m-d H:i:s');
    $shared_start = date('Y-m-d H:i:s', strtotime('+5 days 10:00'));
    $shared_end = date('Y-m-d H:i:s', strtotime('+5 days 12:00'));
    $shared_location = 'Titel-Update-Ort';
    
    // Erstelle erste Reservierung
    $stmt = $db->prepare("
        INSERT INTO reservations (vehicle_id, requester_name, requester_email, reason, start_datetime, end_datetime, location, status, created_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $vehicle1['id'],
        'Test User 1',
        'test1@example.com',
        $shared_reason,
        $shared_start,
        $shared_end,
        $shared_location,
        'approved'
    ]);
    
    $reservation1_id = $db->lastInsertId();
    echo "<p style='color: green;'>✅ Erste Reservierung erstellt (ID: $reservation1_id)</p>";
    
    // Erstelle zweite Reservierung
    $stmt = $db->prepare("
        INSERT INTO reservations (vehicle_id, requester_name, requester_email, reason, start_datetime, end_datetime, location, status, created_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $vehicle2['id'],
        'Test User 2',
        'test2@example.com',
        $shared_reason,
        $shared_start,
        $shared_end,
        $shared_location,
        'approved'
    ]);
    
    $reservation2_id = $db->lastInsertId();
    echo "<p style='color: green;'>✅ Zweite Reservierung erstellt (ID: $reservation2_id)</p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim Erstellen der Test-Reservierungen: " . $e->getMessage() . "</p>";
    exit;
}

// Teste erste Reservierung (sollte neues Event erstellen)
echo "<h2>2. Teste erste Reservierung (neues Event)</h2>";

try {
    error_log('TITEL UPDATE DEBUG: Teste erste Reservierung');
    
    $result1 = create_or_update_google_calendar_event(
        $vehicle1['name'],
        $shared_reason,
        $shared_start,
        $shared_end,
        $reservation1_id,
        $shared_location
    );
    
    if ($result1) {
        echo "<p style='color: green;'>✅ Erste Reservierung erfolgreich: $result1</p>";
    } else {
        echo "<p style='color: red;'>❌ Erste Reservierung fehlgeschlagen</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Exception bei erster Reservierung: " . $e->getMessage() . "</p>";
}

// Teste zweite Reservierung (sollte Titel erweitern)
echo "<h2>3. Teste zweite Reservierung (Titel erweitern)</h2>";

try {
    error_log('TITEL UPDATE DEBUG: Teste zweite Reservierung');
    
    $result2 = create_or_update_google_calendar_event(
        $vehicle2['name'],
        $shared_reason,
        $shared_start,
        $shared_end,
        $reservation2_id,
        $shared_location
    );
    
    if ($result2) {
        echo "<p style='color: green;'>✅ Zweite Reservierung erfolgreich: $result2</p>";
    } else {
        echo "<p style='color: red;'>❌ Zweite Reservierung fehlgeschlagen</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Exception bei zweiter Reservierung: " . $e->getMessage() . "</p>";
}

// Prüfe Error Logs
echo "<h2>4. Error Logs analysieren</h2>";

$error_log_file = ini_get('error_log');
if ($error_log_file && file_exists($error_log_file)) {
    $logs = file_get_contents($error_log_file);
    $recent_logs = array_slice(explode("\n", $logs), -50);
    echo "<h3>Letzte 50 Error Log Einträge:</h3>";
    echo "<pre style='background: #f5f5f5; padding: 10px; max-height: 500px; overflow-y: auto;'>";
    foreach ($recent_logs as $log) {
        if (strpos($log, 'Google Calendar') !== false || 
            strpos($log, 'create_or_update') !== false || 
            strpos($log, 'TITEL UPDATE') !== false ||
            strpos($log, 'Intelligente') !== false ||
            strpos($log, 'update_google_calendar_event_title') !== false) {
            echo htmlspecialchars($log) . "\n";
        }
    }
    echo "</pre>";
} else {
    echo "<p style='color: orange;'>⚠️ Error Log nicht verfügbar</p>";
}

// Prüfe calendar_events Tabelle
echo "<h2>5. Prüfe calendar_events Tabelle</h2>";

try {
    $stmt = $db->prepare("SELECT * FROM calendar_events WHERE reservation_id IN (?, ?) ORDER BY id");
    $stmt->execute([$reservation1_id, $reservation2_id]);
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

// Teste update_google_calendar_event_title direkt
echo "<h2>6. Teste update_google_calendar_event_title direkt</h2>";

if (function_exists('update_google_calendar_event_title')) {
    echo "<p style='color: green;'>✅ update_google_calendar_event_title Funktion verfügbar</p>";
    
    // Hole die Google Event ID der ersten Reservierung
    $stmt = $db->prepare("SELECT google_event_id FROM calendar_events WHERE reservation_id = ?");
    $stmt->execute([$reservation1_id]);
    $first_event = $stmt->fetch();
    
    if ($first_event) {
        $google_event_id = $first_event['google_event_id'];
        $new_title = $vehicle1['name'] . ', ' . $vehicle2['name'] . ' - ' . $shared_reason;
        
        echo "<p>Teste Titel-Update für Event: $google_event_id</p>";
        echo "<p>Neuer Titel: $new_title</p>";
        
        $update_result = update_google_calendar_event_title($google_event_id, $new_title);
        
        if ($update_result) {
            echo "<p style='color: green;'>✅ Titel-Update erfolgreich</p>";
        } else {
            echo "<p style='color: red;'>❌ Titel-Update fehlgeschlagen</p>";
        }
    } else {
        echo "<p style='color: orange;'>⚠️ Keine Google Event ID für erste Reservierung gefunden</p>";
    }
} else {
    echo "<p style='color: red;'>❌ update_google_calendar_event_title Funktion nicht verfügbar</p>";
}

echo "<hr>";
echo "<p><a href='admin/dashboard.php'>← Zum Dashboard</a></p>";
echo "<p><a href='fix-opcache-problem.php'>← Zurück zum Opcache Fix</a></p>";
?>
