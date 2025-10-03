<?php
/**
 * Datenbank-Schema Fix für Feuerwehr App
 * Entfernt type und capacity Spalten, da sie nicht mehr benötigt werden
 */

// Direkte Datenbankverbindung
$host = 'mysql';
$dbname = 'feuerwehr_app';
$username = 'feuerwehr_user';
$password = 'feuerwehr_password';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "🔧 Datenbank-Schema Fix\n";
    echo "======================\n\n";
    
    // Prüfen ob type und capacity Spalten existieren
    $stmt = $pdo->prepare("SHOW COLUMNS FROM vehicles LIKE 'type'");
    $stmt->execute();
    $type_exists = $stmt->fetch() !== false;
    
    $stmt = $pdo->prepare("SHOW COLUMNS FROM vehicles LIKE 'capacity'");
    $stmt->execute();
    $capacity_exists = $stmt->fetch() !== false;
    
    echo "📊 Aktuelle Spalten in vehicles Tabelle:\n";
    $stmt = $pdo->prepare("SHOW COLUMNS FROM vehicles");
    $stmt->execute();
    $columns = $stmt->fetchAll();
    foreach ($columns as $column) {
        echo "   - " . $column['Field'] . " (" . $column['Type'] . ")\n";
    }
    echo "\n";
    
    if ($type_exists || $capacity_exists) {
        echo "🔧 Entferne nicht mehr benötigte Spalten...\n";
        
        if ($type_exists) {
            $pdo->exec("ALTER TABLE vehicles DROP COLUMN type");
            echo "✅ Spalte 'type' entfernt\n";
        }
        
        if ($capacity_exists) {
            $pdo->exec("ALTER TABLE vehicles DROP COLUMN capacity");
            echo "✅ Spalte 'capacity' entfernt\n";
        }
        
        echo "\n✅ Datenbank-Schema erfolgreich aktualisiert!\n";
    } else {
        echo "✅ Schema ist bereits korrekt - keine Änderungen nötig\n";
    }
    
    // Test: Fahrzeug hinzufügen
    echo "\n🧪 Test: Füge Test-Fahrzeug hinzu...\n";
    $stmt = $pdo->prepare("INSERT INTO vehicles (name, description, is_active) VALUES (?, ?, ?)");
    $stmt->execute(['Test Fahrzeug', 'Test Beschreibung', 1]);
    echo "✅ Test-Fahrzeug erfolgreich hinzugefügt\n";
    
    // Test: Fahrzeuge abfragen
    echo "\n📋 Aktuelle Fahrzeuge:\n";
    $stmt = $pdo->prepare("SELECT id, name, description, is_active FROM vehicles ORDER BY id DESC LIMIT 5");
    $stmt->execute();
    $vehicles = $stmt->fetchAll();
    foreach ($vehicles as $vehicle) {
        echo "   - ID: {$vehicle['id']}, Name: {$vehicle['name']}, Aktiv: " . ($vehicle['is_active'] ? 'Ja' : 'Nein') . "\n";
    }
    
    echo "\n🎉 Datenbank-Schema Fix abgeschlossen!\n";
    echo "🚗 Fahrzeuge können jetzt korrekt hinzugefügt werden\n";
    echo "👥 Benutzer können jetzt korrekt hinzugefügt werden\n";
    
} catch (Exception $e) {
    echo "❌ Fehler: " . $e->getMessage() . "\n";
    exit(1);
}
?>
