<?php
/**
 * Einfaches Datenbank-Update ohne komplexe Funktionen
 */

echo "<!DOCTYPE html>";
echo "<html><head><title>Datenbank-Update</title></head><body>";
echo "<h1>🔧 Datenbank-Update</h1>";
echo "<p>Zeitstempel: " . date('d.m.Y H:i:s') . "</p>";

try {
    require_once 'config/database.php';
    echo "✅ Datenbank-Verbindung erfolgreich<br><br>";
    
    // Prüfe ob calendar_conflicts Spalte bereits existiert
    $stmt = $db->query("SHOW COLUMNS FROM reservations LIKE 'calendar_conflicts'");
    $column_exists = $stmt->fetch();
    
    if (!$column_exists) {
        echo "Füge calendar_conflicts Spalte hinzu...<br>";
        
        // Füge calendar_conflicts Spalte hinzu
        $db->exec("ALTER TABLE reservations ADD COLUMN calendar_conflicts TEXT NULL AFTER location");
        echo "✅ calendar_conflicts Spalte erfolgreich hinzugefügt<br>";
        
        // Füge Kommentar hinzu
        $db->exec("ALTER TABLE reservations MODIFY COLUMN calendar_conflicts TEXT NULL COMMENT 'JSON-Array der gefundenen Kalender-Konflikte'");
        echo "✅ Kommentar hinzugefügt<br>";
        
    } else {
        echo "ℹ️ calendar_conflicts Spalte existiert bereits<br>";
    }
    
    // Zeige aktuelle Tabellenstruktur
    echo "<h2>📋 Aktuelle Tabellenstruktur:</h2>";
    $stmt = $db->query("DESCRIBE reservations");
    $columns = $stmt->fetchAll();
    
    echo "<table border='1'>";
    echo "<tr><th>Feld</th><th>Typ</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
    
    foreach ($columns as $column) {
        echo "<tr>";
        echo "<td><strong>{$column['Field']}</strong></td>";
        echo "<td>{$column['Type']}</td>";
        echo "<td>{$column['Null']}</td>";
        echo "<td>{$column['Key']}</td>";
        echo "<td>{$column['Default']}</td>";
        echo "<td>{$column['Extra']}</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    echo "<br><div style='color: green; font-weight: bold;'>🎉 Datenbank-Update abgeschlossen!</div>";
    
} catch (Exception $e) {
    echo "<div style='color: red;'>";
    echo "<h3>❌ Fehler beim Datenbank-Update:</h3>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    echo "</div>";
}

echo "<hr>";
echo "<p><a href='simple-debug.php'>Debug-Report anzeigen</a> | <a href='admin/dashboard.php'>Zum Dashboard</a></p>";
echo "</body></html>";
?>
