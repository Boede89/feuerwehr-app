<?php
/**
 * Debug-Datei für Datenbankverbindung
 */

echo "<h1>Datenbankverbindung Debug</h1>";

// Prüfe Docker-Compose-Konfiguration
echo "<h2>1. Docker-Compose-Konfiguration prüfen</h2>";
echo "<pre>";
$compose_file = '../docker-compose.yml';
if (file_exists($compose_file)) {
    $content = file_get_contents($compose_file);
    echo htmlspecialchars($content);
} else {
    echo "docker-compose.yml nicht gefunden";
}
echo "</pre>";

// Prüfe MySQL-Container
echo "<h2>2. MySQL-Container Status</h2>";
echo "<pre>";
$output = shell_exec('docker ps | grep mysql');
echo htmlspecialchars($output ?: 'Kein MySQL-Container gefunden');
echo "</pre>";

// Prüfe MySQL-Logs
echo "<h2>3. MySQL-Container Logs</h2>";
echo "<pre>";
$logs = shell_exec('docker logs feuerwehr_mysql --tail 20');
echo htmlspecialchars($logs ?: 'Keine Logs verfügbar');
echo "</pre>";

// Versuche verschiedene Verbindungen
echo "<h2>4. Verbindungsversuche</h2>";

$host = 'feuerwehr_mysql';
$dbname = 'feuerwehr_app';
$username = 'root';
$passwords = ['', 'root', 'feuerwehr123', 'password', 'admin', 'mysql', '123456', 'secret'];

foreach ($passwords as $password) {
    echo "<h3>Passwort: '" . ($password ?: 'LEER') . "'</h3>";
    
    try {
        $db = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        echo "<span style='color: green;'>✓ ERFOLGREICH verbunden!</span><br>";
        
        // Prüfe verfügbare Datenbanken
        $stmt = $db->query("SHOW DATABASES");
        $databases = $stmt->fetchAll(PDO::FETCH_COLUMN);
        echo "Verfügbare Datenbanken: " . implode(', ', $databases) . "<br>";
        
        // Prüfe verfügbare Tabellen
        $stmt = $db->query("SHOW TABLES");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        echo "Verfügbare Tabellen: " . implode(', ', $tables) . "<br>";
        
        break;
    } catch (PDOException $e) {
        echo "<span style='color: red;'>✗ Fehler: " . htmlspecialchars($e->getMessage()) . "</span><br>";
    }
}

echo "<h2>5. Umgebungsvariablen</h2>";
echo "<pre>";
$env_vars = [
    'MYSQL_ROOT_PASSWORD',
    'MYSQL_DATABASE',
    'MYSQL_USER',
    'MYSQL_PASSWORD'
];

foreach ($env_vars as $var) {
    $value = getenv($var);
    echo "$var: " . ($value ?: 'NICHT GESETZT') . "\n";
}
echo "</pre>";
?>
