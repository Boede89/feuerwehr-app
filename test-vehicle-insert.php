<?php
/**
 * Test-Skript für Fahrzeug-INSERT Problem
 */

require_once 'config/database.php';

echo "🧪 Fahrzeug-INSERT Test\n";
echo "======================\n\n";

try {
    // Test 1: Direktes INSERT
    echo "1. Direktes INSERT testen:\n";
    $stmt = $db->prepare("INSERT INTO vehicles (name, description, is_active) VALUES (?, ?, ?)");
    $result = $stmt->execute(['Test Fahrzeug ' . date('H:i:s'), 'Test Beschreibung', 1]);
    $insert_id = $db->lastInsertId();
    echo "   ✅ INSERT erfolgreich - ID: $insert_id\n";
    
    // Test 2: Sofortiges SELECT
    echo "\n2. Sofortiges SELECT testen:\n";
    $stmt = $db->prepare("SELECT * FROM vehicles WHERE id = ?");
    $stmt->execute([$insert_id]);
    $vehicle = $stmt->fetch();
    if ($vehicle) {
        echo "   ✅ Fahrzeug gefunden - Name: {$vehicle['name']}\n";
    } else {
        echo "   ❌ Fahrzeug nicht gefunden!\n";
    }
    
    // Test 3: Alle Fahrzeuge anzeigen
    echo "\n3. Alle Fahrzeuge:\n";
    $stmt = $db->prepare("SELECT id, name, description, is_active FROM vehicles ORDER BY id DESC LIMIT 10");
    $stmt->execute();
    $vehicles = $stmt->fetchAll();
    foreach ($vehicles as $v) {
        echo "   - ID: {$v['id']}, Name: {$v['name']}, Aktiv: " . ($v['is_active'] ? 'Ja' : 'Nein') . "\n";
    }
    
    // Test 4: Simuliere das gleiche wie in vehicles.php
    echo "\n4. Simuliere vehicles.php INSERT:\n";
    $name = 'Simulation Test ' . date('H:i:s');
    $description = 'Simulation Beschreibung';
    $is_active = 1;
    
    $stmt = $db->prepare("INSERT INTO vehicles (name, description, is_active) VALUES (?, ?, ?)");
    $stmt->execute([$name, $description, $is_active]);
    $sim_id = $db->lastInsertId();
    echo "   ✅ Simulation INSERT - ID: $sim_id\n";
    
    // Test 5: Prüfe ob es in der Liste erscheint
    echo "\n5. Prüfe ob in Liste:\n";
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM vehicles WHERE name = ?");
    $stmt->execute([$name]);
    $count = $stmt->fetch()['count'];
    echo "   - Anzahl mit Name '$name': $count\n";
    
    echo "\n🎉 Test abgeschlossen!\n";
    
} catch (Exception $e) {
    echo "❌ Fehler: " . $e->getMessage() . "\n";
}
?>
