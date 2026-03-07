<?php
require_once __DIR__ . '/includes/debug-auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

echo "<h1>🔍 Debug: Google Calendar Event-Erstellung bei Genehmigung</h1>";

// 1. Prüfe ob die neue Funktion verfügbar ist
echo "<h2>1. Funktion-Verfügbarkeit prüfen</h2>";
if (function_exists('create_or_update_google_calendar_event')) {
    echo "<p style='color: green;'>✅ create_or_update_google_calendar_event Funktion verfügbar</p>";
} else {
    echo "<p style='color: red;'>❌ create_or_update_google_calendar_event Funktion NICHT verfügbar</p>";
}

if (function_exists('create_google_calendar_event')) {
    echo "<p style='color: green;'>✅ create_google_calendar_event Funktion verfügbar</p>";
} else {
    echo "<p style='color: red;'>❌ create_google_calendar_event Funktion NICHT verfügbar</p>";
}

// 2. Prüfe Google Calendar Einstellungen
echo "<h2>2. Google Calendar Einstellungen prüfen</h2>";
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

// 3. Prüfe ob es genehmigte Reservierungen gibt
echo "<h2>3. Genehmigte Reservierungen prüfen</h2>";
try {
    $stmt = $db->prepare("SELECT r.*, v.name as vehicle_name FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id WHERE r.status = 'approved' ORDER BY r.id DESC LIMIT 5");
    $stmt->execute();
    $reservations = $stmt->fetchAll();
    
    if (empty($reservations)) {
        echo "<p style='color: orange;'>⚠️ Keine genehmigten Reservierungen gefunden</p>";
    } else {
        echo "<p style='color: green;'>✅ " . count($reservations) . " genehmigte Reservierungen gefunden</p>";
        echo "<table border='1' style='border-collapse: collapse;'>";
        echo "<tr><th>ID</th><th>Fahrzeug</th><th>Grund</th><th>Start</th><th>Ende</th><th>Status</th></tr>";
        foreach ($reservations as $res) {
            echo "<tr>";
            echo "<td>" . $res['id'] . "</td>";
            echo "<td>" . $res['vehicle_name'] . "</td>";
            echo "<td>" . $res['reason'] . "</td>";
            echo "<td>" . $res['start_datetime'] . "</td>";
            echo "<td>" . $res['end_datetime'] . "</td>";
            echo "<td>" . $res['status'] . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim Laden der Reservierungen: " . $e->getMessage() . "</p>";
}

// 4. Teste die neue Funktion mit einer echten Reservierung
echo "<h2>4. Teste create_or_update_google_calendar_event</h2>";
if (!empty($reservations)) {
    $test_reservation = $reservations[0];
    echo "<p>Teste mit Reservierung ID: " . $test_reservation['id'] . "</p>";
    echo "<p>Fahrzeug: " . $test_reservation['vehicle_name'] . "</p>";
    echo "<p>Grund: " . $test_reservation['reason'] . "</p>";
    echo "<p>Zeitraum: " . $test_reservation['start_datetime'] . " - " . $test_reservation['end_datetime'] . "</p>";
    
    // Prüfe ob bereits ein calendar_events Eintrag existiert
    $stmt = $db->prepare("SELECT * FROM calendar_events WHERE reservation_id = ?");
    $stmt->execute([$test_reservation['id']]);
    $existing_calendar_event = $stmt->fetch();
    
    if ($existing_calendar_event) {
        echo "<p style='color: orange;'>⚠️ Calendar Event bereits vorhanden:</p>";
        echo "<ul>";
        echo "<li>Google Event ID: " . $existing_calendar_event['google_event_id'] . "</li>";
        echo "<li>Titel: " . $existing_calendar_event['title'] . "</li>";
        echo "<li>Erstellt: " . $existing_calendar_event['created_at'] . "</li>";
        echo "</ul>";
    } else {
        echo "<p style='color: blue;'>ℹ️ Kein Calendar Event vorhanden - teste Erstellung...</p>";
        
        // Teste die Funktion
        $result = create_or_update_google_calendar_event(
            $test_reservation['vehicle_name'],
            $test_reservation['reason'],
            $test_reservation['start_datetime'],
            $test_reservation['end_datetime'],
            $test_reservation['id'],
            $test_reservation['location'] ?? null
        );
        
        if ($result) {
            echo "<p style='color: green;'>✅ create_or_update_google_calendar_event erfolgreich: $result</p>";
        } else {
            echo "<p style='color: red;'>❌ create_or_update_google_calendar_event fehlgeschlagen</p>";
        }
    }
} else {
    echo "<p style='color: orange;'>⚠️ Keine Reservierungen zum Testen verfügbar</p>";
}

// 5. Prüfe Error Logs
echo "<h2>5. Error Logs prüfen</h2>";
$error_log_file = ini_get('error_log');
if ($error_log_file && file_exists($error_log_file)) {
    $logs = file_get_contents($error_log_file);
    $recent_logs = array_slice(explode("\n", $logs), -20);
    echo "<h3>Letzte 20 Error Log Einträge:</h3>";
    echo "<pre style='background: #f5f5f5; padding: 10px; max-height: 300px; overflow-y: auto;'>";
    foreach ($recent_logs as $log) {
        if (strpos($log, 'Google Calendar') !== false || strpos($log, 'create_or_update') !== false) {
            echo htmlspecialchars($log) . "\n";
        }
    }
    echo "</pre>";
} else {
    echo "<p style='color: orange;'>⚠️ Error Log nicht verfügbar</p>";
}

echo "<hr>";
echo "<p><a href='admin/dashboard.php'>← Zurück zum Dashboard</a></p>";
echo "<p><a href='admin/reservations.php'>← Zur Reservierungen-Übersicht</a></p>";
?>
