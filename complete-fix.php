<?php
/**
 * Kompletter Fix für alle Datenbank-Probleme
 */

// Direkte Datenbankverbindung
$host = 'mysql';
$dbname = 'feuerwehr_app';
$username = 'feuerwehr_user';
$password = 'feuerwehr_password';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "🔧 Kompletter Datenbank-Fix\n";
    echo "==========================\n\n";
    
    // 1. Prüfe und repariere vehicles Tabelle
    echo "1. VEHICLES TABELLE REPARIEREN:\n";
    echo "--------------------------------\n";
    
    // Prüfe ob type und capacity Spalten existieren
    $stmt = $pdo->prepare("SHOW COLUMNS FROM vehicles");
    $stmt->execute();
    $columns = $stmt->fetchAll();
    $column_names = array_column($columns, 'Field');
    
    if (in_array('type', $column_names)) {
        $pdo->exec("ALTER TABLE vehicles DROP COLUMN type");
        echo "✅ Spalte 'type' entfernt\n";
    }
    
    if (in_array('capacity', $column_names)) {
        $pdo->exec("ALTER TABLE vehicles DROP COLUMN capacity");
        echo "✅ Spalte 'capacity' entfernt\n";
    }
    
    // Teste INSERT
    echo "\n🧪 Teste Fahrzeug-INSERT:\n";
    $stmt = $pdo->prepare("INSERT INTO vehicles (name, description, is_active) VALUES (?, ?, ?)");
    $stmt->execute(['Test Fahrzeug', 'Test Beschreibung', 1]);
    echo "✅ Fahrzeug-INSERT erfolgreich\n";
    
    // Teste SELECT
    $stmt = $pdo->prepare("SELECT id, name, description, is_active FROM vehicles WHERE name = 'Test Fahrzeug'");
    $stmt->execute();
    $vehicle = $stmt->fetch();
    if ($vehicle) {
        echo "✅ Fahrzeug-SELECT erfolgreich - ID: {$vehicle['id']}\n";
    }
    
    // Lösche Test-Fahrzeug
    $pdo->exec("DELETE FROM vehicles WHERE name = 'Test Fahrzeug'");
    echo "✅ Test-Fahrzeug gelöscht\n";
    
    // 2. Prüfe users Tabelle
    echo "\n2. USERS TABELLE PRÜFEN:\n";
    echo "------------------------\n";
    
    // Teste users INSERT
    $password_hash = password_hash('test123', PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO users (username, email, password_hash, first_name, last_name, is_admin, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute(['testuser', 'test@test.com', $password_hash, 'Test', 'User', 0, 1]);
    echo "✅ Benutzer-INSERT erfolgreich\n";
    
    // Teste users SELECT
    $stmt = $pdo->prepare("SELECT id, username, email FROM users WHERE username = 'testuser'");
    $stmt->execute();
    $user = $stmt->fetch();
    if ($user) {
        echo "✅ Benutzer-SELECT erfolgreich - ID: {$user['id']}\n";
    }
    
    // Lösche Test-Benutzer
    $pdo->exec("DELETE FROM users WHERE username = 'testuser'");
    echo "✅ Test-Benutzer gelöscht\n";
    
    // 3. Prüfe reservations Tabelle
    echo "\n3. RESERVATIONS TABELLE PRÜFEN:\n";
    echo "--------------------------------\n";
    
    // Teste reservations SELECT (ohne v.type)
    $stmt = $pdo->prepare("
        SELECT r.*, v.name as vehicle_name 
        FROM reservations r
        JOIN vehicles v ON r.vehicle_id = v.id
        LIMIT 1
    ");
    $stmt->execute();
    echo "✅ Reservierungen-SELECT erfolgreich\n";
    
    // 4. Zeige aktuelle Daten
    echo "\n4. AKTUELLE DATEN:\n";
    echo "------------------\n";
    
    echo "Fahrzeuge:\n";
    $stmt = $pdo->prepare("SELECT id, name, description, is_active FROM vehicles ORDER BY id DESC LIMIT 5");
    $stmt->execute();
    $vehicles = $stmt->fetchAll();
    foreach ($vehicles as $vehicle) {
        echo "   - ID: {$vehicle['id']}, Name: {$vehicle['name']}, Aktiv: " . ($vehicle['is_active'] ? 'Ja' : 'Nein') . "\n";
    }
    
    echo "\nBenutzer:\n";
    $stmt = $pdo->prepare("SELECT id, username, email, first_name, last_name, is_admin, is_active FROM users ORDER BY id DESC LIMIT 5");
    $stmt->execute();
    $users = $stmt->fetchAll();
    foreach ($users as $user) {
        echo "   - ID: {$user['id']}, Username: {$user['username']}, Admin: " . ($user['is_admin'] ? 'Ja' : 'Nein') . ", Aktiv: " . ($user['is_active'] ? 'Ja' : 'Nein') . "\n";
    }
    
    echo "\nReservierungen:\n";
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM reservations");
    $stmt->execute();
    $count = $stmt->fetch()['count'];
    echo "   - Anzahl: $count\n";
    
    echo "\n🎉 Kompletter Fix abgeschlossen!\n";
    echo "✅ Alle Tabellen funktionieren korrekt\n";
    echo "✅ Fahrzeuge können hinzugefügt werden\n";
    echo "✅ Benutzer können hinzugefügt werden\n";
    echo "✅ Reservierungen können angezeigt werden\n";
    
} catch (Exception $e) {
    echo "❌ Fehler: " . $e->getMessage() . "\n";
    exit(1);
}
?>
