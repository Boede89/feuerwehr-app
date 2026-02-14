<?php
/**
 * Einstellungen für das Anwesenheitsliste-Formular.
 * Alle Felder und deren Optionen können hier verwaltet werden.
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

// Standardwerte
$defaults = [
    'alarmierung_optionen' => ['Telefon', 'DME Löschzug', 'DME Kleinhilfe', 'Sirene'],
    'klassifizierung_optionen' => ['Grossbrand', 'Mittelbrand', 'Kleinbrand', 'Gelöschtes Feuer', 'Gefahrenmeldeanlage', 'Menschen in Notlage', 'Tiere in Notlage', 'Verkehrsunfall', 'Techn. Hilfeleistung', 'Wasserrettung', 'CBRN-Einsatz', 'Unterstützung RD', 'Sonstiger Einsatz', 'Fehlalarm', 'Böswill. Alarm'],
    'personenschaeden_optionen' => ['Ja', 'Nein', 'Person gerettet', 'Person verstorben'],
    'kostenpflichtiger_optionen' => ['Ja', 'Nein'],
    'brandwache_optionen' => ['Ja', 'Nein'],
];

foreach ($defaults as $key => $opts) {
    $k = 'anwesenheitsliste_' . $key;
    if (empty($settings[$k])) {
        $settings[$k] = json_encode($opts);
    }
}

$alarmierung = json_decode($settings['anwesenheitsliste_alarmierung_optionen'] ?? '[]', true) ?: $defaults['alarmierung_optionen'];
$klassifizierung = json_decode($settings['anwesenheitsliste_klassifizierung_optionen'] ?? '[]', true) ?: $defaults['klassifizierung_optionen'];
$personenschaeden = json_decode($settings['anwesenheitsliste_personenschaeden_optionen'] ?? '[]', true) ?: $defaults['personenschaeden_optionen'];
$kostenpflichtiger = json_decode($settings['anwesenheitsliste_kostenpflichtiger_optionen'] ?? '[]', true) ?: $defaults['kostenpflichtiger_optionen'];
$brandwache = json_decode($settings['anwesenheitsliste_brandwache_optionen'] ?? '[]', true) ?: $defaults['brandwache_optionen'];

// Feld-Labels (optional anpassbar)
$feld_labels_raw = $settings['anwesenheitsliste_feld_labels'] ?? '';
$feld_labels = [];
if ($feld_labels_raw !== '') {
    $dec = json_decode($feld_labels_raw, true);
    $feld_labels = is_array($dec) ? $dec : [];
}

$feld_defaults = [
    'uhrzeit_von' => 'Uhrzeit von',
    'uhrzeit_bis' => 'Uhrzeit bis',
    'einsatzleiter' => 'Einsatzleiter',
    'alarmierung_durch' => 'Alarmierung durch',
    'einsatzstelle' => 'Einsatzstelle',
    'objekt' => 'Objekt',
    'eigentuemer' => 'Eigentümer',
    'geschaedigter' => 'Geschädigter',
    'klassifizierung' => 'Klassifizierung / Stichwörter',
    'kostenpflichtiger_einsatz' => 'Kostenpflichtiger Einsatz',
    'personenschaeden' => 'Personenschäden',
    'brandwache' => 'Brandwache',
    'bemerkung' => 'Einsatzkurzbericht',
];

// Sichtbarkeit pro Feld
$sichtbar_raw = $settings['anwesenheitsliste_felder_sichtbar'] ?? '';
$felder_sichtbar = [];
if ($sichtbar_raw !== '') {
    $dec = json_decode($sichtbar_raw, true);
    $felder_sichtbar = is_array($dec) ? $dec : [];
}
foreach (array_keys($feld_defaults) as $fk) {
    if (!isset($felder_sichtbar[$fk])) {
        $felder_sichtbar[$fk] = '1';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Ungültiger Sicherheitstoken.';
    } else {
        try {
            // Optionen aus Textareas (eine Option pro Zeile)
            $alarmierung_post = array_filter(array_map('trim', explode("\n", $_POST['alarmierung_optionen'] ?? '')));
            $klassifizierung_post = array_filter(array_map('trim', explode("\n", $_POST['klassifizierung_optionen'] ?? '')));
            $personenschaeden_post = array_filter(array_map('trim', explode("\n", $_POST['personenschaeden_optionen'] ?? '')));
            $kostenpflichtiger_post = array_filter(array_map('trim', explode("\n", $_POST['kostenpflichtiger_optionen'] ?? '')));
            $brandwache_post = array_filter(array_map('trim', explode("\n", $_POST['brandwache_optionen'] ?? '')));

            if (empty($alarmierung_post)) $alarmierung_post = $defaults['alarmierung_optionen'];
            if (empty($klassifizierung_post)) $klassifizierung_post = $defaults['klassifizierung_optionen'];
            if (empty($personenschaeden_post)) $personenschaeden_post = $defaults['personenschaeden_optionen'];
            if (empty($kostenpflichtiger_post)) $kostenpflichtiger_post = $defaults['kostenpflichtiger_optionen'];
            if (empty($brandwache_post)) $brandwache_post = $defaults['brandwache_optionen'];

            $upsert = $db->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');

            $upsert->execute(['anwesenheitsliste_alarmierung_optionen', json_encode(array_values($alarmierung_post))]);
            $upsert->execute(['anwesenheitsliste_klassifizierung_optionen', json_encode(array_values($klassifizierung_post))]);
            $upsert->execute(['anwesenheitsliste_personenschaeden_optionen', json_encode(array_values($personenschaeden_post))]);
            $upsert->execute(['anwesenheitsliste_kostenpflichtiger_optionen', json_encode(array_values($kostenpflichtiger_post))]);
            $upsert->execute(['anwesenheitsliste_brandwache_optionen', json_encode(array_values($brandwache_post))]);

            // Feld-Labels
            $labels = [];
            foreach ($feld_defaults as $fk => $def) {
                $v = trim($_POST['label_' . $fk] ?? '');
                $labels[$fk] = $v !== '' ? $v : $def;
            }
            $upsert->execute(['anwesenheitsliste_feld_labels', json_encode($labels)]);

            // Sichtbarkeit
            $sichtbar = [];
            foreach ($feld_defaults as $fk => $def) {
                $sichtbar[$fk] = isset($_POST['sichtbar_' . $fk]) ? '1' : '0';
            }
            $upsert->execute(['anwesenheitsliste_felder_sichtbar', json_encode($sichtbar)]);

            $message = 'Anwesenheitsliste-Einstellungen gespeichert.';
            $alarmierung = $alarmierung_post;
            $klassifizierung = $klassifizierung_post;
            $personenschaeden = $personenschaeden_post;
            $kostenpflichtiger = $kostenpflichtiger_post;
            $brandwache = $brandwache_post;
            $feld_labels = $labels;
            $felder_sichtbar = $sichtbar;
        } catch (Exception $e) {
            $error = 'Fehler beim Speichern: ' . $e->getMessage();
        }
    }
}

function opt($arr) {
    return implode("\n", $arr);
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Anwesenheitsliste – Einstellungen</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
    <div class="container-fluid">
        <a class="navbar-brand" href="../index.php"><i class="fas fa-fire"></i> Feuerwehr App</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav me-auto">
                <?php echo get_admin_navigation(); ?>
            </ul>
        </div>
    </div>
</nav>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h3 mb-0"><i class="fas fa-clipboard-list"></i> Anwesenheitsliste – Einstellungen</h1>
        <a href="settings.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left"></i> Zurück zu Einstellungen</a>
    </div>
    <?php if ($message) echo show_success($message); ?>
    <?php if ($error) echo show_error($error); ?>

    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">

        <div class="card mb-4">
            <div class="card-header"><i class="fas fa-list"></i> Auswahl-Optionen (eine Option pro Zeile)</div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Alarmierung durch</label>
                        <textarea class="form-control font-monospace" name="alarmierung_optionen" rows="5"><?php echo htmlspecialchars(opt($alarmierung)); ?></textarea>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Klassifizierung / Stichwörter</label>
                        <textarea class="form-control font-monospace" name="klassifizierung_optionen" rows="10"><?php echo htmlspecialchars(opt($klassifizierung)); ?></textarea>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Personenschäden</label>
                        <textarea class="form-control font-monospace" name="personenschaeden_optionen" rows="5"><?php echo htmlspecialchars(opt($personenschaeden)); ?></textarea>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Kostenpflichtiger Einsatz (Radio-Optionen)</label>
                        <textarea class="form-control font-monospace" name="kostenpflichtiger_optionen" rows="3"><?php echo htmlspecialchars(opt($kostenpflichtiger)); ?></textarea>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Brandwache (Radio-Optionen)</label>
                        <textarea class="form-control font-monospace" name="brandwache_optionen" rows="3"><?php echo htmlspecialchars(opt($brandwache)); ?></textarea>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header"><i class="fas fa-tags"></i> Feld-Bezeichnungen und Sichtbarkeit</div>
            <div class="card-body">
                <p class="text-muted small">Passen Sie die Anzeigenamen der Felder an und legen Sie fest, welche Felder im Formular sichtbar sind.</p>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Feld</th>
                                <th>Bezeichnung (Label)</th>
                                <th>Sichtbar</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($feld_defaults as $fk => $def):
                                $label_val = $feld_labels[$fk] ?? $def;
                                $checked = ($felder_sichtbar[$fk] ?? '1') === '1' ? ' checked' : '';
                            ?>
                            <tr>
                                <td><code><?php echo htmlspecialchars($fk); ?></code></td>
                                <td><input type="text" class="form-control form-control-sm" name="label_<?php echo htmlspecialchars($fk); ?>" value="<?php echo htmlspecialchars($label_val); ?>" placeholder="<?php echo htmlspecialchars($def); ?>"></td>
                                <td><input type="checkbox" class="form-check-input" name="sichtbar_<?php echo htmlspecialchars($fk); ?>" value="1"<?php echo $checked; ?>></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Speichern</button>
    </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
