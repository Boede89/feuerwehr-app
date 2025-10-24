<?php
/**
 * Füge Kalender-Konflikt-Feld zur Reservierungen-Tabelle hinzu
 */

require_once 'config/database.php';

try {
    echo "🔧 Füge Kalender-Konflikt-Feld zur Reservierungen-Tabelle hinzu...\n";
    
    // Prüfe ob calendar_conflicts Spalte bereits existiert
    $stmt = $db->query("SHOW COLUMNS FROM reservations LIKE 'calendar_conflicts'");
    $column_exists = $stmt->fetch();
    
    if (!$column_exists) {
        // Füge calendar_conflicts Spalte hinzu
        $db->exec("ALTER TABLE reservations ADD COLUMN calendar_conflicts TEXT NULL AFTER location");
        echo "✅ Kalender-Konflikt-Feld erfolgreich zur Reservierungen-Tabelle hinzugefügt.\n";
        
        // Füge Kommentar hinzu
        $db->exec("ALTER TABLE reservations MODIFY COLUMN calendar_conflicts TEXT NULL COMMENT 'JSON-Array der gefundenen Kalender-Konflikte'");
        echo "✅ Kommentar für Kalender-Konflikt-Feld hinzugefügt.\n";
    } else {
        echo "ℹ️ Kalender-Konflikt-Feld existiert bereits in der Reservierungen-Tabelle.\n";
    }
    
    // Zeige aktuelle Tabellenstruktur
    echo "\n📋 Aktuelle Reservierungen-Tabellenstruktur:\n";
    $stmt = $db->query("DESCRIBE reservations");
    $columns = $stmt->fetchAll();
    
    foreach ($columns as $column) {
        echo "- {$column['Field']}: {$column['Type']} " . ($column['Null'] == 'YES' ? 'NULL' : 'NOT NULL') . "\n";
    }
    
    echo "\n🎉 Datenbank-Update abgeschlossen!\n";
    
} catch (PDOException $e) {
    echo "❌ Fehler beim Hinzufügen des Kalender-Konflikt-Feldes: " . $e->getMessage() . "\n";
    echo "Stack Trace: " . $e->getTraceAsString() . "\n";
}
?>
