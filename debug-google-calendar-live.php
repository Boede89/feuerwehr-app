<?php
/**
 * Debug: Google Calendar Live - Zeige aktuelle Logs
 */

echo "<h1>Debug: Google Calendar Live - Aktuelle Logs</h1>";

// Prüfe verschiedene mögliche error_log Pfade
$log_paths = [
    ini_get('error_log'), // Priorität: Aktuelle PHP error_log Einstellung
    '/var/log/php_errors.log',
    '/var/log/apache2/error.log',
    '/var/log/nginx/error.log',
    '/tmp/php_errors.log',
    'error.log',
    '/var/log/apache2/access.log',
    '/var/log/php.log'
];

echo "<h2>1. Prüfe error_log Datei</h2>";

$log_found = false;
foreach ($log_paths as $log_path) {
    if (file_exists($log_path)) {
        echo "✅ Log-Datei gefunden: $log_path<br>";
        $log_content = file_get_contents($log_path);
        $lines = explode("\n", $log_content);
        
        echo "<h3>Letzte 50 Zeilen aus $log_path:</h3>";
        echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ddd; max-height: 400px; overflow-y: auto;'>";
        $recent_lines = array_slice($lines, -50); // Letzte 50 Zeilen
        $google_calendar_found = false;
        foreach ($recent_lines as $line) {
            if (strpos($line, 'Google Calendar') !== false || strpos($line, 'Dashboard') !== false || strpos($line, 'TEST ERROR_LOG') !== false || strpos($line, 'TEST DIRECT APPROVAL') !== false) {
                echo "<strong style='color: red;'>" . htmlspecialchars($line) . "</strong>\n";
                $google_calendar_found = true;
            } else {
                echo htmlspecialchars($line) . "\n";
            }
        }
        echo "</pre>";
        
        if (!$google_calendar_found) {
            echo "<div style='color: orange; font-weight: bold;'>⚠️ Keine Google Calendar oder Dashboard Einträge in den letzten 50 Zeilen gefunden</div>";
        }
        
        $log_found = true;
        break;
    }
}

if (!$log_found) {
    echo "❌ Keine Log-Datei gefunden";
}

echo "<h2>2. Teste Google Calendar direkt</h2>";

// Lade Funktionen
require_once 'config/database.php';
require_once 'includes/functions.php';

echo "<p>✅ Funktionen geladen</p>";

// Teste Google Calendar
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
echo "<p>1. <a href='debug-google-calendar-logs.php'>Prüfe die Logs</a></p>";
echo "<p>2. <a href='admin/dashboard.php'>Teste das Dashboard</a></p>";
echo "<p>3. <a href='admin/reservations.php'>Prüfe die Reservierungen</a></p>";

echo "<hr>";
echo "<p><small>Zeitstempel: " . date('Y-m-d H:i:s') . "</small></p>";
?>
