<?php
/**
 * Druck-Diagnose: Zeigt Konfiguration und hilft bei der Fehlersuche.
 * Nur für Admins. Aufruf: api/print-debug.php?einheit_id=1
 */
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/einheit-settings-helper.php';
require_once __DIR__ . '/print-helper.inc.php';

header('Content-Type: application/json; charset=UTF-8');

if (!isset($_SESSION['user_id']) || !hasAdminPermission()) {
    echo json_encode(['success' => false, 'message' => 'Zugriff verweigert']);
    exit;
}

$einheit_id = isset($_GET['einheit_id']) ? (int)$_GET['einheit_id'] : 0;
if ($einheit_id <= 0) {
    $einheit_id = function_exists('get_current_einheit_id') ? get_current_einheit_id() : 0;
}

$config = print_get_printer_config($db, $einheit_id);
$settings = $einheit_id > 0 ? load_settings_for_einheit($db, $einheit_id) : [];

$smtp_ok = !empty(trim($settings['smtp_host'] ?? '')) && !empty(trim($settings['smtp_username'] ?? '')) && !empty(trim($settings['smtp_password'] ?? ''));
$email_ok = !empty($config['printer_email_recipient']);
$cups_ok = ($config['printer_mode'] ?? '') === 'cups' && !empty(trim($config['printer_cups_name'] ?? ''));

$out = [
    'success' => true,
    'einheit_id' => $einheit_id,
    'printer_mode' => $config['printer_mode'] ?? 'email',
    'cups_server' => $config['printer_cups_server'] ?? '',
    'cups_server_env' => getenv('CUPS_SERVER') ?: '(nicht gesetzt)',
    'printer_cups_name' => $config['printer_cups_name'] ?: '(nicht gesetzt)',
    'printer_email_recipient' => $config['printer_email_recipient'] ?: '(nicht gesetzt)',
    'printer_email_subject' => $config['printer_email_subject'] ?? 'DRUCK',
    'cloud_url' => $config['cloud_url'] ?: '(nicht gesetzt)',
    'smtp_konfiguriert' => $smtp_ok,
    'smtp_host' => !empty(trim($settings['smtp_host'] ?? '')) ? '(gesetzt)' : '(fehlt)',
    'smtp_username' => !empty(trim($settings['smtp_username'] ?? '')) ? '(gesetzt)' : '(fehlt)',
    'smtp_password' => !empty(trim($settings['smtp_password'] ?? '')) ? '(gesetzt)' : '(fehlt)',
    'smtp_port' => trim($settings['smtp_port'] ?? '') ?: '587',
    'e_mail_druck_moeglich' => $email_ok && $smtp_ok,
    'hinweis' => [],
];

if ($email_ok && !$smtp_ok) {
    $out['hinweis'][] = 'E-Mail-Postfach ist hinterlegt, aber SMTP der Einheit fehlt. Bitte Einstellungen → Einheit wählen → SMTP-Tab ausfüllen (Host, Benutzer, Passwort).';
}
if (!$email_ok && !$cups_ok && empty($config['cloud_url']) && $einheit_id > 0) {
    $out['hinweis'][] = 'Kein Drucker konfiguriert. Bitte Einstellungen → Einheit wählen → Drucker-Tab (CUPS oder E-Mail).';
}
if ($einheit_id <= 0) {
    $out['hinweis'][] = 'Keine Einheit ausgewählt. Im Formularcenter eine Einheit im Filter wählen (Dropdown oben) oder Einstellungen → Einheit auswählen.';
}
if ($smtp_ok && $email_ok) {
    $out['hinweis'][] = 'Konfiguration sieht korrekt aus. Bei weiterem Fehler: PHP error_log prüfen (SMTP-Verbindungsfehler werden dort geloggt).';
}

// Benutzer-Info für Einheit-Wechsel
$out['can_switch_einheit'] = function_exists('can_switch_einheit') && can_switch_einheit();
$out['is_superadmin'] = is_superadmin();
$out['has_admin_permission'] = hasAdminPermission();
$out['user_einheiten_anzahl'] = count(function_exists('get_user_einheiten') ? get_user_einheiten() : []);

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
