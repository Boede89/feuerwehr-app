<?php
/**
 * Test: Logging Detailed - Prüfe alle Logging-Methoden
 */

echo "<h1>Test: Logging Detailed - Prüfe alle Logging-Methoden</h1>";

echo "<h2>1. Teste verschiedene Logging-Methoden</h2>";

// Test 1: error_log() mit verschiedenen Parametern
echo "<h3>1.1 error_log() Tests</h3>";

$test_message = 'TEST LOGGING DETAILED: ' . date('Y-m-d H:i:s') . ' - error_log() Test';
$result1 = error_log($test_message);
echo "<p>error_log() mit Standard-Parameter: " . ($result1 ? 'true' : 'false') . "</p>";

$result2 = error_log($test_message, 0);
echo "<p>error_log() mit message_type=0: " . ($result2 ? 'true' : 'false') . "</p>";

$result3 = error_log($test_message, 3, '/tmp/test_error.log');
echo "<p>error_log() mit custom file: " . ($result3 ? 'true' : 'false') . "</p>";

$result4 = error_log($test_message, 1, 'test_email@example.com');
echo "<p>error_log() mit email: " . ($result4 ? 'true' : 'false') . "</p>";

// Test 2: file_put_contents()
echo "<h3>1.2 file_put_contents() Tests</h3>";

$test_file_content = 'TEST LOGGING DETAILED: ' . date('Y-m-d H:i:s') . ' - file_put_contents() Test' . PHP_EOL;

$result5 = file_put_contents('test_detailed.log', $test_file_content);
echo "<p>file_put_contents() in test_detailed.log: " . ($result5 ? 'true' : 'false') . "</p>";

$result6 = file_put_contents('/tmp/test_detailed.log', $test_file_content);
echo "<p>file_put_contents() in /tmp/test_detailed.log: " . ($result6 ? 'true' : 'false') . "</p>";

$result7 = file_put_contents('/var/log/test_detailed.log', $test_file_content);
echo "<p>file_put_contents() in /var/log/test_detailed.log: " . ($result7 ? 'true' : 'false') . "</p>";

// Test 3: syslog()
echo "<h3>1.3 syslog() Tests</h3>";

$result8 = syslog(LOG_INFO, $test_message);
echo "<p>syslog() mit LOG_INFO: " . ($result8 ? 'true' : 'false') . "</p>";

// Test 4: echo mit flush
echo "<h3>1.4 echo mit flush Tests</h3>";

echo "<p>TEST LOGGING DETAILED: " . date('Y-m-d H:i:s') . " - echo Test</p>";
flush();

echo "<h2>2. Prüfe alle möglichen Log-Dateien</h2>";

$log_paths = [
    ini_get('error_log'),
    '/var/log/php_errors.log',
    '/var/log/apache2/error.log',
    '/var/log/nginx/error.log',
    '/tmp/php_errors.log',
    '/tmp/test_detailed.log',
    'test_detailed.log',
    'error.log',
    'test.log',
    '/var/log/syslog',
    '/var/log/messages',
    '/var/log/daemon.log'
];

foreach ($log_paths as $log_path) {
    if (file_exists($log_path)) {
        echo "<p>✅ Log-Datei gefunden: $log_path</p>";
        
        // Prüfe ob unsere Test-Logs enthalten sind
        $log_content = file_get_contents($log_path);
        $test_found = false;
        
        if (strpos($log_content, 'TEST LOGGING DETAILED') !== false) {
            echo "<p style='color: green;'>✅ TEST LOGGING DETAILED Logs gefunden in $log_path</p>";
            $test_found = true;
        }
        
        if (strpos($log_content, 'TEST DIRECT') !== false) {
            echo "<p style='color: green;'>✅ TEST DIRECT Logs gefunden in $log_path</p>";
            $test_found = true;
        }
        
        if (strpos($log_content, 'TEST ERROR_LOG') !== false) {
            echo "<p style='color: green;'>✅ TEST ERROR_LOG gefunden in $log_path</p>";
            $test_found = true;
        }
        
        if (!$test_found) {
            echo "<p style='color: orange;'>⚠️ Keine Test-Logs gefunden in $log_path</p>";
        }
        
        // Zeige letzte 5 Zeilen
        $lines = explode("\n", $log_content);
        $recent_lines = array_slice($lines, -5);
        echo "<p><small>Letzte 5 Zeilen:</small></p>";
        echo "<pre style='background: #f5f5f5; padding: 5px; font-size: 12px;'>";
        foreach ($recent_lines as $line) {
            if (!empty(trim($line))) {
                echo htmlspecialchars($line) . "\n";
            }
        }
        echo "</pre>";
        
    } else {
        echo "<p>❌ Log-Datei nicht gefunden: $log_path</p>";
    }
}

echo "<h2>3. Prüfe Berechtigungen</h2>";

$test_dirs = [
    '.',
    '/tmp',
    '/var/log',
    '/var/log/apache2'
];

foreach ($test_dirs as $dir) {
    if (is_dir($dir)) {
        $writable = is_writable($dir);
        echo "<p>" . ($writable ? "✅" : "❌") . " Verzeichnis $dir ist " . ($writable ? "beschreibbar" : "NICHT beschreibbar") . "</p>";
    } else {
        echo "<p>❌ Verzeichnis $dir existiert nicht</p>";
    }
}

echo "<h2>4. Prüfe PHP-Konfiguration</h2>";

echo "<p>error_log: " . ini_get('error_log') . "</p>";
echo "<p>log_errors: " . (ini_get('log_errors') ? 'ON' : 'OFF') . "</p>";
echo "<p>display_errors: " . (ini_get('display_errors') ? 'ON' : 'OFF') . "</p>";
echo "<p>error_reporting: " . ini_get('error_reporting') . "</p>";
echo "<p>log_errors_max_len: " . ini_get('log_errors_max_len') . "</p>";
echo "<p>ignore_repeated_errors: " . (ini_get('ignore_repeated_errors') ? 'ON' : 'OFF') . "</p>";
echo "<p>ignore_repeated_source: " . (ini_get('ignore_repeated_source') ? 'ON' : 'OFF') . "</p>";

echo "<h2>5. Nächste Schritte</h2>";
echo "<p>1. <a href='test-google-calendar-direct.php'>Teste Google Calendar direkt</a></p>";
echo "<p>2. <a href='debug-google-calendar-live.php'>Prüfe die Logs</a></p>";
echo "<p>3. <a href='admin/dashboard.php'>Teste das Dashboard</a></p>";

echo "<hr>";
echo "<p><small>Zeitstempel: " . date('Y-m-d H:i:s') . "</small></p>";
?>
