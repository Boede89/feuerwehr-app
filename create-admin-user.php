<?php
/**
 * Create Admin User
 * Erstellt einen Admin-User mit allen Berechtigungen
 */

require_once 'config/database.php';

try {
    $username = 'admin';
    $email = 'admin@feuerwehr.local';
    $password = 'admin123';
    $first_name = 'Admin';
    $last_name = 'User';
    
    // Prüfen ob User bereits existiert
    $stmt = $db->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
    $stmt->execute([$username, $email]);
    $existing = $stmt->fetch();
    
    if ($existing) {
        echo "ℹ️ Benutzer '$username' existiert bereits - aktualisiere Berechtigungen\n";
        
        // Bestehenden User auf Admin setzen
        $stmt = $db->prepare("UPDATE users SET 
            password_hash = ?, 
            first_name = ?, 
            last_name = ?, 
            user_role = 'admin',
            role = 'admin',
            is_admin = 1, 
            can_reservations = 1, 
            can_users = 1, 
            can_settings = 1, 
            can_vehicles = 1,
            is_active = 1,
            email_notifications = 1
            WHERE id = ?");
        
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt->execute([$password_hash, $first_name, $last_name, $existing['id']]);
        
        echo "✅ Benutzer '$username' aktualisiert\n";
    } else {
        echo "🆕 Erstelle neuen Admin-User '$username'\n";
        
        // Neuen User erstellen
        $stmt = $db->prepare("INSERT INTO users 
            (username, email, password_hash, first_name, last_name, user_role, role, 
             is_admin, can_reservations, can_users, can_settings, can_vehicles, 
             is_active, email_notifications, created_at) 
            VALUES (?, ?, ?, ?, ?, 'admin', 'admin', 1, 1, 1, 1, 1, 1, 1, NOW())");
        
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt->execute([$username, $email, $password_hash, $first_name, $last_name]);
        
        echo "✅ Admin-User '$username' erstellt\n";
    }
    
    // Login-Daten anzeigen
    echo "\n🔑 Login-Daten:\n";
    echo "Benutzername: $username\n";
    echo "Passwort: $password\n";
    echo "E-Mail: $email\n";
    
    // Alle User anzeigen
    $stmt = $db->prepare("SELECT username, role, user_role, is_admin, can_reservations, can_users, can_settings, can_vehicles FROM users ORDER BY created_at DESC");
    $stmt->execute();
    $users = $stmt->fetchAll();
    
    echo "\n📋 Alle Benutzer:\n";
    foreach ($users as $user) {
        $permissions = [];
        if ($user['is_admin']) $permissions[] = 'Admin';
        if ($user['can_reservations']) $permissions[] = 'Reservierungen';
        if ($user['can_users']) $permissions[] = 'Benutzer';
        if ($user['can_settings']) $permissions[] = 'Einstellungen';
        if ($user['can_vehicles']) $permissions[] = 'Fahrzeuge';
        
        $role_info = $user['role'] ?: $user['user_role'] ?: 'unbekannt';
        echo "- {$user['username']} ($role_info): " . implode(', ', $permissions) . "\n";
    }
    
    echo "\n🎉 Admin-User erfolgreich erstellt/aktualisiert!\n";
    echo "Du kannst dich jetzt mit 'admin' / 'admin123' einloggen.\n";
    
} catch (Exception $e) {
    echo "❌ Fehler: " . $e->getMessage() . "\n";
}
?>