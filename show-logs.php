<?php
/**
 * Zeigt die aktuellen PHP Error Logs an
 */

echo "<h1>PHP Error Logs</h1>";

// Verschiedene mögliche Log-Dateien prüfen
$log_files = [
    '/var/log/apache2/error.log',
    '/var/log/nginx/error.log',
    '/var/log/php_errors.log',
    '/var/log/php/error.log',
    '/usr/local/var/log/php_errors.log',
    'error.log',
    'php_errors.log',
    ini_get('error_log')
];

echo "<h2>Konfigurierte Log-Datei:</h2>";
echo "<p><strong>error_log Einstellung:</strong> " . (ini_get('error_log') ?: 'Nicht gesetzt') . "</p>";

echo "<h2>Verfügbare Log-Dateien:</h2>";
foreach ($log_files as $log_file) {
    if (file_exists($log_file)) {
        echo "<p>✅ <strong>$log_file</strong> (Größe: " . filesize($log_file) . " Bytes)</p>";
        
        // Letzte 20 Zeilen anzeigen
        $lines = file($log_file);
        $last_lines = array_slice($lines, -20);
        
        echo "<h3>Letzte 20 Zeilen aus $log_file:</h3>";
        echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ddd; max-height: 300px; overflow-y: auto;'>";
        foreach ($last_lines as $line) {
            echo htmlspecialchars($line);
        }
        echo "</pre>";
    } else {
        echo "<p>❌ $log_file (nicht gefunden)</p>";
    }
}

echo "<h2>PHP Konfiguration:</h2>";
echo "<p><strong>log_errors:</strong> " . (ini_get('log_errors') ? 'Aktiviert' : 'Deaktiviert') . "</p>";
echo "<p><strong>display_errors:</strong> " . (ini_get('display_errors') ? 'Aktiviert' : 'Deaktiviert') . "</p>";
echo "<p><strong>error_reporting:</strong> " . ini_get('error_reporting') . "</p>";

echo "<h2>Test-Log schreiben:</h2>";
error_log("TEST-LOG: " . date('Y-m-d H:i:s') . " - Dies ist ein Test-Log-Eintrag");
echo "<p>✅ Test-Log-Eintrag geschrieben. Prüfe die Log-Dateien oben.</p>";

echo "<h2>Docker Container Logs (falls verfügbar):</h2>";
echo "<p>Falls du Docker verwendest, kannst du auch folgende Befehle ausführen:</p>";
echo "<pre>";
echo "docker-compose logs --tail=50 web\n";
echo "docker-compose logs --tail=50 apache\n";
echo "docker-compose logs --tail=50 php\n";
echo "</pre>";
?>
