<?php
/**
 * Einstellungen für das Anwesenheitsliste-Formular.
 * Felder können hinzugefügt, bearbeitet, gelöscht und neu angeordnet werden.
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

// Standard-Felder (für Migration von alter Konfiguration)
$standard_felder = [
    ['id' => 'uhrzeit_von', 'label' => 'Uhrzeit von', 'type' => 'time', 'options' => [], 'visible' => true, 'position' => 1],
    ['id' => 'uhrzeit_bis', 'label' => 'Uhrzeit bis', 'type' => 'time', 'options' => [], 'visible' => true, 'position' => 2],
    ['id' => 'einsatzleiter', 'label' => 'Einsatzleiter', 'type' => 'einsatzleiter', 'options' => [], 'visible' => true, 'position' => 3],
    ['id' => 'alarmierung_durch', 'label' => 'Alarmierung durch', 'type' => 'select', 'options' => ['Telefon', 'DME Löschzug', 'DME Kleinhilfe', 'Sirene'], 'visible' => true, 'position' => 4],
    ['id' => 'einsatzstelle', 'label' => 'Einsatzstelle', 'type' => 'einsatzstelle', 'options' => [], 'visible' => true, 'position' => 5],
    ['id' => 'objekt', 'label' => 'Objekt', 'type' => 'text', 'options' => [], 'visible' => true, 'position' => 6],
    ['id' => 'eigentuemer', 'label' => 'Eigentümer', 'type' => 'text', 'options' => [], 'visible' => true, 'position' => 7],
    ['id' => 'geschaedigter', 'label' => 'Geschädigter', 'type' => 'text', 'options' => [], 'visible' => true, 'position' => 8],
    ['id' => 'klassifizierung', 'label' => 'Klassifizierung / Stichwörter', 'type' => 'select', 'options' => ['Grossbrand', 'Mittelbrand', 'Kleinbrand', 'Gelöschtes Feuer', 'Gefahrenmeldeanlage', 'Menschen in Notlage', 'Tiere in Notlage', 'Verkehrsunfall', 'Techn. Hilfeleistung', 'Wasserrettung', 'CBRN-Einsatz', 'Unterstützung RD', 'Sonstiger Einsatz', 'Fehlalarm', 'Böswill. Alarm'], 'visible' => true, 'position' => 9],
    ['id' => 'kostenpflichtiger_einsatz', 'label' => 'Kostenpflichtiger Einsatz', 'type' => 'radio', 'options' => ['Ja', 'Nein'], 'visible' => true, 'position' => 10],
    ['id' => 'personenschaeden', 'label' => 'Personenschäden', 'type' => 'select', 'options' => ['Ja', 'Nein', 'Person gerettet', 'Person verstorben'], 'visible' => true, 'position' => 11],
    ['id' => 'brandwache', 'label' => 'Brandwache', 'type' => 'radio', 'options' => ['Ja', 'Nein'], 'visible' => true, 'position' => 12],
    ['id' => 'bemerkung', 'label' => 'Einsatzkurzbericht', 'type' => 'textarea', 'options' => [], 'visible' => true, 'position' => 13],
];

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

// Felder laden (neu: anwesenheitsliste_felder) oder aus alter Konfiguration migrieren
$felder_raw = $settings['anwesenheitsliste_felder'] ?? '';
$felder = [];
if ($felder_raw !== '') {
    $dec = json_decode($felder_raw, true);
    if (is_array($dec) && !empty($dec)) {
        $felder = $dec;
    }
}
if (empty($felder)) {
    // Migration: aus alter Config bauen
    $labels = [];
    $sichtbar = [];
    if (!empty($settings['anwesenheitsliste_feld_labels'])) {
        $l = json_decode($settings['anwesenheitsliste_feld_labels'], true);
        $labels = is_array($l) ? $l : [];
    }
    if (!empty($settings['anwesenheitsliste_felder_sichtbar'])) {
        $s = json_decode($settings['anwesenheitsliste_felder_sichtbar'], true);
        $sichtbar = is_array($s) ? $s : [];
    }
    $opts_map = [
        'alarmierung_durch' => 'anwesenheitsliste_alarmierung_optionen',
        'klassifizierung' => 'anwesenheitsliste_klassifizierung_optionen',
        'personenschaeden' => 'anwesenheitsliste_personenschaeden_optionen',
        'kostenpflichtiger_einsatz' => 'anwesenheitsliste_kostenpflichtiger_optionen',
        'brandwache' => 'anwesenheitsliste_brandwache_optionen',
    ];
    foreach ($standard_felder as $f) {
        $id = $f['id'];
        $opt_key = $opts_map[$id] ?? null;
        $opts = $f['options'];
        if ($opt_key && !empty($settings[$opt_key])) {
            $o = json_decode($settings[$opt_key], true);
            if (is_array($o)) $opts = $o;
        }
        $felder[] = [
            'id' => $id,
            'label' => $labels[$id] ?? $f['label'],
            'type' => $f['type'],
            'options' => $opts,
            'visible' => ($sichtbar[$id] ?? '1') === '1',
            'position' => $f['position'],
        ];
    }
} else {
    usort($felder, function ($a, $b) {
        return ($a['position'] ?? 999) - ($b['position'] ?? 999);
    });
}

$typ_options = ['text' => 'Text', 'textarea' => 'Mehrzeiliger Text', 'time' => 'Uhrzeit', 'select' => 'Auswahlliste', 'radio' => 'Radio (Ja/Nein)', 'einsatzleiter' => 'Einsatzleiter (Mitglieder)', 'einsatzstelle' => 'Einsatzstelle (mit Adress-Suche)'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Ungültiger Sicherheitstoken.';
    } else {
        $action = $_POST['action'] ?? 'save';
        try {
            if ($action === 'delete' && isset($_POST['delete_id'])) {
                $del_id = preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['delete_id']);
                $felder = array_values(array_filter($felder, function ($f) use ($del_id) {
                    return ($f['id'] ?? '') !== $del_id;
                }));
                $message = 'Feld gelöscht.';
            } elseif ($action === 'add') {
                $new_id = 'custom_' . (string)(time() % 100000);
                $new_label = trim($_POST['new_label'] ?? 'Neues Feld');
                $new_type = $_POST['new_type'] ?? 'text';
                $new_opts = array_filter(array_map('trim', explode("\n", $_POST['new_options'] ?? '')));
                if (!in_array($new_type, ['select', 'radio'])) $new_opts = [];
                $max_pos = 0;
                foreach ($felder as $f) {
                    $max_pos = max($max_pos, (int)($f['position'] ?? 0));
                }
                $felder[] = [
                    'id' => $new_id,
                    'label' => $new_label !== '' ? $new_label : 'Neues Feld',
                    'type' => $new_type,
                    'options' => array_values($new_opts),
                    'visible' => true,
                    'position' => $max_pos + 1,
                ];
                $message = 'Feld hinzugefügt.';
            } elseif ($action === 'save') {
                $felder_post = [];
                $ids = array_filter(array_map('trim', $_POST['field_id'] ?? []));
                $labels = $_POST['field_label'] ?? [];
                $types = $_POST['field_type'] ?? [];
                $opts_raw = $_POST['field_options'] ?? [];
                $visible = $_POST['field_visible'] ?? [];
                $positions = $_POST['field_position'] ?? [];
                foreach ($ids as $i => $id) {
                    $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
                    if ($id === '') continue;
                    $label = trim($labels[$i] ?? '');
                    $type = $types[$i] ?? 'text';
                    $opts = array_filter(array_map('trim', explode("\n", $opts_raw[$i] ?? '')));
                    if (!in_array($type, ['select', 'radio'])) $opts = [];
                    $felder_post[] = [
                        'id' => $id,
                        'label' => $label !== '' ? $label : $id,
                        'type' => $type,
                        'options' => array_values($opts),
                        'visible' => isset($visible[$i]),
                        'position' => (int)($positions[$i] ?? $i + 1),
                    ];
                }
                usort($felder_post, function ($a, $b) {
                    return $a['position'] - $b['position'];
                });
                $felder = $felder_post;
                $message = 'Einstellungen gespeichert.';
            }
            $stmt = $db->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
            $stmt->execute(['anwesenheitsliste_felder', json_encode($felder)]);
        } catch (Exception $e) {
            $error = 'Fehler: ' . $e->getMessage();
        }
    }
}

function opt($arr) {
    return is_array($arr) ? implode("\n", $arr) : '';
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
        <h1 class="h3 mb-0"><i class="fas fa-clipboard-list"></i> Anwesenheitsliste – Felder verwalten</h1>
        <a href="settings.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left"></i> Zurück</a>
    </div>
    <?php if ($message) echo show_success($message); ?>
    <?php if ($error) echo show_error($error); ?>

    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="fas fa-plus"></i> Neues Feld hinzufügen</span>
        </div>
        <div class="card-body">
            <form method="POST" class="row g-3">
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                <input type="hidden" name="action" value="add">
                <div class="col-md-3">
                    <label class="form-label">Bezeichnung</label>
                    <input type="text" class="form-control" name="new_label" placeholder="z. B. Wetterlage" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Typ</label>
                    <select class="form-select" name="new_type">
                        <?php foreach ($typ_options as $k => $v): ?>
                        <option value="<?php echo htmlspecialchars($k); ?>"><?php echo htmlspecialchars($v); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Optionen (nur bei Auswahlliste/Radio, eine pro Zeile)</label>
                    <textarea class="form-control font-monospace" name="new_options" rows="2" placeholder="Ja&#10;Nein"></textarea>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-success w-100"><i class="fas fa-plus"></i> Hinzufügen</button>
                </div>
            </form>
        </div>
    </div>

    <form method="POST" id="felderForm">
        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
        <input type="hidden" name="action" value="save" id="formAction">
        <input type="hidden" name="delete_id" value="" id="deleteId">
        <div class="card mb-4">
            <div class="card-header"><i class="fas fa-list"></i> Felder (Reihenfolge, Bezeichnung, Typ, Optionen, Sichtbarkeit)</div>
            <div class="card-body">
                <p class="text-muted small">Ändern Sie Bezeichnungen und Optionen. Deaktivieren Sie „Sichtbar“, um ein Feld auszublenden. Löschen entfernt das Feld dauerhaft.</p>
                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead>
                            <tr>
                                <th style="width: 50px;">Pos.</th>
                                <th>ID</th>
                                <th>Bezeichnung</th>
                                <th>Typ</th>
                                <th>Optionen</th>
                                <th>Sichtbar</th>
                                <th style="width: 80px;">Löschen</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($felder as $i => $f):
                                $id = $f['id'] ?? 'field_' . $i;
                                $label = $f['label'] ?? $id;
                                $type = $f['type'] ?? 'text';
                                $opts = $f['options'] ?? [];
                                $visible = !empty($f['visible']);
                                $pos = (int)($f['position'] ?? $i + 1);
                                $is_custom = strpos($id, 'custom_') === 0;
                            ?>
                            <tr>
                                <td><input type="number" class="form-control form-control-sm" name="field_position[]" value="<?php echo $pos; ?>" min="1" style="width: 55px;"></td>
                                <td><code class="small"><?php echo htmlspecialchars($id); ?></code></td>
                                <td><input type="text" class="form-control form-control-sm" name="field_label[]" value="<?php echo htmlspecialchars($label); ?>" required></td>
                                <td>
                                    <?php if ($is_custom): ?>
                                    <select class="form-select form-select-sm" name="field_type[]" style="min-width: 140px;">
                                        <?php foreach ($typ_options as $k => $v): ?>
                                        <option value="<?php echo htmlspecialchars($k); ?>" <?php echo $type === $k ? 'selected' : ''; ?>><?php echo htmlspecialchars($v); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php else: ?>
                                    <span class="text-muted"><?php echo htmlspecialchars($typ_options[$type] ?? $type); ?></span>
                                    <input type="hidden" name="field_type[]" value="<?php echo htmlspecialchars($type); ?>">
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (in_array($type, ['select', 'radio'])): ?>
                                    <textarea class="form-control form-control-sm font-monospace" name="field_options[]" rows="2" style="min-width: 120px;"><?php echo htmlspecialchars(opt($opts)); ?></textarea>
                                    <?php else: ?>
                                    <span class="text-muted">—</span>
                                    <input type="hidden" name="field_options[]" value="">
                                    <?php endif; ?>
                                </td>
                                <td><input type="checkbox" class="form-check-input" name="field_visible[<?php echo $i; ?>]" value="1" <?php echo $visible ? 'checked' : ''; ?>></td>
                                <td>
                                    <input type="hidden" name="field_id[]" value="<?php echo htmlspecialchars($id); ?>">
                                    <button type="button" class="btn btn-sm btn-outline-danger btn-delete-field" title="Feld löschen" data-field-id="<?php echo htmlspecialchars($id); ?>"><i class="fas fa-trash"></i></button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (empty($felder)): ?>
                <p class="text-muted">Keine Felder definiert. Fügen Sie oben ein neues Feld hinzu.</p>
                <?php endif; ?>
            </div>
            <div class="card-footer">
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Änderungen speichern</button>
            </div>
        </div>
    </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.querySelectorAll('.btn-delete-field').forEach(function(btn) {
    btn.addEventListener('click', function() {
        if (!confirm('Feld wirklich löschen?')) return;
        document.getElementById('formAction').value = 'delete';
        document.getElementById('deleteId').value = this.getAttribute('data-field-id');
        document.getElementById('felderForm').submit();
    });
});
</script>
</body>
</html>
