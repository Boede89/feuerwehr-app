<?php
/**
 * Update Permissions System
 * Erweitert die users Tabelle um granular permissions
 */

require_once 'config/database.php';

try {
    // Neue Spalten für granular permissions hinzufügen
    $sql = "ALTER TABLE users 
            ADD COLUMN is_admin BOOLEAN DEFAULT FALSE,
            ADD COLUMN can_reservations BOOLEAN DEFAULT FALSE,
            ADD COLUMN can_users BOOLEAN DEFAULT FALSE,
            ADD COLUMN can_settings BOOLEAN DEFAULT FALSE,
            ADD COLUMN can_vehicles BOOLEAN DEFAULT FALSE";
    
    $db->exec($sql);
    echo "✅ Spalten für granular permissions hinzugefügt\n";
    
    // Bestehende Admin-User migrieren
    $stmt = $db->prepare("UPDATE users SET is_admin = TRUE, can_reservations = TRUE, can_users = TRUE, can_settings = TRUE, can_vehicles = TRUE WHERE role = 'admin'");
    $stmt->execute();
    echo "✅ Bestehende Admin-User migriert\n";
    
    // Normale User bekommen nur Reservierungen-Recht
    $stmt = $db->prepare("UPDATE users SET can_reservations = TRUE WHERE role != 'admin'");
    $stmt->execute();
    echo "✅ Normale User bekommen Reservierungen-Recht\n";
    
    echo "\n🎉 Permissions System erfolgreich aktualisiert!\n";
    echo "Neue Berechtigungen:\n";
    echo "- is_admin: Vollzugriff auf alles\n";
    echo "- can_reservations: Dashboard + Reservierungen\n";
    echo "- can_users: Benutzerverwaltung\n";
    echo "- can_settings: Einstellungen\n";
    echo "- can_vehicles: Fahrzeugverwaltung\n";
    
} catch (Exception $e) {
    echo "❌ Fehler: " . $e->getMessage() . "\n";
}
?>
