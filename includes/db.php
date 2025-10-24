<?php
/**
 * Datenbankverbindung für Feuerwehr App
 * Fallback-Version mit mehreren Host-Versuchen
 */

// Versuche zuerst die Standard-Konfiguration
if (file_exists(__DIR__ . '/../config/database.php')) {
    require_once __DIR__ . '/../config/database.php';
} else {
    // Fallback: Erstelle direkte Verbindung
    $hosts = [
        'mysql',           // Docker Container Name
        'localhost',       // Lokaler Host
        '127.0.0.1',      // IP
        '192.168.10.150', // LXC IP
        'feuerwehr_mysql' // Alternative Container Name
    ];
    
    $db_name = 'feuerwehr_app';
    $username = 'feuerwehr_user';
    $password = 'feuerwehr_password';
    $db = null;
    
    foreach ($hosts as $host) {
        try {
            $db = new PDO(
                "mysql:host=" . $host . ";dbname=" . $db_name . ";charset=utf8",
                $username,
                $password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_TIMEOUT => 5
                ]
            );
            break; // Erfolgreiche Verbindung gefunden
        } catch(PDOException $exception) {
            continue; // Nächsten Host versuchen
        }
    }
    
    if ($db === null) {
        die("
        <div style='background: #f8d7da; color: #721c24; padding: 20px; margin: 20px; border: 1px solid #f5c6cb; border-radius: 5px;'>
            <h3>⚠️ Datenbankverbindung fehlgeschlagen</h3>
            <p>Die Anwendung kann keine Verbindung zur Datenbank herstellen.</p>
            <p><strong>Fehler:</strong> Alle Hosts versucht, keine Verbindung möglich</p>
            <p><a href='debug-database-connection.php'>🔍 Debug-Informationen anzeigen</a></p>
        </div>
        ");
    }
}
?>
