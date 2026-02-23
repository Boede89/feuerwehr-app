<?php
/**
 * Divera 24/7 – API-Konfiguration für Termin-Webhook
 *
 * Access Key: Einheitenspezifisch in Einstellungen → Einheit → Divera.
 * Kein globaler Key mehr – nur einheit_settings.
 * API-Dokumentation: https://api.divera247.com/ (v2/event – Termin erstellen)
 */
$divera_config = [
    'api_base_url' => 'https://app.divera247.com',
    'access_key'   => '',
];

$eid = (function_exists('get_current_einheit_id')) ? get_current_einheit_id() : null;

if (!empty($db) && $eid > 0) {
    // Einheit ausgewählt: NUR einheit_settings – kein divera.local, kein settings, kein users
    if (function_exists('apply_divera_config_for_einheit')) {
        require_once dirname(__DIR__) . '/includes/einheit-settings-helper.php';
        apply_divera_config_for_einheit($db, $eid);
    }
} elseif (!empty($db)) {
    // Keine Einheit: Legacy-Fallback (settings, divera.local, users)
    if (is_file(__DIR__ . '/divera.local.php')) {
        include __DIR__ . '/divera.local.php';
    }
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
    } catch (Exception $e) {}
}
