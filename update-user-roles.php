<?php
/**
 * Erweitert Benutzerrollen und E-Mail-Benachrichtigungen
 */

require_once 'config/database.php';

echo "🔧 Benutzerrollen erweitern\n";
echo "===========================\n\n";

try {
    // 1. Prüfen ob neue Spalten bereits existieren
    echo "1. Prüfe Datenbank-Schema...\n";
    
    // Prüfe user_role Spalte
    $stmt = $db->query("SHOW COLUMNS FROM users LIKE 'user_role'");
    $role_column_exists = $stmt->fetch();
    
    if ($role_column_exists) {
        echo "   ✅ Spalte 'user_role' existiert bereits\n";
    } else {
        echo "   ➕ Füge Spalte 'user_role' hinzu...\n";
        $db->exec("ALTER TABLE users ADD COLUMN user_role ENUM('admin', 'approver', 'user') DEFAULT 'user' AFTER is_admin");
        echo "   ✅ Spalte 'user_role' hinzugefügt\n";
    }
    
    // Prüfe email_notifications Spalte
    $stmt = $db->query("SHOW COLUMNS FROM users LIKE 'email_notifications'");
    $email_column_exists = $stmt->fetch();
    
    if ($email_column_exists) {
        echo "   ✅ Spalte 'email_notifications' existiert bereits\n";
    } else {
        echo "   ➕ Füge Spalte 'email_notifications' hinzu...\n";
        $db->exec("ALTER TABLE users ADD COLUMN email_notifications TINYINT(1) DEFAULT 1 AFTER user_role");
        echo "   ✅ Spalte 'email_notifications' hinzugefügt\n";
    }
    
    // 2. Bestehende Benutzer aktualisieren
    echo "\n2. Aktualisiere bestehende Benutzer...\n";
    
    // Admin-Benutzer zu 'admin' Rolle setzen
    $stmt = $db->prepare("UPDATE users SET user_role = 'admin' WHERE is_admin = 1");
    $stmt->execute();
    $admin_count = $stmt->rowCount();
    echo "   ✅ $admin_count Admin-Benutzer auf 'admin' Rolle gesetzt\n";
    
    // Normale Benutzer zu 'user' Rolle setzen
    $stmt = $db->prepare("UPDATE users SET user_role = 'user' WHERE is_admin = 0");
    $stmt->execute();
    $user_count = $stmt->rowCount();
    echo "   ✅ $user_count normale Benutzer auf 'user' Rolle gesetzt\n";
    
    // 3. Beispiel-Genehmiger erstellen
    echo "\n3. Erstelle Beispiel-Genehmiger...\n";
    
    // Prüfe ob bereits ein Genehmiger existiert
    $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE user_role = 'approver'");
    $stmt->execute();
    $approver_count = $stmt->fetchColumn();
    
    if ($approver_count == 0) {
        $stmt = $db->prepare("INSERT INTO users (username, email, password_hash, first_name, last_name, user_role, email_notifications, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            'genehmiger',
            'genehmiger@feuerwehr-app.local',
            password_hash('genehmiger123', PASSWORD_DEFAULT),
            'Genehmiger',
            'User',
            'approver',
            1,
            1
        ]);
        echo "   ✅ Beispiel-Genehmiger erstellt (Username: genehmiger, Passwort: genehmiger123)\n";
    } else {
        echo "   ✅ $approver_count Genehmiger bereits vorhanden\n";
    }
    
    // 4. Rollen-Übersicht anzeigen
    echo "\n4. Aktuelle Benutzerrollen:\n";
    $stmt = $db->query("SELECT user_role, COUNT(*) as count FROM users GROUP BY user_role");
    $roles = $stmt->fetchAll();
    
    foreach ($roles as $role) {
        echo "   - {$role['user_role']}: {$role['count']} Benutzer\n";
    }
    
    echo "\n🎯 Benutzerrollen erfolgreich erweitert!\n";
    echo "📋 Rollen:\n";
    echo "   - admin: Vollzugriff auf alle Funktionen\n";
    echo "   - approver: Kann Anträge genehmigen, aber keine Admin-Einstellungen\n";
    echo "   - user: Kann nur Reservierungen einreichen\n";
    
} catch (Exception $e) {
    echo "❌ Fehler: " . $e->getMessage() . "\n";
}

echo "\n🎯 Setup abgeschlossen!\n";
?>
