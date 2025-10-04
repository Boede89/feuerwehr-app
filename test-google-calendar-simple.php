<?php
/**
 * Test: Google Calendar Simple - Teste ohne Logs
 */

echo "<h1>Test: Google Calendar Simple - Teste ohne Logs</h1>";

// Lade Funktionen
require_once 'config/database.php';
require_once 'includes/functions.php';

echo "<h2>1. Teste Google Calendar direkt</h2>";

if (function_exists('create_google_calendar_event')) {
    echo "<p>✅ create_google_calendar_event Funktion verfügbar</p>";
    
    echo "<p>🔍 Teste mit Test-Daten...</p>";
    
    $test_result = create_google_calendar_event(
        'Test Fahrzeug',
        'Test Grund',
        '2025-10-05 10:00:00',
        '2025-10-05 12:00:00',
        999999,
        'Test Ort'
    );
    
    if ($test_result) {
        echo "<p style='color: green;'>✅ Test erfolgreich - Event ID: $test_result</p>";
    } else {
        echo "<p style='color: red;'>❌ Test fehlgeschlagen - Funktion gab false zurück</p>";
    }
} else {
    echo "<p style='color: red;'>❌ create_google_calendar_event Funktion nicht verfügbar</p>";
}

echo "<h2>2. Teste Google Calendar mit echten Dashboard-Daten</h2>";

// Lade eine echte Reservierung aus der Datenbank
try {
    $stmt = $db->prepare("SELECT r.*, v.name as vehicle_name FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id WHERE r.status = 'pending' LIMIT 1");
    $stmt->execute();
    $reservation = $stmt->fetch();
    
    if ($reservation) {
        echo "<p>✅ Echte Reservierung gefunden: ID " . $reservation['id'] . "</p>";
        echo "<p>Fahrzeug: " . htmlspecialchars($reservation['vehicle_name']) . "</p>";
        echo "<p>Grund: " . htmlspecialchars($reservation['reason']) . "</p>";
        echo "<p>Start: " . $reservation['start_datetime'] . "</p>";
        echo "<p>Ende: " . $reservation['end_datetime'] . "</p>";
        echo "<p>Ort: " . ($reservation['location'] ?? 'Nicht angegeben') . "</p>";
        
        echo "<p>🔍 Teste mit echten Daten...</p>";
        
        $real_result = create_google_calendar_event(
            $reservation['vehicle_name'],
            $reservation['reason'],
            $reservation['start_datetime'],
            $reservation['end_datetime'],
            $reservation['id'],
            $reservation['location'] ?? null
        );
        
        if ($real_result) {
            echo "<p style='color: green;'>✅ Echter Test erfolgreich - Event ID: $real_result</p>";
        } else {
            echo "<p style='color: red;'>❌ Echter Test fehlgeschlagen - Funktion gab false zurück</p>";
        }
    } else {
        echo "<p>❌ Keine ausstehende Reservierung gefunden</p>";
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim Laden der Reservierung: " . $e->getMessage() . "</p>";
}

echo "<h2>3. Prüfe Google Calendar Einstellungen</h2>";

try {
    $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'google_calendar_%'");
    $stmt->execute();
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    echo "<table border='1' style='border-collapse: collapse;'>";
    echo "<tr><th>Einstellung</th><th>Wert</th></tr>";
    foreach ($settings as $key => $value) {
        if ($key === 'google_calendar_service_account_json') {
            $display_value = !empty($value) ? 'Gesetzt (' . strlen($value) . ' Zeichen)' : 'Nicht gesetzt';
        } else {
            $display_value = htmlspecialchars($value);
        }
        echo "<tr><td>$key</td><td>$display_value</td></tr>";
    }
    echo "</table>";
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim Laden der Einstellungen: " . $e->getMessage() . "</p>";
}

echo "<h2>4. Prüfe Google Calendar Klassen</h2>";

if (class_exists('GoogleCalendarServiceAccount')) {
    echo "<p style='color: green;'>✅ GoogleCalendarServiceAccount Klasse verfügbar</p>";
} else {
    echo "<p style='color: red;'>❌ GoogleCalendarServiceAccount Klasse NICHT verfügbar</p>";
}

if (class_exists('GoogleCalendar')) {
    echo "<p style='color: green;'>✅ GoogleCalendar Klasse verfügbar</p>";
} else {
    echo "<p style='color: red;'>❌ GoogleCalendar Klasse NICHT verfügbar</p>";
}

echo "<h2>5. Nächste Schritte</h2>";
echo "<p>1. <a href='test-logging-detailed.php'>Teste Logging Detailed</a></p>";
echo "<p>2. <a href='test-google-calendar-direct.php'>Teste Google Calendar direkt</a></p>";
echo "<p>3. <a href='admin/dashboard.php'>Teste das Dashboard</a></p>";

echo "<hr>";
echo "<p><small>Zeitstempel: " . date('Y-m-d H:i:s') . "</small></p>";
?>
