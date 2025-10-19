<?php
/**
 * Debug-Datei für Datenbankverbindungsprobleme
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>🔍 Datenbankverbindung Debug</h1>";

// 1. PHP PDO MySQL Extension prüfen
echo "<h2>1. PHP Extensions:</h2>";
echo "<p><strong>PDO verfügbar:</strong> " . (extension_loaded('pdo') ? '✅ Ja' : '❌ Nein') . "</p>";
echo "<p><strong>PDO MySQL verfügbar:</strong> " . (extension_loaded('pdo_mysql') ? '✅ Ja' : '❌ Nein') . "</p>";
echo "<p><strong>MySQLi verfügbar:</strong> " . (extension_loaded('mysqli') ? '✅ Ja' : '❌ Nein') . "</p>";

// 2. Verfügbare PDO Treiber
echo "<h2>2. Verfügbare PDO Treiber:</h2>";
$drivers = PDO::getAvailableDrivers();
echo "<p>" . implode(', ', $drivers) . "</p>";

// 3. Verschiedene Hosts testen
echo "<h2>3. Verbindungsversuche:</h2>";

$hosts = [
    'mysql',           // Docker Container Name
    'localhost',       // Lokaler Host
    '127.0.0.1',      // IP
    '192.168.10.150', // LXC IP
    'feuerwehr_mysql' // Alternative Container Name
];

$dbname = 'feuerwehr_app';
$username = 'feuerwehr_user';
$password = 'feuerwehr_password';

foreach ($hosts as $host) {
    echo "<h3>Host: $host</h3>";
    
    try {
        $dsn = "mysql:host=$host;dbname=$dbname;charset=utf8";
        echo "<p><strong>DSN:</strong> $dsn</p>";
        
        $pdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 5
        ]);
        
        echo "<p style='color: green;'>✅ <strong>Verbindung erfolgreich!</strong></p>";
        
        // Test Query
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM users");
        $result = $stmt->fetch();
        echo "<p><strong>Anzahl Benutzer:</strong> " . $result['count'] . "</p>";
        
        // Tabellen auflisten
        $stmt = $pdo->query("SHOW TABLES");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        echo "<p><strong>Verfügbare Tabellen:</strong> " . implode(', ', $tables) . "</p>";
        
        break; // Erfolgreiche Verbindung gefunden
        
    } catch (PDOException $e) {
        echo "<p style='color: red;'>❌ <strong>Fehler:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
    }
}

// 4. System-Informationen
echo "<h2>4. System-Informationen:</h2>";
echo "<p><strong>PHP Version:</strong> " . phpversion() . "</p>";
echo "<p><strong>Server:</strong> " . ($_SERVER['SERVER_SOFTWARE'] ?? 'Unbekannt') . "</p>";
echo "<p><strong>OS:</strong> " . php_uname() . "</p>";

// 5. Netzwerk-Test
echo "<h2>5. Netzwerk-Test:</h2>";
$test_hosts = ['mysql', 'localhost', '127.0.0.1'];
foreach ($test_hosts as $host) {
    $connection = @fsockopen($host, 3306, $errno, $errstr, 5);
    if ($connection) {
        echo "<p style='color: green;'>✅ $host:3306 ist erreichbar</p>";
        fclose($connection);
    } else {
        echo "<p style='color: red;'>❌ $host:3306 nicht erreichbar ($errno: $errstr)</p>";
    }
}

// 6. Aktuelle Konfiguration
echo "<h2>6. Aktuelle Datenbank-Konfiguration:</h2>";
if (file_exists('config/database.php')) {
    echo "<p>✅ config/database.php gefunden</p>";
    $content = file_get_contents('config/database.php');
    echo "<pre>" . htmlspecialchars($content) . "</pre>";
} else {
    echo "<p>❌ config/database.php nicht gefunden</p>";
}

echo "<h2>7. Empfohlene Lösung:</h2>";
echo "<p>Falls alle Verbindungen fehlschlagen, prüfen Sie:</p>";
echo "<ul>";
echo "<li>Ist der MySQL-Container gestartet? (docker ps)</li>";
echo "<li>Ist der MySQL-Service aktiv? (systemctl status mysql)</li>";
echo "<li>Ist Port 3306 geöffnet? (netstat -tlnp | grep 3306)</li>";
echo "<li>Stimmen die Zugangsdaten? (Benutzer: feuerwehr_user, Passwort: feuerwehr_password)</li>";
echo "<li>Ist die Datenbank 'feuerwehr_app' erstellt?</li>";
echo "</ul>";
?>
