<?php
/**
 * Debug-Skript für Datenbank-Probleme
 */

// Direkte Datenbankverbindung
$host = 'mysql';
$dbname = 'feuerwehr_app';
$username = 'feuerwehr_user';
$password = 'feuerwehr_password';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "🔍 Datenbank Debug\n";
    echo "=================\n\n";
    
    // 1. Prüfe vehicles Tabelle
    echo "1. VEHICLES TABELLE:\n";
    echo "--------------------\n";
    $stmt = $pdo->prepare("SHOW COLUMNS FROM vehicles");
    $stmt->execute();
    $columns = $stmt->fetchAll();
    foreach ($columns as $column) {
        echo "   - " . $column['Field'] . " (" . $column['Type'] . ")\n";
    }
    
    // 2. Teste INSERT mit allen Spalten
    echo "\n2. TESTE INSERT:\n";
    echo "----------------\n";
    try {
        $stmt = $pdo->prepare("INSERT INTO vehicles (name, description, is_active) VALUES (?, ?, ?)");
        $stmt->execute(['Debug Test', 'Debug Beschreibung', 1]);
        echo "✅ INSERT erfolgreich\n";
        
        // Test-Fahrzeug wieder löschen
        $pdo->exec("DELETE FROM vehicles WHERE name = 'Debug Test'");
        echo "✅ Test-Fahrzeug wieder gelöscht\n";
    } catch (Exception $e) {
        echo "❌ INSERT Fehler: " . $e->getMessage() . "\n";
    }
    
    // 3. Prüfe users Tabelle
    echo "\n3. USERS TABELLE:\n";
    echo "-----------------\n";
    $stmt = $pdo->prepare("SHOW COLUMNS FROM users");
    $stmt->execute();
    $columns = $stmt->fetchAll();
    foreach ($columns as $column) {
        echo "   - " . $column['Field'] . " (" . $column['Type'] . ")\n";
    }
    
    // 4. Teste users INSERT
    echo "\n4. TESTE USERS INSERT:\n";
    echo "----------------------\n";
    try {
        $password_hash = password_hash('test123', PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (username, email, password_hash, first_name, last_name, is_admin, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute(['testuser', 'test@test.com', $password_hash, 'Test', 'User', 0, 1]);
        echo "✅ USERS INSERT erfolgreich\n";
        
        // Test-Benutzer wieder löschen
        $pdo->exec("DELETE FROM users WHERE username = 'testuser'");
        echo "✅ Test-Benutzer wieder gelöscht\n";
    } catch (Exception $e) {
        echo "❌ USERS INSERT Fehler: " . $e->getMessage() . "\n";
    }
    
    // 5. Zeige aktuelle Daten
    echo "\n5. AKTUELLE DATEN:\n";
    echo "------------------\n";
    
    echo "Fahrzeuge:\n";
    $stmt = $pdo->prepare("SELECT id, name, description, is_active FROM vehicles ORDER BY id DESC LIMIT 3");
    $stmt->execute();
    $vehicles = $stmt->fetchAll();
    foreach ($vehicles as $vehicle) {
        echo "   - ID: {$vehicle['id']}, Name: {$vehicle['name']}, Aktiv: " . ($vehicle['is_active'] ? 'Ja' : 'Nein') . "\n";
    }
    
    echo "\nBenutzer:\n";
    $stmt = $pdo->prepare("SELECT id, username, email, first_name, last_name, is_admin, is_active FROM users ORDER BY id DESC LIMIT 3");
    $stmt->execute();
    $users = $stmt->fetchAll();
    foreach ($users as $user) {
        echo "   - ID: {$user['id']}, Username: {$user['username']}, Admin: " . ($user['is_admin'] ? 'Ja' : 'Nein') . ", Aktiv: " . ($user['is_active'] ? 'Ja' : 'Nein') . "\n";
    }
    
    echo "\n🎉 Debug abgeschlossen!\n";
    
} catch (Exception $e) {
    echo "❌ Fehler: " . $e->getMessage() . "\n";
    exit(1);
}
?>
