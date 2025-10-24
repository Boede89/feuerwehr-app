<?php
/**
 * Fallback-Datenbankverbindung für Feuerwehr App
 * Versucht verschiedene Hosts und Konfigurationen
 */

class DatabaseFallback {
    private $hosts = [
        'mysql',           // Docker Container Name
        'localhost',       // Lokaler Host
        '127.0.0.1',      // IP
        '192.168.10.150', // LXC IP
        'feuerwehr_mysql' // Alternative Container Name
    ];
    private $db_name = 'feuerwehr_app';
    private $username = 'feuerwehr_user';
    private $password = 'feuerwehr_password';
    private $conn;

    public function getConnection() {
        $this->conn = null;

        foreach ($this->hosts as $host) {
            try {
                echo "<!-- Versuche Verbindung zu: $host -->\n";
                
                $this->conn = new PDO(
                    "mysql:host=" . $host . ";dbname=" . $this->db_name . ";charset=utf8",
                    $this->username,
                    $this->password,
                    [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_TIMEOUT => 5
                    ]
                );
                
                echo "<!-- Erfolgreiche Verbindung zu: $host -->\n";
                break; // Erfolgreiche Verbindung gefunden
                
            } catch(PDOException $exception) {
                echo "<!-- Fehler bei $host: " . $exception->getMessage() . " -->\n";
                $this->conn = null;
                continue; // Nächsten Host versuchen
            }
        }

        if ($this->conn === null) {
            echo "<!-- Alle Verbindungsversuche fehlgeschlagen -->\n";
            // Logging für Debugging
            error_log("Datenbankverbindung fehlgeschlagen: Alle Hosts versucht");
        }

        return $this->conn;
    }
}

// Globale Datenbankverbindung mit Fallback
$database = new DatabaseFallback();
$db = $database->getConnection();

// Falls keine Verbindung möglich ist, zeige Fehlermeldung
if ($db === null) {
    die("
    <div style='background: #f8d7da; color: #721c24; padding: 20px; margin: 20px; border: 1px solid #f5c6cb; border-radius: 5px;'>
        <h3>⚠️ Datenbankverbindung fehlgeschlagen</h3>
        <p>Die Anwendung kann keine Verbindung zur Datenbank herstellen.</p>
        <p><strong>Mögliche Lösungen:</strong></p>
        <ul>
            <li>Prüfen Sie, ob der MySQL-Service läuft</li>
            <li>Starten Sie den MySQL-Container neu</li>
            <li>Überprüfen Sie die Netzwerkverbindung</li>
            <li>Kontaktieren Sie den Administrator</li>
        </ul>
        <p><a href='debug-database-connection.php'>🔍 Debug-Informationen anzeigen</a></p>
    </div>
    ");
}
?>
