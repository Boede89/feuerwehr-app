<?php
/**
 * Fix Admin Permissions
 * Setzt alle Admin-User auf die neuen Permission-Spalten
 */

require_once 'config/database.php';

try {
    // Alle User mit role='admin' auf alle Permissions setzen
    $stmt = $db->prepare("UPDATE users SET 
        is_admin = 1, 
        can_reservations = 1, 
        can_users = 1, 
        can_settings = 1, 
        can_vehicles = 1 
        WHERE role = 'admin' OR user_role = 'admin'");
    
    $result = $stmt->execute();
    $count = $stmt->rowCount();
    
    echo "✅ $count Admin-User aktualisiert\n";
    
    // Speziell für Boede (falls er existiert)
    $stmt = $db->prepare("UPDATE users SET 
        is_admin = 1, 
        can_reservations = 1, 
        can_users = 1, 
        can_settings = 1, 
        can_vehicles = 1 
        WHERE username = 'Boede'");
    
    $stmt->execute();
    $count = $stmt->rowCount();
    
    if ($count > 0) {
        echo "✅ Benutzer 'Boede' explizit aktualisiert\n";
    } else {
        echo "ℹ️ Benutzer 'Boede' nicht gefunden\n";
    }
    
    // Alle User anzeigen
    $stmt = $db->prepare("SELECT username, role, user_role, is_admin, can_reservations, can_users, can_settings, can_vehicles FROM users");
    $stmt->execute();
    $users = $stmt->fetchAll();
    
    echo "\n📋 Aktuelle Benutzer:\n";
    foreach ($users as $user) {
        $permissions = [];
        if ($user['is_admin']) $permissions[] = 'Admin';
        if ($user['can_reservations']) $permissions[] = 'Reservierungen';
        if ($user['can_users']) $permissions[] = 'Benutzer';
        if ($user['can_settings']) $permissions[] = 'Einstellungen';
        if ($user['can_vehicles']) $permissions[] = 'Fahrzeuge';
        
        echo "- {$user['username']}: " . implode(', ', $permissions) . "\n";
    }
    
    echo "\n🎉 Admin-Permissions erfolgreich repariert!\n";
    
} catch (Exception $e) {
    echo "❌ Fehler: " . $e->getMessage() . "\n";
}
?>
