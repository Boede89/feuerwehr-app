<?php
/**
 * Datenbank-Update: Füge location Spalte hinzu
 * Führe dieses Script über den Browser aus: http://deine-domain/database-update.php
 */

require_once 'config/database.php';

echo "<h1>Datenbank-Update: Ort-Feld hinzufügen</h1>";

try {
    echo "<p>🔧 Füge location Spalte zur Reservierungen-Tabelle hinzu...</p>";
    
    // Prüfe ob location Spalte bereits existiert
    $stmt = $db->query("SHOW COLUMNS FROM reservations LIKE 'location'");
    $column_exists = $stmt->fetch();
    
    if (!$column_exists) {
        // Füge location Spalte hinzu
        $db->exec("ALTER TABLE reservations ADD COLUMN location VARCHAR(255) NULL AFTER reason");
        echo "<p style='color: green;'>✅ Ort-Feld erfolgreich zur Reservierungen-Tabelle hinzugefügt.</p>";
        
        // Füge Kommentar hinzu
        $db->exec("ALTER TABLE reservations MODIFY COLUMN location VARCHAR(255) NULL COMMENT 'Ort der Fahrzeugreservierung'");
        echo "<p style='color: green;'>✅ Kommentar für Ort-Feld hinzugefügt.</p>";
    } else {
        echo "<p style='color: blue;'>ℹ️ Ort-Feld existiert bereits in der Reservierungen-Tabelle.</p>";
    }
    
    // Zeige aktuelle Tabellenstruktur
    echo "<h2>📋 Aktuelle Reservierungen-Tabellenstruktur:</h2>";
    echo "<table border='1' style='border-collapse: collapse;'>";
    echo "<tr><th>Feld</th><th>Typ</th><th>Null</th><th>Schlüssel</th><th>Standard</th><th>Extra</th></tr>";
    
    $stmt = $db->query("DESCRIBE reservations");
    $columns = $stmt->fetchAll();
    
    foreach ($columns as $column) {
        echo "<tr>";
        echo "<td>{$column['Field']}</td>";
        echo "<td>{$column['Type']}</td>";
        echo "<td>{$column['Null']}</td>";
        echo "<td>{$column['Key']}</td>";
        echo "<td>{$column['Default']}</td>";
        echo "<td>{$column['Extra']}</td>";
        echo "</tr>";
    }
    
    echo "</table>";
    
    echo "<p style='color: green; font-weight: bold;'>🎉 Datenbank-Update abgeschlossen!</p>";
    echo "<p>Du kannst jetzt Reservierungen mit Ort-Feld erstellen.</p>";
    
} catch (PDOException $e) {
    echo "<p style='color: red;'>❌ Fehler beim Hinzufügen des Ort-Feldes: " . $e->getMessage() . "</p>";
    echo "<pre>Stack Trace: " . $e->getTraceAsString() . "</pre>";
}
?>
