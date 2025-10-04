<?php
/**
 * Debug: Prüfe Datenbank-Schema für calendar_conflicts Feld
 */

require_once 'config/database.php';

try {
    echo "🔍 Prüfe Datenbank-Schema für Reservierungen-Tabelle...\n\n";
    
    // Zeige aktuelle Tabellenstruktur
    echo "📋 Aktuelle Reservierungen-Tabellenstruktur:\n";
    $stmt = $db->query("DESCRIBE reservations");
    $columns = $stmt->fetchAll();
    
    $has_calendar_conflicts = false;
    foreach ($columns as $column) {
        echo "- {$column['Field']}: {$column['Type']} " . ($column['Null'] == 'YES' ? 'NULL' : 'NOT NULL') . "\n";
        if ($column['Field'] === 'calendar_conflicts') {
            $has_calendar_conflicts = true;
        }
    }
    
    echo "\n";
    
    if ($has_calendar_conflicts) {
        echo "✅ calendar_conflicts Feld existiert in der Datenbank.\n";
        
        // Prüfe Beispiel-Daten
        echo "\n📊 Prüfe Beispiel-Daten:\n";
        $stmt = $db->query("SELECT id, vehicle_id, reason, calendar_conflicts FROM reservations ORDER BY created_at DESC LIMIT 5");
        $reservations = $stmt->fetchAll();
        
        foreach ($reservations as $reservation) {
            echo "ID: {$reservation['id']}, Grund: {$reservation['reason']}, Konflikte: " . ($reservation['calendar_conflicts'] ?: 'Keine') . "\n";
        }
    } else {
        echo "❌ calendar_conflicts Feld fehlt in der Datenbank!\n";
        echo "Führe add-calendar-conflict-field.php aus.\n";
    }
    
} catch (PDOException $e) {
    echo "❌ Fehler beim Prüfen der Datenbank: " . $e->getMessage() . "\n";
}
?>
