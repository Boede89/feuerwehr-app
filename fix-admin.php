<?php
/**
 * Admin-Benutzer Fix für Feuerwehr App
 */

// Direkte Datenbankverbindung ohne PDO-Klasse
$host = 'mysql';
$dbname = 'feuerwehr_app';
$username = 'feuerwehr_user';
$password = 'feuerwehr_password';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "✅ Datenbankverbindung erfolgreich\n";
    
    // Prüfen ob Admin-Benutzer existiert
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM users WHERE username = 'admin'");
    $stmt->execute();
    $admin_exists = $stmt->fetch()['count'] > 0;
    
    if ($admin_exists) {
        echo "✅ Admin-Benutzer bereits vorhanden\n";
        
        // Admin-Benutzer aktivieren
        $stmt = $pdo->prepare("UPDATE users SET is_active = 1 WHERE username = 'admin'");
        $stmt->execute();
        echo "✅ Admin-Benutzer aktiviert\n";
        
    } else {
        // Admin-Benutzer erstellen
        $admin_password = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'; // admin123
        $stmt = $pdo->prepare("INSERT INTO users (username, email, password_hash, first_name, last_name, is_admin, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute(['admin', 'admin@feuerwehr-app.local', $admin_password, 'Admin', 'User', 1, 1]);
        echo "✅ Admin-Benutzer erstellt und aktiviert\n";
    }
    
    // Prüfen ob Fahrzeuge vorhanden sind
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM vehicles");
    $stmt->execute();
    $vehicles_count = $stmt->fetch()['count'];
    
    if ($vehicles_count == 0) {
        // Beispiel-Fahrzeuge erstellen
        $vehicles = [
            ['LF 20/1', 'Löschfahrzeug', 'Standard Löschfahrzeug mit 2000L Wasser', 6],
            ['DLK 23/12', 'Drehleiter', 'Drehleiter mit 23m Steighöhe', 3],
            ['RW 2', 'Rüstwagen', 'Rüstwagen für technische Hilfeleistung', 4],
            ['ELW 1', 'Einsatzleitwagen', 'Fahrzeug für Einsatzleitung', 2],
            ['MTF', 'Mannschaftstransportfahrzeug', 'Transportfahrzeug für Personal', 8]
        ];
        
        $stmt = $pdo->prepare("INSERT INTO vehicles (name, type, description, capacity) VALUES (?, ?, ?, ?)");
        foreach ($vehicles as $vehicle) {
            $stmt->execute($vehicle);
        }
        echo "✅ Beispiel-Fahrzeuge erstellt\n";
    } else {
        echo "✅ $vehicles_count Fahrzeuge bereits vorhanden\n";
    }
    
    echo "\n🎉 Admin-Login sollte jetzt funktionieren!\n";
    echo "👤 Benutzername: admin\n";
    echo "🔑 Passwort: admin123\n";
    
} catch (Exception $e) {
    echo "❌ Fehler: " . $e->getMessage() . "\n";
    exit(1);
}
?>
