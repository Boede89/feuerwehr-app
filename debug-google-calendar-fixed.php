<?php
require_once __DIR__ . '/includes/debug-auth.php';
/**
 * Debug: Google Calendar Fixed - Zeige Logs aus der richtigen Datei
 */

echo "<h1>Debug: Google Calendar Fixed - Zeige Logs aus der richtigen Datei</h1>";

echo "<h2>1. Prüfe alle möglichen Log-Dateien</h2>";

$log_paths = [
    '/tmp/dashboard_google_calendar.log',
    '/tmp/test_detailed.log',
    '/tmp/php_errors.log',
    ini_get('error_log'),
    '/var/log/php_errors.log',
    '/var/log/apache2/error.log',
    '/var/log/nginx/error.log',
    'error.log',
    'test.log'
];

$log_found = false;
foreach ($log_paths as $log_path) {
    if (file_exists($log_path)) {
        echo "✅ Log-Datei gefunden: $log_path<br>";
        $log_content = file_get_contents($log_path);
        $lines = explode("\n", $log_content);
        
        echo "<h3>Letzte 20 Zeilen aus $log_path:</h3>";
        echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ddd; max-height: 300px; overflow-y: auto;'>";
        $recent_lines = array_slice($lines, -20);
        $google_calendar_found = false;
        foreach ($recent_lines as $line) {
            if (strpos($line, 'Google Calendar') !== false || strpos($line, 'Dashboard') !== false || strpos($line, 'TEST') !== false) {
                echo "<strong style='color: red;'>" . htmlspecialchars($line) . "</strong>\n";
                $google_calendar_found = true;
            } else {
                echo htmlspecialchars($line) . "\n";
            }
        }
        echo "</pre>";
        
        if ($google_calendar_found) {
            echo "<div style='color: green; font-weight: bold;'>✅ Google Calendar oder Dashboard Einträge gefunden!</div>";
        } else {
            echo "<div style='color: orange; font-weight: bold;'>⚠️ Keine Google Calendar oder Dashboard Einträge gefunden</div>";
        }
        
        $log_found = true;
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

echo "<h2>3. Teste Dashboard-Logging</h2>";

// Schreibe Test-Log in Dashboard-Log-Datei
$test_log_message = '[' . date('Y-m-d H:i:s') . '] TEST: Dashboard Google Calendar Test' . PHP_EOL;
file_put_contents('/tmp/dashboard_google_calendar.log', $test_log_message, FILE_APPEND);
echo "<p>✅ Test-Log in /tmp/dashboard_google_calendar.log geschrieben</p>";

echo "<h2>4. Prüfe Google Calendar Einstellungen</h2>";

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

echo "<h2>5. Nächste Schritte</h2>";
echo "<p>1. <a href='admin/dashboard.php'>Teste das Dashboard</a> - Google Calendar sollte jetzt funktionieren!</p>";
echo "<p>2. <a href='admin/reservations.php'>Prüfe die Reservierungen</a></p>";
echo "<p>3. <a href='debug-google-calendar-live.php'>Prüfe die Logs</a></p>";

echo "<hr>";
echo "<p><small>Zeitstempel: " . date('Y-m-d H:i:s') . "</small></p>";
?>
