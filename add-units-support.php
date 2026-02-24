<?php
/**
 * Migration: Multi-Einheiten-Support
 * Erstellt units-Tabelle, user_units, fügt unit_id zu relevanten Tabellen hinzu.
 * Öffnen Sie diese Datei einmal im Browser: http://ihre-domain/add-units-support.php
 */
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Multi-Einheiten-Support</h1>";
echo "<p>Diese Migration fügt die Einheiten-Struktur hinzu.</p>";

try {
    require_once __DIR__ . '/config/database.php';
    echo "<p style='color: green;'>✅ Datenbankverbindung erfolgreich</p>";

    // 1. Units-Tabelle erstellen
    echo "<h2>1. Units-Tabelle</h2>";
    $db->exec("
        CREATE TABLE IF NOT EXISTS units (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            slug VARCHAR(50) NOT NULL UNIQUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "<p style='color: green;'>✅ units Tabelle erstellt/geprüft</p>";

    // Einheiten einfügen falls leer
    $stmt = $db->query("SELECT COUNT(*) FROM units");
    if ((int)$stmt->fetchColumn() === 0) {
        $db->exec("INSERT INTO units (id, name, slug) VALUES (1, 'Hauptfeuerwehr', 'hauptfeuerwehr')");
        $db->exec("INSERT INTO units (id, name, slug) VALUES (2, 'Zweite Einheit', 'zweite-einheit')");
        echo "<p style='color: green;'>✅ Einheit 1 (Hauptfeuerwehr) und Einheit 2 (Zweite Einheit) angelegt</p>";
    } else {
        echo "<p style='color: blue;'>ℹ Einheiten existieren bereits</p>";
    }

    // 2. user_units Tabelle (Benutzer zu Einheiten)
    echo "<h2>2. user_units Tabelle</h2>";
    $db->exec("
        CREATE TABLE IF NOT EXISTS user_units (
            user_id INT NOT NULL,
            unit_id INT NOT NULL,
            PRIMARY KEY (user_id, unit_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (unit_id) REFERENCES units(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "<p style='color: green;'>✅ user_units Tabelle erstellt</p>";

    // Alle Benutzer zu Einheit 1 zuweisen, Admins zusätzlich zu Einheit 2
    $stmt = $db->query("SELECT id, is_admin, user_role FROM users WHERE is_active = 1");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($users as $u) {
        $isAdmin = !empty($u['is_admin']) || ($u['user_role'] ?? '') === 'admin';
        try {
            $db->prepare("INSERT IGNORE INTO user_units (user_id, unit_id) VALUES (?, 1)")->execute([$u['id']]);
            if ($isAdmin) {
                $db->prepare("INSERT IGNORE INTO user_units (user_id, unit_id) VALUES (?, 2)")->execute([$u['id']]);
            }
        } catch (Exception $e) { /* ignore */ }
    }
    echo "<p style='color: green;'>✅ Benutzer zu Einheiten zugewiesen (Admins: Einheit 1+2)</p>";

    // 3. unit_id zu Tabellen hinzufügen
    $tablesWithUnit = [
        'members' => 'ALTER TABLE members ADD COLUMN unit_id INT DEFAULT 1',
        'vehicles' => 'ALTER TABLE vehicles ADD COLUMN unit_id INT DEFAULT 1',
        'atemschutz_traeger' => 'ALTER TABLE atemschutz_traeger ADD COLUMN unit_id INT DEFAULT 1',
        'atemschutz_entries' => 'ALTER TABLE atemschutz_entries ADD COLUMN unit_id INT DEFAULT 1',
        'reservations' => 'ALTER TABLE reservations ADD COLUMN unit_id INT DEFAULT 1',
        'courses' => 'ALTER TABLE courses ADD COLUMN unit_id INT DEFAULT 1',
        'ric_codes' => 'ALTER TABLE ric_codes ADD COLUMN unit_id INT DEFAULT 1',
        'app_forms' => 'ALTER TABLE app_forms ADD COLUMN unit_id INT DEFAULT 1',
        'dashboard_preferences' => 'ALTER TABLE dashboard_preferences ADD COLUMN unit_id INT DEFAULT 1',
        'dashboard_settings' => 'ALTER TABLE dashboard_settings ADD COLUMN unit_id INT DEFAULT 1',
    ];

    echo "<h2>3. unit_id zu Tabellen</h2>";
    foreach ($tablesWithUnit as $table => $sql) {
        try {
            $db->exec("DESCRIBE $table");
        } catch (Exception $e) {
            echo "<p style='color: orange;'>⚠ Tabelle $table existiert nicht, überspringe</p>";
            continue;
        }
        try {
            $db->exec($sql);
            echo "<p style='color: green;'>✅ $table: unit_id hinzugefügt</p>";
        } catch (Exception $e) {
            if (strpos($e->getMessage(), 'Duplicate column') !== false) {
                echo "<p style='color: blue;'>ℹ $table: unit_id existiert bereits</p>";
            } else {
                echo "<p style='color: orange;'>⚠ $table: " . htmlspecialchars($e->getMessage()) . "</p>";
            }
        }
    }

    // 4. settings Tabelle: unit_id hinzufügen
    echo "<h2>4. settings Tabelle</h2>";
    try {
        $db->exec("ALTER TABLE settings ADD COLUMN unit_id INT DEFAULT 1");
        echo "<p style='color: green;'>✅ settings: unit_id hinzugefügt</p>";
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false) {
            echo "<p style='color: blue;'>ℹ settings: unit_id existiert bereits</p>";
        } else {
            echo "<p style='color: orange;'>⚠ settings: " . htmlspecialchars($e->getMessage()) . "</p>";
        }
    }

    // Unique-Constraint für settings anpassen (unit_id, setting_key)
    try {
        $idx = $db->query("SHOW INDEX FROM settings WHERE Column_name = 'setting_key' AND Non_unique = 0")->fetch(PDO::FETCH_ASSOC);
        if ($idx && !empty($idx['Key_name'] ?? $idx['key_name'] ?? '')) {
            $keyName = $idx['Key_name'] ?? $idx['key_name'];
            $db->exec("ALTER TABLE settings DROP INDEX `" . $keyName . "`");
        }
    } catch (Exception $e) { /* ignore */ }
    try {
        $db->exec("ALTER TABLE settings ADD UNIQUE KEY unique_unit_setting (unit_id, setting_key)");
        echo "<p style='color: green;'>✅ settings: Unique-Constraint (unit_id, setting_key) gesetzt</p>";
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate') !== false || strpos($e->getMessage(), 'already exists') !== false) {
            echo "<p style='color: blue;'>ℹ settings: Constraint existiert bereits</p>";
        } else {
            echo "<p style='color: orange;'>⚠ " . htmlspecialchars($e->getMessage()) . "</p>";
        }
    }

    // 5. Weitere Tabellen mit unit_id (falls vorhanden)
    $moreTables = ['member_courses', 'member_ric', 'member_qualifications', 'strecke_termine', 'strecke_zuordnungen',
        'dienstplan', 'anwesenheitslisten', 'maengelberichte', 'geraetewartmitteilungen', 'calendar_events'];
    foreach ($moreTables as $table) {
        try {
            $db->exec("DESCRIBE $table");
            $db->exec("ALTER TABLE $table ADD COLUMN unit_id INT DEFAULT 1");
            echo "<p style='color: green;'>✅ $table: unit_id hinzugefügt</p>";
        } catch (Exception $e) {
            if (strpos($e->getMessage(), 'Duplicate column') !== false) {
                echo "<p style='color: blue;'>ℹ $table: unit_id existiert bereits</p>";
            }
        }
    }

    echo "<h2>Fertig</h2>";
    echo "<p><a href='index.php'>Zur Startseite</a></p>";

} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}

ob_end_flush();
