<?php
/**
 * Divera 24/7 – API-Konfiguration für Termin-Webhook
 *
 * Access Key: In Divera 24/7 unter Verwaltung → API-Verwaltung / Zugangsdaten erzeugen.
 * Optional: config/divera.local.php anlegen (siehe config/divera.local.php.example),
 * dort den echten Key eintragen (divera.local.php wird von Git ignoriert).
 * API-Dokumentation: https://api.divera247.com/ (v2/event – Termin erstellen)
 */
$divera_config = [
    'api_base_url' => 'https://app.divera247.com',
    'access_key'   => '', // z.B. 'Ihr-Access-Key-von-Divera'
];

// Lokale Overrides (z.B. config/divera.local.php mit $divera_config['access_key'] = '...';)
if (is_file(__DIR__ . '/divera.local.php')) {
    include __DIR__ . '/divera.local.php';
}

// Globale Einstellungen (Legacy) aus settings
if (!empty($db)) {
    try {
        $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('divera_access_key', 'divera_api_base_url')");
        $stmt->execute();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($row['setting_key'] === 'divera_access_key' && trim((string) $row['setting_value']) !== '') {
                $divera_config['access_key'] = trim((string) $row['setting_value']);
            }
            if ($row['setting_key'] === 'divera_api_base_url' && trim((string) $row['setting_value']) !== '') {
                $divera_config['api_base_url'] = rtrim(trim((string) $row['setting_value']), '/');
            }
        }
    } catch (Exception $e) {
        // Tabelle/Spalte fehlt oder DB nicht erreichbar – Datei-/Standardwerte behalten
    }
    // Einheitenspezifische Einstellungen haben Vorrang (wenn aktuelle Einheit gesetzt)
    if (function_exists('get_current_einheit_id') && function_exists('apply_divera_config_for_einheit')) {
        $eid = get_current_einheit_id();
        if ($eid > 0) {
            require_once dirname(__DIR__) . '/includes/einheit-settings-helper.php';
            apply_divera_config_for_einheit($db, $eid);
        }
    }
}
