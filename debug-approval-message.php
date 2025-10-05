<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

echo "<h1>🔍 Debug: Genehmigungs-Meldung analysieren</h1>";

// Simuliere eine Genehmigung
echo "<h2>1. Simuliere Genehmigung</h2>";

try {
    // Hole eine Reservierung zum Testen
    $stmt = $db->prepare("SELECT r.*, v.name as vehicle_name FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id WHERE r.status = 'pending' LIMIT 1");
    $stmt->execute();
    $reservation = $stmt->fetch();
    
    if (!$reservation) {
        echo "<p style='color: orange;'>⚠️ Keine ausstehende Reservierung gefunden - erstelle Test-Reservierung</p>";
        
        // Erstelle Test-Reservierung
        $stmt = $db->prepare("SELECT id, name FROM vehicles LIMIT 1");
        $stmt->execute();
        $vehicle = $stmt->fetch();
        
        $stmt = $db->prepare("
            INSERT INTO reservations (vehicle_id, requester_name, requester_email, reason, start_datetime, end_datetime, location, status, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $vehicle['id'],
            'Debug Test',
            'debug@example.com',
            'Debug Test - ' . date('Y-m-d H:i:s'),
            date('Y-m-d H:i:s', strtotime('+1 day 10:00')),
            date('Y-m-d H:i:s', strtotime('+1 day 12:00')),
            'Debug-Ort',
            'pending'
        ]);
        
        $reservation_id = $db->lastInsertId();
        
        // Lade die neue Reservierung
        $stmt = $db->prepare("SELECT r.*, v.name as vehicle_name FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id WHERE r.id = ?");
        $stmt->execute([$reservation_id]);
        $reservation = $stmt->fetch();
    }
    
    echo "<p style='color: green;'>✅ Reservierung gefunden (ID: " . $reservation['id'] . ")</p>";
    echo "<p><strong>Fahrzeug:</strong> " . $reservation['vehicle_name'] . "</p>";
    echo "<p><strong>Grund:</strong> " . $reservation['reason'] . "</p>";
    echo "<p><strong>Zeitraum:</strong> " . $reservation['start_datetime'] . " - " . $reservation['end_datetime'] . "</p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim Laden der Reservierung: " . $e->getMessage() . "</p>";
    exit;
}

// Teste die Genehmigung
echo "<h2>2. Teste Genehmigung</h2>";

try {
    // Setze Status auf approved
    $stmt = $db->prepare("UPDATE reservations SET status = 'approved' WHERE id = ?");
    $stmt->execute([$reservation['id']]);
    
    echo "<p style='color: green;'>✅ Reservierung auf 'approved' gesetzt</p>";
    
    // Teste Google Calendar Event-Erstellung
    echo "<h3>2.1 Teste create_or_update_google_calendar_event</h3>";
    
    error_log('DEBUG APPROVAL: Teste create_or_update_google_calendar_event für Reservierung ' . $reservation['id']);
    
    $result = create_or_update_google_calendar_event(
        $reservation['vehicle_name'],
        $reservation['reason'],
        $reservation['start_datetime'],
        $reservation['end_datetime'],
        $reservation['id'],
        $reservation['location'] ?? null
    );
    
    if ($result) {
        echo "<p style='color: green;'>✅ Google Calendar Event erfolgreich erstellt: $result</p>";
        echo "<p style='color: green;'>✅ Meldung sollte lauten: 'Reservierung erfolgreich genehmigt und in Google Calendar eingetragen.'</p>";
    } else {
        echo "<p style='color: red;'>❌ Google Calendar Event konnte nicht erstellt werden</p>";
        echo "<p style='color: red;'>❌ Meldung würde lauten: 'Reservierung genehmigt, aber Google Calendar Event konnte nicht erstellt werden.'</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Exception bei Genehmigung: " . $e->getMessage() . "</p>";
}

// Prüfe Error Logs
echo "<h2>3. Error Logs analysieren</h2>";

$error_log_file = ini_get('error_log');
if ($error_log_file && file_exists($error_log_file)) {
    $logs = file_get_contents($error_log_file);
    $recent_logs = array_slice(explode("\n", $logs), -30);
    echo "<h3>Letzte 30 Error Log Einträge:</h3>";
    echo "<pre style='background: #f5f5f5; padding: 10px; max-height: 400px; overflow-y: auto;'>";
    foreach ($recent_logs as $log) {
        if (strpos($log, 'Google Calendar') !== false || 
            strpos($log, 'create_or_update') !== false || 
            strpos($log, 'DEBUG APPROVAL') !== false ||
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
echo "<h2>4. Prüfe calendar_events Tabelle</h2>";

try {
    $stmt = $db->prepare("SELECT * FROM calendar_events WHERE reservation_id = ?");
    $stmt->execute([$reservation['id']]);
    $calendar_event = $stmt->fetch();
    
    if ($calendar_event) {
        echo "<p style='color: green;'>✅ Calendar Event gefunden:</p>";
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
        echo "<p style='color: red;'>❌ Kein Calendar Event gefunden</p>";
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim Prüfen der calendar_events Tabelle: " . $e->getMessage() . "</p>";
}

echo "<hr>";
echo "<p><a href='admin/dashboard.php'>← Zum Dashboard</a></p>";
echo "<p><a href='debug-second-vehicle-logic.php'>← Zurück zum Logik Debug</a></p>";
?>
