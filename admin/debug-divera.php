<?php
require_once __DIR__ . '/../includes/debug-auth.php';
/**
 * Debug: Divera-Konfiguration prüfen (nur Superadmin).
 */

$einheit_id = isset($_GET['einheit_id']) ? (int)$_GET['einheit_id'] : 1;
$_SESSION['current_einheit_id'] = $einheit_id;

require_once __DIR__ . '/../config/divera.php';
require_once __DIR__ . '/../includes/einheit-settings-helper.php';

$einheit_settings_key = null;
try {
    $stmt = $db->prepare("SELECT setting_value FROM einheit_settings WHERE einheit_id = ? AND setting_key = 'divera_access_key'");
    $stmt->execute([$einheit_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $einheit_settings_key = $row ? $row['setting_value'] : '(keine Zeile)';
} catch (Exception $e) {
    $einheit_settings_key = 'Fehler: ' . $e->getMessage();
}

$settings_key = null;
try {
    $stmt = $db->query("SELECT setting_value FROM settings WHERE setting_key = 'divera_access_key' LIMIT 1");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $settings_key = $row ? $row['setting_value'] : '(keine Zeile)';
} catch (Exception $e) {
    $settings_key = 'Fehler: ' . $e->getMessage();
}

$divera_local_exists = is_file(__DIR__ . '/../config/divera.local.php');
$divera_local_content = '';
if ($divera_local_exists) {
    $divera_local_content = file_get_contents(__DIR__ . '/../config/divera.local.php');
    $divera_local_content = preg_replace('/[\'\"][^\'\"]{10,}[\'\"]/', "'***KEY***'", $divera_local_content);
}

$has_key = !empty(trim((string)($divera_config['access_key'] ?? '')));
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Divera Debug</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<div class="container mt-4">
    <h1>Divera Debug</h1>
    <p class="text-muted">Prüft die Divera-Konfiguration für Einheit <?php echo (int)$einheit_id; ?>.</p>
    <div class="card mb-3">
        <div class="card-header">Aktueller Zustand</div>
        <div class="card-body">
            <table class="table table-sm">
                <tr><th>get_current_einheit_id()</th><td><?php echo (int)$einheit_id; ?></td></tr>
                <tr><th>divera_config['access_key']</th><td><?php echo $has_key ? 'Key vorhanden (' . strlen(trim($divera_config['access_key'])) . ' Zeichen)' : '<span class="text-danger">leer</span>'; ?></td></tr>
                <tr><th>einheit_settings (einheit_id=<?php echo $einheit_id; ?>)</th><td><?php echo $einheit_settings_key === '(keine Zeile)' ? '<span class="text-warning">keine Zeile</span>' : (trim((string)$einheit_settings_key) !== '' ? 'Key vorhanden' : 'leer (gelöscht)'); ?></td></tr>
                <tr><th>settings (global)</th><td><?php echo $settings_key === '(keine Zeile)' ? 'keine Zeile' : (trim((string)$settings_key) !== '' ? 'Key vorhanden' : 'leer'); ?></td></tr>
                <tr><th>config/divera.local.php</th><td><?php echo $divera_local_exists ? 'existiert' : 'existiert nicht'; ?></td></tr>
            </table>
        </div>
    </div>
    <?php if ($divera_local_exists): ?>
    <div class="card mb-3">
        <div class="card-header">divera.local.php (Key maskiert)</div>
        <div class="card-body">
            <pre class="bg-light p-2 rounded small"><?php echo htmlspecialchars($divera_local_content); ?></pre>
            <p class="small text-muted mb-0">Öffnen Sie <code>config/divera.local.php</code> im Editor, um den Key zu prüfen oder zu entfernen.</p>
        </div>
    </div>
    <?php endif; ?>
    <p><a href="?einheit_id=1">Einheit 1</a> | <a href="?einheit_id=2">Einheit 2</a></p>
    <p><a href="settings-global.php">← Zurück zu Einstellungen</a></p>
</div>
</body>
</html>
