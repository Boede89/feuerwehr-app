<?php
/**
 * Einheiten-System: Tabellen und Spalten für Multi-Einheiten-Betrieb
 * Wird von Admin-Seiten eingebunden, um die Struktur sicherzustellen.
 */
if (!isset($db)) return;

try {
    // Tabelle einheiten
    $db->exec("CREATE TABLE IF NOT EXISTS einheiten (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        kurzbeschreibung VARCHAR(500) NULL,
        sort_order INT DEFAULT 0,
        is_active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // is_active Spalte nachträglich hinzufügen (falls Tabelle älter)
    try { $db->exec("ALTER TABLE einheiten ADD COLUMN is_active TINYINT(1) DEFAULT 1"); } catch (Exception $e) {}
    // kurzbeschreibung nachträglich hinzufügen (falls Tabelle älter)
    try { $db->exec("ALTER TABLE einheiten ADD COLUMN kurzbeschreibung VARCHAR(500) NULL"); } catch (Exception $e) {}

    // Standard-Einheiten anlegen falls leer
    $stmt = $db->query("SELECT COUNT(*) FROM einheiten");
    if ($stmt && (int)$stmt->fetchColumn() === 0) {
        $db->exec("INSERT INTO einheiten (name, sort_order) VALUES ('Löschzug Amern', 1), ('Löschzug Waldniel', 2)");
    }

    // users: user_type und einheit_id
    try { $db->exec("ALTER TABLE users ADD COLUMN user_type VARCHAR(30) DEFAULT 'user'"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE users ADD COLUMN einheit_id INT NULL"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE users ADD CONSTRAINT fk_users_einheit FOREIGN KEY (einheit_id) REFERENCES einheiten(id) ON DELETE SET NULL"); } catch (Exception $e) {}

    // user_einheiten: Benutzer mit Zugriff auf mehrere Einheiten (z.B. Superadmin oder mehrere Einheitsadmins)
    $db->exec("CREATE TABLE IF NOT EXISTS user_einheiten (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        einheit_id INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_user_einheit (user_id, einheit_id),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (einheit_id) REFERENCES einheiten(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // members: einheit_id (FK optional – kann bei bestehenden Daten fehlschlagen)
    try { $db->exec("ALTER TABLE members ADD COLUMN einheit_id INT NULL"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE members ADD CONSTRAINT fk_members_einheit FOREIGN KEY (einheit_id) REFERENCES einheiten(id) ON DELETE SET NULL"); } catch (Exception $e) {}

    // vehicles: einheit_id
    try { $db->exec("ALTER TABLE vehicles ADD COLUMN einheit_id INT NULL"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE vehicles ADD CONSTRAINT fk_vehicles_einheit FOREIGN KEY (einheit_id) REFERENCES einheiten(id) ON DELETE SET NULL"); } catch (Exception $e) {}

    // Bestehende Daten: Einheit 1 zuweisen (Löschzug Amern)
    try {
        $db->exec("UPDATE members SET einheit_id = 1 WHERE einheit_id IS NULL");
        $db->exec("UPDATE vehicles SET einheit_id = 1 WHERE einheit_id IS NULL");
    } catch (Exception $e) {}

    // Bestehende Admins zu Superadmin machen
    try {
        $db->exec("UPDATE users SET user_type = 'superadmin' WHERE (is_admin = 1 OR user_role = 'admin' OR can_settings = 1) AND (user_type IS NULL OR user_type = 'user')");
    } catch (Exception $e) {}
    // Bestehende Benutzer (nicht Superadmin) Einheit 1 zuweisen
    try {
        $db->exec("UPDATE users SET einheit_id = 1 WHERE einheit_id IS NULL AND (user_type IS NULL OR user_type = 'user' OR user_type = 'einheitsadmin')");
    } catch (Exception $e) {}
} catch (Exception $e) {
    error_log("Einheiten-Setup Fehler: " . $e->getMessage());
}
