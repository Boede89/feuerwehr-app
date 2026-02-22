<?php
/**
 * Einstellungen für das Mängelbericht-Formular.
 * E-Mail-Versand nach Absenden.
 */
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('Location: ../login.php');
    exit;
}
if (!hasAdminPermission()) {
    header('Location: ../login.php?error=access_denied');
    exit;
}

$message = '';
$error = '';
$form_key = 'maengelbericht';

$settings = [];
try {
    $stmt = $db->prepare('SELECT setting_key, setting_value FROM settings');
    $stmt->execute();
    foreach ($stmt->fetchAll() as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Exception $e) {
    $error = 'Fehler beim Laden: ' . $e->getMessage();
}

$users_for_email = [];
try {
    $stmt = $db->query("SELECT id, first_name, last_name, email FROM users WHERE is_active = 1 AND email IS NOT NULL AND email != '' ORDER BY first_name, last_name");
    $users_for_email = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$email_auto = ($settings[$form_key . '_email_auto'] ?? '0') === '1';
$email_recipients = json_decode($settings[$form_key . '_email_recipients'] ?? '[]', true) ?: [];
$email_manual = trim($settings[$form_key . '_email_manual'] ?? '');
$standort_options = ['GH Amern', 'GH Hehler', 'GH Waldniel'];
$mangel_an_options = ['Gebäude', 'Fahrzeug', 'Gerät', 'PSA'];
$standort_default = trim($settings[$form_key . '_standort_default'] ?? '');
if (!in_array($standort_default, $standort_options)) $standort_default = $standort_options[0];
$mangel_an_default = trim($settings[$form_key . '_mangel_an_default'] ?? '');
if (!in_array($mangel_an_default, $mangel_an_options)) $mangel_an_default = $mangel_an_options[0];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Ungültiger Sicherheitstoken.';
    } else {
        try {
            $stmt = $db->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
            $stmt->execute([$form_key . '_email_auto', isset($_POST['email_auto']) ? '1' : '0']);
            $recipients = array_filter(array_map('intval', $_POST['email_recipients'] ?? []));
            $stmt->execute([$form_key . '_email_recipients', json_encode(array_values($recipients))]);
            $manual = trim($_POST['email_manual'] ?? '');
            $stmt->execute([$form_key . '_email_manual', $manual]);
            $standortDef = trim($_POST['standort_default'] ?? '');
            if (!in_array($standortDef, ['GH Amern', 'GH Hehler', 'GH Waldniel'])) $standortDef = 'GH Amern';
            $stmt->execute([$form_key . '_standort_default', $standortDef]);
            $mangelAnDef = trim($_POST['mangel_an_default'] ?? '');
            if (!in_array($mangelAnDef, ['Gebäude', 'Fahrzeug', 'Gerät', 'PSA'])) $mangelAnDef = 'Gebäude';
            $stmt->execute([$form_key . '_mangel_an_default', $mangelAnDef]);
            $email_auto = isset($_POST['email_auto']);
            $email_recipients = $recipients;
            $email_manual = $manual;
            $standort_default = $standortDef;
            $mangel_an_default = $mangelAnDef;
            $message = 'Einstellungen gespeichert.';
        } catch (Exception $e) {
            $error = 'Fehler: ' . $e->getMessage();
        }
    }
}

$return_formularcenter = isset($_GET['return']) && $_GET['return'] === 'formularcenter';
$back_url = $return_formularcenter ? 'settings-formularcenter.php?tab=forms' : 'settings.php';
$back_target = $return_formularcenter ? ' target="_parent"' : '';
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mängelbericht – Einstellungen</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
    <div class="container-fluid">
        <a class="navbar-brand" href="../index.php"><i class="fas fa-fire"></i> Feuerwehr App</a>
        <div class="navbar-nav ms-auto d-flex align-items-center">
            <?php $admin_menu_in_navbar = true; include __DIR__ . '/includes/admin-menu.inc.php'; ?>
        </div>
    </div>
</nav>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h3 mb-0"><i class="fas fa-exclamation-triangle"></i> Mängelbericht – Einstellungen</h1>
        <a href="<?php echo htmlspecialchars($back_url); ?>" class="btn btn-outline-secondary"<?php echo $back_target; ?>><i class="fas fa-arrow-left"></i> Zurück</a>
    </div>
    <?php if ($message) echo show_success($message); ?>
    <?php if ($error) echo show_error($error); ?>

    <form method="POST" class="card mb-4" id="settingsForm">
        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
        <div class="card-header"><i class="fas fa-cog"></i> Mängelbericht – Standardwerte</div>
        <div class="card-body">
            <div class="mb-3">
                <label class="form-label" for="standort_default">Standard-Standort</label>
                <select class="form-select" name="standort_default" id="standort_default">
                    <?php foreach ($standort_options as $opt): ?>
                    <option value="<?php echo htmlspecialchars($opt); ?>" <?php echo $opt === $standort_default ? 'selected' : ''; ?>><?php echo htmlspecialchars($opt); ?></option>
                    <?php endforeach; ?>
                </select>
                <small class="text-muted">Wird beim Ausfüllen des Mängelberichts vorausgewählt.</small>
            </div>
            <div class="mb-3">
                <label class="form-label" for="mangel_an_default">Standard Mangel/Wartung an</label>
                <select class="form-select" name="mangel_an_default" id="mangel_an_default">
                    <?php foreach ($mangel_an_options as $opt): ?>
                    <option value="<?php echo htmlspecialchars($opt); ?>" <?php echo $opt === $mangel_an_default ? 'selected' : ''; ?>><?php echo htmlspecialchars($opt); ?></option>
                    <?php endforeach; ?>
                </select>
                <small class="text-muted">Wird beim Ausfüllen des Mängelberichts vorausgewählt.</small>
            </div>
        </div>
        <div class="card-header"><i class="fas fa-envelope"></i> E-Mail-Versand nach Absenden</div>
        <div class="card-body">
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" name="email_auto" id="email_auto" value="1" <?php echo $email_auto ? 'checked' : ''; ?>>
                <label class="form-check-label" for="email_auto">Automatischer E-Mail-Versand aktiv – Bericht wird nach dem Absenden als PDF per E-Mail gesendet</label>
            </div>
            <div id="email_recipients_wrap" style="display: <?php echo $email_auto ? 'block' : 'none'; ?>;">
                <label class="form-label">Empfänger (Personen auswählen)</label>
                <select class="form-select" name="email_recipients[]" multiple size="6">
                    <?php foreach ($users_for_email as $u): ?>
                    <option value="<?php echo (int)$u['id']; ?>" <?php echo in_array((int)$u['id'], $email_recipients) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($u['first_name'] . ' ' . $u['last_name'] . ' (' . $u['email'] . ')'); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <small class="text-muted d-block mt-1">Strg+Klick für Mehrfachauswahl</small>
                <label class="form-label mt-3">Zusätzliche E-Mail-Adressen (manuell)</label>
                <textarea class="form-control" name="email_manual" rows="2" placeholder="email1@beispiel.de&#10;email2@beispiel.de"><?php echo htmlspecialchars($email_manual); ?></textarea>
                <small class="text-muted d-block mt-1">Eine Adresse pro Zeile</small>
            </div>
        </div>
        <div class="card-footer">
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Speichern</button>
        </div>
    </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.getElementById('email_auto').addEventListener('change', function() {
    document.getElementById('email_recipients_wrap').style.display = this.checked ? 'block' : 'none';
});
</script>
</body>
</html>
