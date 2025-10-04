<?php
/**
 * Test: Google Calendar direkt - Mit detailliertem Logging
 */

echo "<h1>Test: Google Calendar direkt - Mit detailliertem Logging</h1>";

// Lade Funktionen
require_once 'config/database.php';
require_once 'includes/functions.php';

echo "<h2>1. Teste error_log() Funktion</h2>";

// Teste error_log
$test_log_result = error_log('TEST ERROR_LOG: Test-Log von test-google-calendar-direct.php');
echo "<p>error_log() Rückgabe: " . ($test_log_result ? 'true' : 'false') . "</p>";

// Teste file_put_contents
$test_file_result = file_put_contents('test.log', 'TEST FILE: Test-Log von test-google-calendar-direct.php' . PHP_EOL, FILE_APPEND);
echo "<p>file_put_contents() Rückgabe: " . ($test_file_result ? 'true' : 'false') . "</p>";

echo "<h2>2. Teste Google Calendar mit detailliertem Logging</h2>";

// Schreibe Test-Log vor dem Aufruf
error_log('TEST DIRECT: Starte Google Calendar Test');
echo "<p>🔍 Schreibe Test-Log vor dem Aufruf...</p>";

// Teste Google Calendar
if (function_exists('create_google_calendar_event')) {
    echo "<p>✅ create_google_calendar_event Funktion verfügbar</p>";
    
    echo "<p>🔍 Teste mit Test-Daten...</p>";
    
    // Schreibe Test-Log vor dem Aufruf
    error_log('TEST DIRECT: Rufe create_google_calendar_event auf');
    echo "<p>🔍 Schreibe Test-Log vor dem Aufruf...</p>";
    
    $test_result = create_google_calendar_event(
        'Test Fahrzeug',
        'Test Grund',
        '2025-10-05 10:00:00',
        '2025-10-05 12:00:00',
        999999,
        'Test Ort'
    );
    
    // Schreibe Test-Log nach dem Aufruf
    error_log('TEST DIRECT: create_google_calendar_event Rückgabe: ' . ($test_result ? $test_result : 'false'));
    echo "<p>🔍 Schreibe Test-Log nach dem Aufruf...</p>";
    
    if ($test_result) {
        echo "<p style='color: green;'>✅ Test erfolgreich - Event ID: $test_result</p>";
    } else {
        echo "<p style='color: red;'>❌ Test fehlgeschlagen - Funktion gab false zurück</p>";
    }
} else {
    echo "<p style='color: red;'>❌ create_google_calendar_event Funktion nicht verfügbar</p>";
}

echo "<h2>3. Prüfe error_log Einstellungen</h2>";

echo "<p>error_log: " . ini_get('error_log') . "</p>";
echo "<p>log_errors: " . (ini_get('log_errors') ? 'ON' : 'OFF') . "</p>";
echo "<p>display_errors: " . (ini_get('display_errors') ? 'ON' : 'OFF') . "</p>";
echo "<p>error_reporting: " . ini_get('error_reporting') . "</p>";

echo "<h2>4. Prüfe Log-Dateien</h2>";

$log_paths = [
    ini_get('error_log'),
    '/var/log/php_errors.log',
    '/var/log/apache2/error.log',
    '/var/log/nginx/error.log',
    '/tmp/php_errors.log',
    'error.log',
    'test.log'
];

foreach ($log_paths as $log_path) {
    if (file_exists($log_path)) {
        echo "<p>✅ Log-Datei gefunden: $log_path</p>";
        
        // Prüfe ob unsere Test-Logs enthalten sind
        $log_content = file_get_contents($log_path);
        if (strpos($log_content, 'TEST DIRECT') !== false) {
            echo "<p style='color: green;'>✅ TEST DIRECT Logs gefunden in $log_path</p>";
        } else {
            echo "<p style='color: orange;'>⚠️ TEST DIRECT Logs NICHT gefunden in $log_path</p>";
        }
        
        if (strpos($log_content, 'TEST ERROR_LOG') !== false) {
            echo "<p style='color: green;'>✅ TEST ERROR_LOG gefunden in $log_path</p>";
        } else {
            echo "<p style='color: orange;'>⚠️ TEST ERROR_LOG NICHT gefunden in $log_path</p>";
        }
    } else {
        echo "<p>❌ Log-Datei nicht gefunden: $log_path</p>";
    }
}

echo "<h2>5. Nächste Schritte</h2>";
echo "<p>1. <a href='debug-google-calendar-live.php'>Prüfe die Logs erneut</a></p>";
echo "<p>2. <a href='admin/dashboard.php'>Teste das Dashboard</a></p>";

echo "<hr>";
echo "<p><small>Zeitstempel: " . date('Y-m-d H:i:s') . "</small></p>";
?>
