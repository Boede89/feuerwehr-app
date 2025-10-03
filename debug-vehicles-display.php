<?php
/**
 * Debug-Skript für Fahrzeug-Anzeige Problem
 */

echo "🔍 Debug: Fahrzeug-Anzeige Problem\n";
echo "==================================\n\n";

// 1. Datenbankverbindung testen
echo "1. Datenbankverbindung testen:\n";
try {
    require_once 'config/database.php';
    echo "   ✅ Datenbankverbindung erfolgreich\n";
    echo "   - Host: mysql\n";
    echo "   - Database: feuerwehr_app\n";
    echo "   - User: feuerwehr_user\n";
} catch (Exception $e) {
    echo "   ❌ Datenbankverbindung fehlgeschlagen: " . $e->getMessage() . "\n";
    exit;
}

// 2. Fahrzeuge aus der Datenbank laden (genau wie in vehicles.php)
echo "\n2. Fahrzeuge aus Datenbank laden:\n";
try {
    $stmt = $db->prepare("SELECT id, name, description, is_active FROM vehicles ORDER BY name ASC");
    $stmt->execute();
    $vehicles = $stmt->fetchAll();
    
    echo "   - Anzahl Fahrzeuge: " . count($vehicles) . "\n";
    
    if (count($vehicles) > 0) {
        echo "   - Fahrzeuge:\n";
        foreach ($vehicles as $vehicle) {
            echo "     * ID: {$vehicle['id']}, Name: {$vehicle['name']}, Aktiv: " . ($vehicle['is_active'] ? 'Ja' : 'Nein') . "\n";
        }
    } else {
        echo "   ⚠️  Keine Fahrzeuge gefunden!\n";
    }
} catch (Exception $e) {
    echo "   ❌ Fehler beim Laden der Fahrzeuge: " . $e->getMessage() . "\n";
}

// 3. Test: Neues Fahrzeug hinzufügen
echo "\n3. Test: Neues Fahrzeug hinzufügen:\n";
try {
    $test_name = 'Debug Test ' . date('H:i:s');
    $test_description = 'Debug Test Beschreibung';
    $test_active = 1;
    
    $stmt = $db->prepare("INSERT INTO vehicles (name, description, is_active) VALUES (?, ?, ?)");
    $stmt->execute([$test_name, $test_description, $test_active]);
    $insert_id = $db->lastInsertId();
    
    echo "   ✅ Test-Fahrzeug hinzugefügt - ID: $insert_id\n";
    
    // Sofort prüfen ob es da ist
    $stmt = $db->prepare("SELECT * FROM vehicles WHERE id = ?");
    $stmt->execute([$insert_id]);
    $test_vehicle = $stmt->fetch();
    
    if ($test_vehicle) {
        echo "   ✅ Test-Fahrzeug sofort verfügbar - Name: {$test_vehicle['name']}\n";
    } else {
        echo "   ❌ Test-Fahrzeug nicht sofort verfügbar!\n";
    }
    
} catch (Exception $e) {
    echo "   ❌ Fehler beim Hinzufügen des Test-Fahrzeugs: " . $e->getMessage() . "\n";
}

// 4. Alle Fahrzeuge nochmal anzeigen
echo "\n4. Alle Fahrzeuge nach Test:\n";
try {
    $stmt = $db->prepare("SELECT id, name, description, is_active FROM vehicles ORDER BY id DESC LIMIT 10");
    $stmt->execute();
    $vehicles = $stmt->fetchAll();
    
    echo "   - Anzahl Fahrzeuge: " . count($vehicles) . "\n";
    
    foreach ($vehicles as $vehicle) {
        echo "     * ID: {$vehicle['id']}, Name: {$vehicle['name']}, Aktiv: " . ($vehicle['is_active'] ? 'Ja' : 'Nein') . "\n";
    }
} catch (Exception $e) {
    echo "   ❌ Fehler beim erneuten Laden: " . $e->getMessage() . "\n";
}

echo "\n🎯 Debug abgeschlossen!\n";
?>
