<?php
/**
 * Prüfe Error Logs für Google Calendar Fehler
 */

echo "<h1>Error Logs prüfen</h1>";
echo "<p>Zeitstempel: " . date('d.m.Y H:i:s') . "</p>";

// 1. Prüfe verschiedene Log-Dateien
$log_files = [
    '/var/log/apache2/error.log',
    '/var/log/nginx/error.log',
    '/var/log/php_errors.log',
    '/tmp/php_errors.log',
    ini_get('error_log')
];

echo "<h2>1. Log-Dateien prüfen</h2>";

foreach ($log_files as $log_file) {
    if ($log_file && file_exists($log_file)) {
        echo "<p><strong>Log-Datei:</strong> " . htmlspecialchars($log_file) . "</p>";
        
        $log_content = file_get_contents($log_file);
        $google_calendar_errors = [];
        
        $lines = explode("\n", $log_content);
        foreach ($lines as $line) {
            if (strpos($line, 'Google Calendar') !== false || strpos($line, 'create_google_calendar_event') !== false) {
                $google_calendar_errors[] = $line;
            }
        }
        
        if (empty($google_calendar_errors)) {
            echo "<p style='color: green;'>✅ Keine Google Calendar Fehler in " . htmlspecialchars($log_file) . "</p>";
        } else {
            echo "<p style='color: orange;'>⚠️ " . count($google_calendar_errors) . " Google Calendar Fehler in " . htmlspecialchars($log_file) . ":</p>";
            echo "<div style='background-color: #f8f9fa; padding: 10px; border-radius: 4px; max-height: 200px; overflow-y: auto;'>";
            foreach (array_slice($google_calendar_errors, -10) as $error) {
                echo "<p style='margin: 2px 0; font-family: monospace; font-size: 12px;'>" . htmlspecialchars($error) . "</p>";
            }
            echo "</div>";
        }
    } else {
        echo "<p style='color: gray;'>⚠️ Log-Datei nicht gefunden: " . htmlspecialchars($log_file) . "</p>";
    }
}

// 2. Prüfe PHP Error Reporting
echo "<h2>2. PHP Error Reporting</h2>";
echo "<p><strong>Error Reporting:</strong> " . error_reporting() . "</p>";
echo "<p><strong>Display Errors:</strong> " . (ini_get('display_errors') ? 'An' : 'Aus') . "</p>";
echo "<p><strong>Log Errors:</strong> " . (ini_get('log_errors') ? 'An' : 'Aus') . "</p>";
echo "<p><strong>Error Log:</strong> " . htmlspecialchars(ini_get('error_log')) . "</p>";

// 3. Teste Error Logging
echo "<h2>3. Error Logging testen</h2>";

error_log("Google Calendar Debug Test - " . date('Y-m-d H:i:s'));

echo "<p>✅ Test-Log-Eintrag erstellt</p>";

// 4. Prüfe ob Google Calendar Funktionen verfügbar sind
echo "<h2>4. Google Calendar Funktionen prüfen</h2>";

if (function_exists('create_google_calendar_event')) {
    echo "<p style='color: green;'>✅ create_google_calendar_event Funktion ist verfügbar</p>";
} else {
    echo "<p style='color: red;'>❌ create_google_calendar_event Funktion ist NICHT verfügbar</p>";
}

if (class_exists('GoogleCalendarServiceAccount')) {
    echo "<p style='color: green;'>✅ GoogleCalendarServiceAccount Klasse ist verfügbar</p>";
} else {
    echo "<p style='color: red;'>❌ GoogleCalendarServiceAccount Klasse ist NICHT verfügbar</p>";
}

// 5. Prüfe includes/functions.php
echo "<h2>5. includes/functions.php prüfen</h2>";

if (file_exists('includes/functions.php')) {
    echo "<p style='color: green;'>✅ includes/functions.php existiert</p>";
    
    $functions_content = file_get_contents('includes/functions.php');
    if (strpos($functions_content, 'function create_google_calendar_event') !== false) {
        echo "<p style='color: green;'>✅ create_google_calendar_event Funktion in includes/functions.php gefunden</p>";
    } else {
        echo "<p style='color: red;'>❌ create_google_calendar_event Funktion NICHT in includes/functions.php gefunden</p>";
    }
} else {
    echo "<p style='color: red;'>❌ includes/functions.php existiert NICHT</p>";
}

echo "<hr>";
echo "<p><strong>Prüfung abgeschlossen um:</strong> " . date('d.m.Y H:i:s') . "</p>";
?>
