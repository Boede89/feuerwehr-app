<?php
/**
 * Datenbank-Initialisierung für Feuerwehr App
 * Dieses Skript erstellt die Datenbank und den Admin-Benutzer
 */

require_once 'config/database.php';

echo "🚒 Feuerwehr App - Datenbank-Initialisierung\n";
echo "==========================================\n\n";

try {
    // Datenbankverbindung testen
    if (!$db) {
        throw new Exception("Keine Datenbankverbindung möglich");
    }
    
    echo "✅ Datenbankverbindung erfolgreich\n";
    
    // Prüfen ob Admin-Benutzer bereits existiert
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM users WHERE username = 'admin'");
    $stmt->execute();
    $admin_exists = $stmt->fetch()['count'] > 0;
    
    if ($admin_exists) {
        echo "✅ Admin-Benutzer bereits vorhanden\n";
        // Bestehenden Admin auf user_type='superadmin' setzen (für Löschbarkeit)
        try {
            $db->exec("ALTER TABLE users ADD COLUMN user_type VARCHAR(50) NULL");
        } catch (Exception $e) {}
        $stmt = $db->prepare("UPDATE users SET user_type = 'superadmin' WHERE username = 'admin' AND (user_type IS NULL OR user_type = '' OR user_type = 'user')");
        $stmt->execute();
    } else {
        // Admin-Benutzer erstellen (user_type='superadmin' für Löschbarkeit in Benutzerverwaltung)
        try { $db->exec("ALTER TABLE users ADD COLUMN user_type VARCHAR(50) NULL"); } catch (Exception $e) {}
        $admin_password = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'; // admin123
        $stmt = $db->prepare("INSERT INTO users (username, email, password_hash, first_name, last_name, is_admin, user_type) VALUES (?, ?, ?, ?, ?, ?, 'superadmin')");
        $stmt->execute(['admin', 'admin@feuerwehr-app.local', $admin_password, 'Admin', 'User', 1]);
        echo "✅ Admin-Benutzer erstellt\n";
    }
    
    // Prüfen ob Fahrzeuge vorhanden sind
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM vehicles");
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
        
        $stmt = $db->prepare("INSERT INTO vehicles (name, type, description, capacity) VALUES (?, ?, ?, ?)");
        foreach ($vehicles as $vehicle) {
            $stmt->execute($vehicle);
        }
        echo "✅ Beispiel-Fahrzeuge erstellt\n";
    } else {
        echo "✅ $vehicles_count Fahrzeuge bereits vorhanden\n";
    }
    
    echo "\n🎉 Datenbank-Initialisierung abgeschlossen!\n";
    echo "👤 Admin-Login: admin / admin123\n";
    echo "🌐 Webanwendung: http://localhost\n";
    echo "🗄️ phpMyAdmin: http://localhost:8080\n";
    
} catch (Exception $e) {
    echo "❌ Fehler: " . $e->getMessage() . "\n";
    exit(1);
}
?>
