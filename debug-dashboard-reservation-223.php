<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

echo "<h1>🔍 Debug: Dashboard-Reservierung 223 spezifisch testen</h1>";

// Hole Reservierung 223 (die im Dashboard fehlschlägt)
echo "<h2>1. Hole Reservierung 223</h2>";

try {
    $stmt = $db->prepare("SELECT r.*, v.name as vehicle_name FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id WHERE r.id = 223");
    $stmt->execute();
    $reservation = $stmt->fetch();
    
    if (!$reservation) {
        echo "<p style='color: red;'>❌ Reservierung 223 nicht gefunden</p>";
        exit;
    }
    
    echo "<p style='color: green;'>✅ Reservierung 223 gefunden</p>";
    echo "<ul>";
    echo "<li>ID: " . $reservation['id'] . "</li>";
    echo "<li>Fahrzeug: " . $reservation['vehicle_name'] . "</li>";
    echo "<li>Status: " . $reservation['status'] . "</li>";
    echo "<li>Grund: " . $reservation['reason'] . "</li>";
    echo "<li>Zeitraum: " . $reservation['start_datetime'] . " - " . $reservation['end_datetime'] . "</li>";
    echo "<li>Ort: " . ($reservation['location'] ?? 'Nicht angegeben') . "</li>";
    echo "</ul>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim Laden der Reservierung 223: " . $e->getMessage() . "</p>";
    exit;
}

// Setze Status auf approved (wie Dashboard)
echo "<h2>2. Setze Status auf 'approved' (wie Dashboard)</h2>";

try {
    $stmt = $db->prepare("UPDATE reservations SET status = 'approved' WHERE id = 223");
    $stmt->execute();
    echo "<p style='color: green;'>✅ Status auf 'approved' gesetzt</p>";
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim Setzen des Status: " . $e->getMessage() . "</p>";
    exit;
}

// Teste create_or_update_google_calendar_event (exakt wie Dashboard)
echo "<h2>3. Teste create_or_update_google_calendar_event (exakt wie Dashboard)</h2>";

try {
    // Lade Reservierung erneut (wie Dashboard)
    $stmt = $db->prepare("SELECT r.*, v.name as vehicle_name FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id WHERE r.id = 223");
    $stmt->execute();
    $reservation = $stmt->fetch();
    
    echo "<p><strong>Reservierung nach Status-Update:</strong></p>";
    echo "<ul>";
    echo "<li>ID: " . $reservation['id'] . "</li>";
    echo "<li>Fahrzeug: " . $reservation['vehicle_name'] . "</li>";
    echo "<li>Status: " . $reservation['status'] . "</li>";
    echo "<li>Grund: " . $reservation['reason'] . "</li>";
    echo "<li>Zeitraum: " . $reservation['start_datetime'] . " - " . $reservation['end_datetime'] . "</li>";
    echo "<li>Ort: " . ($reservation['location'] ?? 'Nicht angegeben') . "</li>";
    echo "</ul>";
    
    if ($reservation && function_exists('create_or_update_google_calendar_event')) {
        echo "<p style='color: green;'>✅ Funktion create_or_update_google_calendar_event existiert</p>";
        
        error_log('DASHBOARD RESERVATION 223 DEBUG: Teste create_or_update_google_calendar_event für Reservierung 223');
        
        $event_id = create_or_update_google_calendar_event(
            $reservation['vehicle_name'],
            $reservation['reason'],
            $reservation['start_datetime'],
            $reservation['end_datetime'],
            $reservation['id'],
            $reservation['location'] ?? null
        );
        
        if ($event_id) {
            echo "<p style='color: green;'>✅ create_or_update_google_calendar_event erfolgreich: $event_id</p>";
            echo "<p style='color: green;'>✅ Meldung würde lauten: 'Reservierung erfolgreich genehmigt und in Google Calendar eingetragen.'</p>";
        } else {
            echo "<p style='color: red;'>❌ create_or_update_google_calendar_event fehlgeschlagen</p>";
            echo "<p style='color: red;'>❌ Meldung würde lauten: 'Reservierung genehmigt, aber Google Calendar Event konnte nicht erstellt werden.'</p>";
        }
    } else {
        echo "<p style='color: red;'>❌ Funktion create_or_update_google_calendar_event existiert nicht</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Exception bei create_or_update_google_calendar_event: " . $e->getMessage() . "</p>";
    echo "<p style='color: red;'>Stack Trace:</p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}

// Prüfe Error Logs
echo "<h2>4. Error Logs nach Reservierung 223 Test</h2>";

$error_log_file = ini_get('error_log');
if ($error_log_file && file_exists($error_log_file)) {
    $logs = file_get_contents($error_log_file);
    $recent_logs = array_slice(explode("\n", $logs), -50);
    echo "<h3>Letzte 50 Error Log Einträge:</h3>";
    echo "<pre style='background: #f5f5f5; padding: 10px; max-height: 400px; overflow-y: auto;'>";
    foreach ($recent_logs as $log) {
        if (strpos($log, 'Google Calendar') !== false || 
            strpos($log, 'create_or_update') !== false || 
            strpos($log, 'DASHBOARD RESERVATION 223 DEBUG') !== false ||
            strpos($log, 'Intelligente') !== false ||
            strpos($log, 'create_google_calendar_event') !== false ||
            strpos($log, 'Service Account') !== false) {
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
    $stmt = $db->prepare("SELECT * FROM calendar_events WHERE reservation_id = 223");
    $stmt->execute();
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
echo "<p><a href='debug-function-internal.php'>← Zurück zur internen Funktions-Analyse</a></p>";
?>
