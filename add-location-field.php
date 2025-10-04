<?php
/**
 * Füge Ort-Feld zur Reservierungen-Tabelle hinzu
 */

require_once 'config/database.php';

try {
    // Prüfe ob location Spalte bereits existiert
    $stmt = $db->query("SHOW COLUMNS FROM reservations LIKE 'location'");
    $column_exists = $stmt->fetch();
    
    if (!$column_exists) {
        // Füge location Spalte hinzu
        $db->exec("ALTER TABLE reservations ADD COLUMN location VARCHAR(255) NULL AFTER reason");
        echo "✅ Ort-Feld erfolgreich zur Reservierungen-Tabelle hinzugefügt.\n";
        
        // Füge Kommentar hinzu
        $db->exec("ALTER TABLE reservations MODIFY COLUMN location VARCHAR(255) NULL COMMENT 'Ort der Fahrzeugreservierung'");
        echo "✅ Kommentar für Ort-Feld hinzugefügt.\n";
    } else {
        echo "ℹ️ Ort-Feld existiert bereits in der Reservierungen-Tabelle.\n";
    }
    
    // Zeige aktuelle Tabellenstruktur
    echo "\n📋 Aktuelle Reservierungen-Tabellenstruktur:\n";
    $stmt = $db->query("DESCRIBE reservations");
    $columns = $stmt->fetchAll();
    
    foreach ($columns as $column) {
        echo "- {$column['Field']}: {$column['Type']} " . ($column['Null'] == 'YES' ? 'NULL' : 'NOT NULL') . "\n";
    }
    
} catch (PDOException $e) {
    echo "❌ Fehler beim Hinzufügen des Ort-Feldes: " . $e->getMessage() . "\n";
}
?>
