<?php
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Ungültiger Sicherheitstoken.';
    } else {
        try {
            $divera_dienstplan_default_group_id = trim((string)($_POST['divera_dienstplan_default_group_id'] ?? ''));
            $stmt = $db->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
            $stmt->execute(['divera_dienstplan_default_group_id', $divera_dienstplan_default_group_id]);
            $message = 'Dienstplan-Einstellungen gespeichert.';
            $settings['divera_dienstplan_default_group_id'] = $divera_dienstplan_default_group_id;
        } catch (Exception $e) {
            $error = 'Fehler beim Speichern: ' . $e->getMessage();
        }
    }
}

$divera_groups = [];
if (!empty($settings['divera_reservation_groups'])) {
    $dec = json_decode($settings['divera_reservation_groups'], true);
    $divera_groups = is_array($dec) ? $dec : [];
}
$default_group_id = trim((string)($settings['divera_dienstplan_default_group_id'] ?? ''));
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dienstplan Einstellungen</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
    <div class="container-fluid">
        <a class="navbar-brand" href="../index.php"><i class="fas fa-fire"></i> Feuerwehr App</a>
        <div class="d-flex ms-auto align-items-center">
            <?php $admin_menu_in_navbar = true; include __DIR__ . '/includes/admin-menu.inc.php'; ?>
        </div>
    </div>
</nav>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h3 mb-0"><i class="fas fa-calendar-alt"></i> Dienstplan Einstellungen</h1>
        <?php
        $return_formularcenter = isset($_GET['return']) && $_GET['return'] === 'formularcenter';
        $back_url = $return_formularcenter ? 'settings-formularcenter.php?tab=dienstplan' : 'settings.php';
        $back_label = $return_formularcenter ? 'Zurück zu Formularcenter' : 'Zurück zu Einstellungen';
        $back_target = $return_formularcenter ? ' target="_parent"' : '';
        ?>
        <a href="<?php echo htmlspecialchars($back_url); ?>" class="btn btn-outline-secondary"<?php echo $back_target; ?>><i class="fas fa-arrow-left"></i> <?php echo htmlspecialchars($back_label); ?></a>
    </div>
    <?php if ($message) echo show_success($message); ?>
    <?php if ($error) echo show_error($error); ?>

    <div class="card">
        <div class="card-header"><i class="fas fa-calendar-plus"></i> Divera 24/7 Export</div>
        <div class="card-body">
            <p class="text-muted small">Beim Export von Dienstplan-Terminen nach Divera wird diese Gruppe standardmäßig ausgewählt. Gruppen werden in den <a href="settings-divera.php">Divera-Einstellungen</a> definiert.</p>
            <form method="POST">
                <div class="mb-3">
                    <label class="form-label">Standard-Empfänger-Gruppe (Divera)</label>
                    <select class="form-select" name="divera_dienstplan_default_group_id" style="max-width: 400px;">
                        <option value="">– Keine Vorauswahl –</option>
                        <?php foreach ($divera_groups as $g):
                            $gid = (int)($g['id'] ?? 0);
                            $gval = $gid > 0 ? (string)$gid : '0';
                            $gname = htmlspecialchars($g['name'] ?? ($gid > 0 ? 'Gruppe ' . $gid : 'Alle des Standortes'));
                            $glabel = $gid > 0 ? $gname . ' (ID: ' . $gid . ')' : $gname . ' (keine Gruppen-ID)';
                        ?>
                        <option value="<?php echo $gval; ?>" <?php echo $default_group_id === $gval ? 'selected' : ''; ?>>
                            <?php echo $glabel; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Diese Gruppe wird beim Export nach Divera standardmäßig ausgewählt. Kann beim Export geändert werden.</div>
                </div>
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Speichern</button>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
