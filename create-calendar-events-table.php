<?php
/**
 * Erstelle calendar_events Tabelle falls sie nicht existiert
 */

require_once 'config/database.php';

echo "<h1>🗄️ Calendar Events Tabelle erstellen</h1>";

try {
    // Prüfe ob Tabelle existiert
    $stmt = $db->prepare("SHOW TABLES LIKE 'calendar_events'");
    $stmt->execute();
    $table_exists = $stmt->fetch();
    
    if ($table_exists) {
        echo "<p style='color: green;'>✅ calendar_events Tabelle existiert bereits</p>";
        
        // Zeige Struktur
        $stmt = $db->prepare("DESCRIBE calendar_events");
        $stmt->execute();
        $columns = $stmt->fetchAll();
        
        echo "<h2>Tabellen-Struktur:</h2>";
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>Feld</th><th>Typ</th><th>Null</th><th>Schlüssel</th><th>Standard</th><th>Extra</th></tr>";
        foreach ($columns as $column) {
            echo "<tr>";
            echo "<td>" . $column['Field'] . "</td>";
            echo "<td>" . $column['Type'] . "</td>";
            echo "<td>" . $column['Null'] . "</td>";
            echo "<td>" . $column['Key'] . "</td>";
            echo "<td>" . $column['Default'] . "</td>";
            echo "<td>" . $column['Extra'] . "</td>";
            echo "</tr>";
        }
        echo "</table>";
        
    } else {
        echo "<p style='color: orange;'>⚠️ calendar_events Tabelle existiert NICHT - erstelle sie jetzt...</p>";
        
        // Erstelle Tabelle
        $sql = "
        CREATE TABLE calendar_events (
            id INT AUTO_INCREMENT PRIMARY KEY,
            reservation_id INT NOT NULL,
            google_event_id VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE CASCADE,
            INDEX idx_reservation_id (reservation_id),
            INDEX idx_google_event_id (google_event_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";
        
        $db->exec($sql);
        echo "<p style='color: green;'>✅ calendar_events Tabelle erfolgreich erstellt</p>";
    }
    
    // Prüfe ob es Einträge gibt
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM calendar_events");
    $stmt->execute();
    $count = $stmt->fetch()['count'];
    
    echo "<p><strong>Anzahl Einträge in calendar_events:</strong> $count</p>";
    
    if ($count > 0) {
        // Zeige alle Einträge
        $stmt = $db->prepare("SELECT * FROM calendar_events ORDER BY id DESC LIMIT 10");
        $stmt->execute();
        $events = $stmt->fetchAll();
        
        echo "<h2>Letzte 10 Einträge:</h2>";
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>ID</th><th>Reservation ID</th><th>Google Event ID</th><th>Erstellt</th></tr>";
        foreach ($events as $event) {
            echo "<tr>";
            echo "<td>" . $event['id'] . "</td>";
            echo "<td>" . $event['reservation_id'] . "</td>";
            echo "<td>" . htmlspecialchars($event['google_event_id']) . "</td>";
            echo "<td>" . $event['created_at'] . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler: " . $e->getMessage() . "</p>";
}

echo "<hr>";
echo "<p><a href='debug-google-calendar-delete.php'>→ Google Calendar Delete Debug</a></p>";
echo "<p><a href='admin/reservations.php'>→ Zur Reservierungen-Übersicht</a></p>";
echo "<p><small>Erstellt: " . date('Y-m-d H:i:s') . "</small></p>";
?>
