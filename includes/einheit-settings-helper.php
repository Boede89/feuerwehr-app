<?php
/**
 * Helper für einheitsspezifische Einstellungen.
 * Löschzug Amern = alle bestehenden Einstellungen.
 * Löschzug Waldniel = leer (erstmal).
 */

/**
 * Gibt die Einheit-ID für "Löschzug Amern" zurück (bestehende Einstellungen).
 */
function get_einheit_amern_id($db) {
    static $id = null;
    if ($id === null) {
        try {
            $stmt = $db->prepare("SELECT id FROM einheiten WHERE name LIKE '%Amern%' LIMIT 1");
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $id = $row ? (int)$row['id'] : 0;
        } catch (Exception $e) {
            $id = 0;
        }
    }
    return $id;
}

/**
 * Prüft, ob die Einheit "Löschzug Waldniel" ist (leere Einstellungen).
 */
function is_einheit_waldniel($db, $einheit_id) {
    try {
        $stmt = $db->prepare("SELECT name FROM einheiten WHERE id = ?");
        $stmt->execute([$einheit_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row && (stripos($row['name'], 'Waldniel') !== false);
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Lädt Einstellungen für eine Einheit.
 * Amern: Migration durchführen (einmalig), dann aus einheit_settings laden.
 * Alle anderen Einheiten: aus einheit_settings (jede Einheit hat eigene Daten).
 * Ohne einheit_id: aus settings (Legacy/ globale Einstellungen).
 */
function load_settings_for_einheit($db, $einheit_id = null) {
    $settings = [];
    if ($einheit_id === null || $einheit_id <= 0) {
        // Legacy: aus settings (ohne Einheit)
        try {
            $stmt = $db->query("SELECT setting_key, setting_value FROM settings");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
        } catch (Exception $e) {}
        return $settings;
    }
    // Amern: Migration durchführen (einmalig), dann aus einheit_settings laden
    if (get_einheit_amern_id($db) === (int)$einheit_id) {
        migrate_settings_to_amern($db);
    }
    // Alle Einheiten: aus einheit_settings laden (jede Einheit hat eigene Daten)
    try {
        ensure_einheit_settings_table($db);
        $stmt = $db->prepare("SELECT setting_key, setting_value FROM einheit_settings WHERE einheit_id = ?");
        $stmt->execute([$einheit_id]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
    } catch (Exception $e) {}
    return $settings;
}

/**
 * Stellt sicher, dass die Tabelle einheit_settings existiert.
 */
function ensure_einheit_settings_table($db) {
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS einheit_settings (
            einheit_id INT NOT NULL,
            setting_key VARCHAR(191) NOT NULL,
            setting_value LONGTEXT NULL,
            PRIMARY KEY (einheit_id, setting_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {}
}

/**
 * Speichert eine Einstellung für eine Einheit.
 */
function save_setting_for_einheit($db, $einheit_id, $key, $value) {
    if ($einheit_id <= 0) return false;
    ensure_einheit_settings_table($db);
    try {
        $stmt = $db->prepare("INSERT INTO einheit_settings (einheit_id, setting_key, setting_value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $stmt->execute([$einheit_id, $key, $value]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Speichert mehrere Einstellungen für eine Einheit.
 */
function save_settings_bulk_for_einheit($db, $einheit_id, $settings_array) {
    if ($einheit_id <= 0) return false;
    ensure_einheit_settings_table($db);
    try {
        $stmt = $db->prepare("INSERT INTO einheit_settings (einheit_id, setting_key, setting_value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        foreach ($settings_array as $k => $v) {
            $stmt->execute([$einheit_id, (string)$k, $v === null ? '' : (string)$v]);
        }
        return true;
    } catch (Exception $e) {
        error_log('save_settings_bulk_for_einheit: ' . $e->getMessage());
        throw $e;
    }
}

/**
 * Migriert bestehende settings nach einheit_settings für Löschzug Amern.
 * Wird einmalig beim ersten Laden ausgeführt.
 */
function migrate_settings_to_amern($db) {
    $amern_id = get_einheit_amern_id($db);
    if ($amern_id <= 0) return;
    ensure_einheit_settings_table($db);
    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM einheit_settings WHERE einheit_id = ?");
        $stmt->execute([$amern_id]);
        if ((int)$stmt->fetchColumn() > 0) return; // Bereits migriert
        $stmt = $db->query("SELECT setting_key, setting_value FROM settings");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $ins = $db->prepare("INSERT IGNORE INTO einheit_settings (einheit_id, setting_key, setting_value) VALUES (?, ?, ?)");
        foreach ($rows as $r) {
            $ins->execute([$amern_id, $r['setting_key'], $r['setting_value']]);
        }
        // E-Mail-Benachrichtigungen aus users-Tabelle übernehmen
        try {
            $stmt = $db->query("SELECT id FROM users WHERE email_notifications = 1 AND is_active = 1");
            $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $ins->execute([$amern_id, 'reservation_notification_user_ids', json_encode(array_map('intval', $ids))]);
        } catch (Exception $e) {}
        // Atemschutz-Benachrichtigungen aus users-Tabelle übernehmen
        try {
            $db->exec("ALTER TABLE users ADD COLUMN atemschutz_notifications TINYINT(1) DEFAULT 0");
        } catch (Exception $e) {}
        try {
            $stmt = $db->query("SELECT id FROM users WHERE atemschutz_notifications = 1");
            $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $ins->execute([$amern_id, 'atemschutz_notification_user_ids', json_encode(array_map('intval', $ids))]);
        } catch (Exception $e) {}
    } catch (Exception $e) {}
}

/**
 * Lädt Divera-Konfiguration für eine Einheit aus einheit_settings.
 * Gibt ['access_key' => ..., 'api_base_url' => ..., 'has_divera_row' => bool] zurück.
 * has_divera_row: true wenn einheit_settings eine Zeile für divera_access_key hat (auch leer).
 */
function load_divera_config_for_einheit($db, $einheit_id) {
    if ($einheit_id <= 0) return [];
    $cfg = ['has_divera_row' => false];
    try {
        ensure_einheit_settings_table($db);
        $stmt = $db->prepare("SELECT setting_key, setting_value FROM einheit_settings WHERE einheit_id = ? AND setting_key IN ('divera_access_key', 'divera_api_base_url')");
        $stmt->execute([$einheit_id]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($row['setting_key'] === 'divera_access_key') {
                $cfg['has_divera_row'] = true;
                $val = trim((string)$row['setting_value']);
                $cfg['access_key'] = $val; // auch leer, wenn explizit gelöscht
            }
            if ($row['setting_key'] === 'divera_api_base_url' && trim((string)$row['setting_value']) !== '') {
                $cfg['api_base_url'] = rtrim(trim((string)$row['setting_value']), '/');
            }
        }
    } catch (Exception $e) {}
    return $cfg;
}

/**
 * Wendet Divera-Konfiguration einer Einheit auf die globale $divera_config an.
 * - Zeile mit Key: diesen verwenden
 * - Zeile mit leerem Key + Legacy: globalen Key behalten (Migration kann leere Zeile erzeugt haben)
 * - Zeile mit leerem Key + andere Einheit: Key leeren (explizit gelöscht)
 * - Keine Zeile + Legacy: globalen Key behalten
 * - Keine Zeile + andere Einheit: Key leeren
 * Legacy = Amern (name) oder Einheit-ID 1 (Fallback)
 */
function apply_divera_config_for_einheit($db, $einheit_id) {
    global $divera_config;
    $cfg = load_divera_config_for_einheit($db, $einheit_id);
    $amern_id = get_einheit_amern_id($db);
    $is_legacy = ($amern_id > 0 && $amern_id === (int)$einheit_id) || ((int)$einheit_id === 1 && $amern_id <= 0);
    $has_key = isset($cfg['access_key']) && $cfg['access_key'] !== '';
    if ($has_key) {
        $divera_config['access_key'] = $cfg['access_key'];
    } elseif ($cfg['has_divera_row'] && $is_legacy) {
        // Legacy mit leerer Zeile: global behalten
    } elseif ($cfg['has_divera_row'] || !$is_legacy) {
        $divera_config['access_key'] = '';
    }
    if (!empty($cfg['api_base_url'])) {
        $divera_config['api_base_url'] = $cfg['api_base_url'];
    }
}

/**
 * Stellt sicher, dass einheit_id in den relevanten Tabellen existiert und migriert bestehende Daten.
 * Bestehende Einträge werden der Einheit Amern zugeordnet (falls vorhanden), sonst der ersten Einheit.
 */
function ensure_einheit_id_in_tables($db) {
    $default_einheit = get_einheit_amern_id($db);
    if ($default_einheit <= 0) {
        try {
            $row = $db->query("SELECT id FROM einheiten ORDER BY sort_order, name LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            $default_einheit = $row ? (int)$row['id'] : 0;
        } catch (Exception $e) {}
    }
    foreach (['vehicles', 'members', 'atemschutz_traeger', 'users'] as $table) {
        try {
            $db->exec("ALTER TABLE $table ADD COLUMN einheit_id INT NULL");
        } catch (Exception $e) {}
        if ($default_einheit > 0) {
            try {
                $db->exec("UPDATE $table SET einheit_id = $default_einheit WHERE einheit_id IS NULL");
            } catch (Exception $e) {}
        }
    }
    try {
        $db->exec("ALTER TABLE atemschutz_entries ADD COLUMN einheit_id INT NULL");
    } catch (Exception $e) {}
    try {
        $db->exec("ALTER TABLE reservations ADD COLUMN einheit_id INT NULL");
    } catch (Exception $e) {}
}
