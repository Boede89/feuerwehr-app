<?php
/**
 * Fügt Sortier-Reihenfolge für Fahrzeuge hinzu
 */

require_once 'config/database.php';

echo "🔧 Fahrzeug-Sortierung hinzufügen\n";
echo "=================================\n\n";

try {
    // 1. Prüfen ob Spalte bereits existiert
    echo "1. Prüfe Datenbank-Schema...\n";
    $stmt = $db->query("SHOW COLUMNS FROM vehicles LIKE 'sort_order'");
    $column_exists = $stmt->fetch();
    
    if ($column_exists) {
        echo "   ✅ Spalte 'sort_order' existiert bereits\n";
    } else {
        echo "   ➕ Füge Spalte 'sort_order' hinzu...\n";
        $db->exec("ALTER TABLE vehicles ADD COLUMN sort_order INT DEFAULT 0 AFTER is_active");
        echo "   ✅ Spalte 'sort_order' hinzugefügt\n";
    }
    
    // 2. Aktuelle Fahrzeuge mit Sortier-Reihenfolge versehen
    echo "\n2. Setze Sortier-Reihenfolge für bestehende Fahrzeuge...\n";
    $stmt = $db->query("SELECT id, name FROM vehicles ORDER BY name");
    $vehicles = $stmt->fetchAll();
    
    $sort_order = 1;
    foreach ($vehicles as $vehicle) {
        $update_stmt = $db->prepare("UPDATE vehicles SET sort_order = ? WHERE id = ?");
        $update_stmt->execute([$sort_order, $vehicle['id']]);
        echo "   ✅ {$vehicle['name']} -> Sortier-Reihenfolge: $sort_order\n";
        $sort_order++;
    }
    
    // 3. Einstellung für Sortier-Modus hinzufügen
    echo "\n3. Füge Einstellung für Sortier-Modus hinzu...\n";
    $stmt = $db->prepare("SELECT COUNT(*) FROM settings WHERE setting_key = 'vehicle_sort_mode'");
    $stmt->execute();
    $setting_exists = $stmt->fetchColumn();
    
    if (!$setting_exists) {
        $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value, description) VALUES (?, ?, ?)");
        $stmt->execute(['vehicle_sort_mode', 'manual', 'Sortier-Modus für Fahrzeuge: manual, name, created']);
        echo "   ✅ Einstellung 'vehicle_sort_mode' hinzugefügt\n";
    } else {
        echo "   ✅ Einstellung 'vehicle_sort_mode' existiert bereits\n";
    }
    
    echo "\n🎯 Fahrzeug-Sortierung erfolgreich eingerichtet!\n";
    echo "📋 Sortier-Modi:\n";
    echo "   - manual: Manuelle Reihenfolge (sort_order)\n";
    echo "   - name: Alphabetisch nach Name\n";
    echo "   - created: Nach Erstellungsdatum\n";
    
} catch (Exception $e) {
    echo "❌ Fehler: " . $e->getMessage() . "\n";
}

echo "\n🎯 Setup abgeschlossen!\n";
?>
