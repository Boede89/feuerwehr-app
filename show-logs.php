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
    '/var/log/syslog',
    '/var/log/messages',
    '/var/log/daemon.log',
    '/var/log/apache2/access.log',
    '/var/log/apache2/error.log.1',
    '/var/log/apache2/error.log.2',
    'error.log',
    'php_errors.log',
    'logs/error.log',
    'logs/php_errors.log',
    ini_get('error_log')
];

// Zusätzlich: Alle .log Dateien im aktuellen Verzeichnis suchen
$current_dir_logs = glob('*.log');
$logs_dir_logs = glob('logs/*.log');
$all_logs = array_merge($log_files, $current_dir_logs, $logs_dir_logs);

echo "<h2>Konfigurierte Log-Datei:</h2>";
echo "<p><strong>error_log Einstellung:</strong> " . (ini_get('error_log') ?: 'Nicht gesetzt') . "</p>";

echo "<h2>Verfügbare Log-Dateien:</h2>";
foreach ($all_logs as $log_file) {
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

echo "<h2>Live-Log-Überwachung:</h2>";
echo "<p>Klicke auf den Button unten, um Live-Logs zu sehen (falls verfügbar):</p>";
echo "<button onclick='checkLogs()'>Logs aktualisieren</button>";
echo "<div id='live-logs' style='background: #f5f5f5; padding: 10px; border: 1px solid #ddd; margin-top: 10px; max-height: 300px; overflow-y: auto;'></div>";

echo "<script>
function checkLogs() {
    fetch('show-logs.php?ajax=1')
        .then(response => response.text())
        .then(data => {
            document.getElementById('live-logs').innerHTML = data;
        })
        .catch(error => {
            document.getElementById('live-logs').innerHTML = 'Fehler beim Laden der Logs: ' + error;
        });
}

// Auto-refresh alle 5 Sekunden
setInterval(checkLogs, 5000);
</script>";

// AJAX-Handler für Live-Updates
if (isset($_GET['ajax'])) {
    $recent_logs = [];
    foreach ($all_logs as $log_file) {
        if (file_exists($log_file) && filesize($log_file) > 0) {
            $lines = file($log_file);
            $last_lines = array_slice($lines, -5);
            foreach ($last_lines as $line) {
                if (strpos($line, 'GC DELETE') !== false || strpos($line, 'TEST-LOG') !== false || strpos($line, 'ERROR') !== false) {
                    $recent_logs[] = htmlspecialchars($line);
                }
            }
        }
    }
    
    if (empty($recent_logs)) {
        echo "<p>Keine relevanten Log-Einträge gefunden. Versuche einen Google Calendar Eintrag zu löschen.</p>";
    } else {
        echo "<h3>Relevante Log-Einträge:</h3>";
        foreach ($recent_logs as $log) {
            echo "<div style='margin: 5px 0; padding: 5px; background: #fff; border-left: 3px solid #007cba;'>" . $log . "</div>";
        }
    }
    exit;
}

echo "<h2>Docker Container Logs (falls verfügbar):</h2>";
echo "<p>Falls du Docker verwendest, kannst du auch folgende Befehle ausführen:</p>";
echo "<pre>";
echo "docker-compose logs --tail=50 web\n";
echo "docker-compose logs --tail=50 apache\n";
echo "docker-compose logs --tail=50 php\n";
echo "</pre>";
?>
