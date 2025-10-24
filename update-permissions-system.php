<?php
/**
 * Update Permissions System
 * Erweitert die users Tabelle um granular permissions
 */

require_once 'config/database.php';

function columnExists(PDO $db, string $table, string $column): bool {
    $stmt = $db->prepare("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$table, $column]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return isset($row['cnt']) ? (int)$row['cnt'] > 0 : false;
}

function addBooleanColumnIfMissing(PDO $db, string $table, string $column): void {
    if (!columnExists($db, $table, $column)) {
        $sql = "ALTER TABLE `$table` ADD COLUMN `$column` TINYINT(1) NOT NULL DEFAULT 0";
        $db->exec($sql);
        echo "✅ Spalte hinzugefügt: $column\n";
    } else {
        echo "ℹ️ Spalte bereits vorhanden: $column\n";
    }
}

try {
    // Jede Spalte einzeln und idempotent hinzufügen
    addBooleanColumnIfMissing($db, 'users', 'is_admin');
    addBooleanColumnIfMissing($db, 'users', 'can_reservations');
    addBooleanColumnIfMissing($db, 'users', 'can_users');
    addBooleanColumnIfMissing($db, 'users', 'can_settings');
    addBooleanColumnIfMissing($db, 'users', 'can_vehicles');

    // Bestehende Admin-User migrieren (Fallback: role oder user_role Feld nutzen)
    $roleColumn = 'role';
    $roleExists = columnExists($db, 'users', 'role');
    if (!$roleExists && columnExists($db, 'users', 'user_role')) {
        $roleColumn = 'user_role';
    }

    // Admins voll freischalten
    $db->exec("UPDATE users SET is_admin = 1, can_reservations = 1, can_users = 1, can_settings = 1, can_vehicles = 1 WHERE $roleColumn = 'admin'");
    echo "✅ Admin-User migriert\n";

    // Nicht-Admins erhalten standardmäßig Reservierungen-Recht (optional)
    $db->exec("UPDATE users SET can_reservations = 1 WHERE $roleColumn <> 'admin'");
    echo "✅ Standardrechte für Nicht-Admins gesetzt\n";

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
