<?php
/**
 * Anwesenheitsliste – Mängel: Mängelbericht-Felder erfassen.
 * Beim Speichern der Anwesenheitsliste werden automatisch Mängelberichte erstellt.
 */
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/dienstplan-typen.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}
if (!has_form_fill_permission()) {
    header('Location: index.php?error=no_forms_access');
    exit;
}

$datum = isset($_GET['datum']) ? trim($_GET['datum']) : '';
$auswahl = isset($_GET['auswahl']) ? trim($_GET['auswahl']) : '';
$edit_id = isset($_GET['edit_id']) ? (int)$_GET['edit_id'] : 0;
$return_formularcenter = isset($_GET['return']) && $_GET['return'] === 'formularcenter';
$einheit_id = isset($_GET['einheit_id']) ? (int)$_GET['einheit_id'] : (isset($_SESSION['current_einheit_id']) ? (int)$_SESSION['current_einheit_id'] : 0);
if ($einheit_id > 0) $_SESSION['current_einheit_id'] = $einheit_id;
$einheit_param = $einheit_id > 0 ? '?einheit_id=' . (int)$einheit_id : '';
$url_suffix = ($edit_id > 0 ? '&edit_id=' . $edit_id : '') . ($return_formularcenter ? '&return=formularcenter' : '') . ($einheit_id > 0 ? '&einheit_id=' . (int)$einheit_id : '');
if ($datum === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum) || $auswahl === '') {
    header('Location: anwesenheitsliste.php?error=datum');
    exit;
}

$draft_key = 'anwesenheit_draft';
if (!isset($_SESSION[$draft_key]) || $_SESSION[$draft_key]['datum'] !== $datum || $_SESSION[$draft_key]['auswahl'] !== $auswahl) {
    header('Location: anwesenheitsliste-eingaben.php?datum=' . urlencode($datum) . '&auswahl=' . urlencode($auswahl) . $url_suffix);
    exit;
}
$draft = &$_SESSION[$draft_key];
if (($draft['typ'] ?? '') === 'einsatz') {
    $ts = trim((string)($_GET['typ_sonstige'] ?? ''));
    if ($ts !== '') {
        $typen = get_dienstplan_typen_auswahl();
        $draft['bezeichnung_sonstige'] = $typen[$ts] ?? $draft['bezeichnung_sonstige'];
    }
    if (!empty($_GET['uebungsleiter']) && is_array($_GET['uebungsleiter'])) {
        $draft['uebungsleiter_member_ids'] = array_values(array_map('intval', array_filter($_GET['uebungsleiter'], function($x){return $x!==''&&ctype_digit((string)$x);})));
    }
}
if (!isset($draft['maengel']) || !is_array($draft['maengel'])) {
    $draft['maengel'] = [];
}

$standort_options = ['GH Amern', 'GH Hehler', 'GH Waldniel'];
$mangel_an_options = ['Gebäude', 'Fahrzeug', 'Gerät', 'PSA'];

$settings = [];
try {
    require_once __DIR__ . '/includes/einheit-settings-helper.php';
    $settings = load_settings_for_einheit($db, $einheit_id > 0 ? $einheit_id : null);
} catch (Exception $e) {}
$standort_default = trim($settings['maengelbericht_standort_default'] ?? '');
if (!in_array($standort_default, $standort_options)) $standort_default = $standort_options[0];
$mangel_an_default = trim($settings['maengelbericht_mangel_an_default'] ?? '');
if (!in_array($mangel_an_default, $mangel_an_options)) $mangel_an_default = $mangel_an_options[0];

$members_list = [];
try {
    if ($einheit_id > 0) {
        $stmt = $db->prepare("SELECT id, first_name, last_name FROM members WHERE einheit_id = ? OR einheit_id IS NULL ORDER BY last_name, first_name");
        $stmt->execute([$einheit_id]);
    } else {
        $stmt = $db->query("SELECT id, first_name, last_name FROM members ORDER BY last_name, first_name");
    }
    $members_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$vehicles_list = [];
try {
    if ($einheit_id > 0) {
        $stmt = $db->prepare("SELECT id, name FROM vehicles WHERE einheit_id = ? OR einheit_id IS NULL ORDER BY name");
        $stmt->execute([$einheit_id]);
    } else {
        $stmt = $db->query("SELECT id, name FROM vehicles ORDER BY name");
    }
    $vehicles_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$back_url = 'anwesenheitsliste-eingaben.php?datum=' . urlencode($datum) . '&auswahl=' . urlencode($auswahl) . $url_suffix;
if (($draft['typ'] ?? '') === 'einsatz') {
    $typen_map = get_dienstplan_typen_auswahl();
    $ts = trim($draft['bezeichnung_sonstige'] ?? 'Einsatz');
    $typ_key = array_search($ts, $typen_map);
    if ($typ_key === false) $typ_key = '__custom__';
    $back_url .= '&typ_sonstige=' . urlencode($typ_key);
    foreach ($draft['uebungsleiter_member_ids'] ?? [] as $uid) {
        if ((int)$uid > 0) $back_url .= '&uebungsleiter[]=' . (int)$uid;
    }
}
$message = '';
$error = '';

// POST: Mängel in Draft speichern und zurück
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_maengel_draft'])) {
    require_once __DIR__ . '/includes/bericht-anhaenge-helper.php';
    $maengel = [];
    if (!empty($_POST['maengel']) && is_array($_POST['maengel'])) {
        foreach ($_POST['maengel'] as $idx => $m) {
            $idx = (int)$idx;
            $standort = trim($m['standort'] ?? '');
            $mangel_an = trim($m['mangel_an'] ?? '');
            $bezeichnung = trim($m['bezeichnung'] ?? '');
            $mangel_beschreibung = trim($m['mangel_beschreibung'] ?? '');
            $ursache = trim($m['ursache'] ?? '');
            $verbleib = trim($m['verbleib'] ?? '');
            $aufgenommen_durch = trim($m['aufgenommen_durch'] ?? '');
            $vehicle_id = isset($m['vehicle_id']) && preg_match('/^\d+$/', (string)$m['vehicle_id']) ? (int)$m['vehicle_id'] : null;
            if (!in_array($standort, $standort_options)) $standort = $standort_options[0];
            if (!in_array($mangel_an, $mangel_an_options)) $mangel_an = $mangel_an_options[0];
            $prevTemp = [];
            if (!empty($draft['maengel']) && is_array($draft['maengel'])
                && isset($draft['maengel'][$idx]['anhaenge_temp']) && is_array($draft['maengel'][$idx]['anhaenge_temp'])) {
                $prevTemp = $draft['maengel'][$idx]['anhaenge_temp'];
            }
            $newFiles = [];
            if (!empty($_FILES['maengel']['name'][$idx]['anhaenge'])) {
                $newFiles = bericht_anhaenge_maengel_draft_save_uploads($_FILES, $idx, (int)($_SESSION['user_id'] ?? 0));
            }
            $anhaenge_temp = array_merge($prevTemp, $newFiles);
            if ($bezeichnung !== '' || $mangel_beschreibung !== '' || $ursache !== '' || $verbleib !== '' || $aufgenommen_durch !== '' || !empty($anhaenge_temp)) {
                $maengel[] = [
                    'standort' => $standort,
                    'mangel_an' => $mangel_an,
                    'bezeichnung' => $bezeichnung ?: null,
                    'mangel_beschreibung' => $mangel_beschreibung ?: null,
                    'ursache' => $ursache ?: null,
                    'verbleib' => $verbleib ?: null,
                    'aufgenommen_durch' => $aufgenommen_durch ?: null,
                    'vehicle_id' => $vehicle_id,
                    'anhaenge_temp' => $anhaenge_temp,
                ];
            }
        }
    }
    $draft['maengel'] = $maengel;
    $_SESSION[$draft_key] = $draft;
    try {
        require_once __DIR__ . '/includes/anwesenheitsliste-helper.php';
        anwesenheitsliste_draft_persist($db, $draft, (int)($_SESSION['user_id'] ?? 0), $einheit_id > 0 ? $einheit_id : null);
    } catch (Exception $e) { error_log('anwesenheitsliste-maengel draft save: ' . $e->getMessage()); }
    header('Location: ' . $back_url);
    exit;
}

$maengel_draft = $draft['maengel'];
if (empty($maengel_draft)) {
    $maengel_draft = [['standort' => $standort_default, 'mangel_an' => $mangel_an_default, 'bezeichnung' => '', 'mangel_beschreibung' => '', 'ursache' => '', 'verbleib' => '', 'aufgenommen_durch' => '', 'vehicle_id' => '', 'anhaenge_temp' => []]];
} else {
    foreach ($maengel_draft as &$md) {
        if (!isset($md['anhaenge_temp']) || !is_array($md['anhaenge_temp'])) {
            $md['anhaenge_temp'] = [];
        }
    }
    unset($md);
}

$members_json = json_encode(array_map(function($m) {
    return ['id' => (int)$m['id'], 'label' => trim($m['last_name'] . ', ' . $m['first_name'])];
}, $members_list));
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Anwesenheitsliste – Mängel - Feuerwehr App</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
    <div class="container">
        <a class="navbar-brand" href="index.php<?php echo $einheit_param; ?>"><i class="fas fa-fire"></i> Feuerwehr App</a>
        <?php if (isset($_SESSION['user_id']) && !is_system_user()): ?>
            <div class="d-flex ms-auto">
            <?php
            $admin_menu_in_navbar = true;
            $admin_menu_base = 'admin/';
            $admin_menu_logout = 'logout.php';
            $admin_menu_index = 'index.php' . $einheit_param;
            include __DIR__ . '/admin/includes/admin-menu.inc.php';
            ?>
            </div>
        <?php else: ?>
            <?php if (!isset($_SESSION['user_id'])): ?>
            <div class="d-flex ms-auto align-items-center">
                <a class="btn btn-outline-light btn-sm px-3 py-2 d-flex align-items-center gap-2" href="login.php">
                    <i class="fas fa-sign-in-alt"></i>
                    <span class="fw-semibold">Anmelden</span>
                </a>
            </div>
            <?php else: ?>
            <?php include __DIR__ . '/includes/system-user-nav.inc.php'; ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</nav>

<main class="container mt-4">
    <div class="row justify-content-center">
        <div class="col-lg-10">
            <div class="card shadow">
                <div class="card-header">
                    <h3 class="mb-0"><i class="fas fa-exclamation-triangle text-warning"></i> Mängel – festhalten</h3>
                    <p class="text-muted mb-0 mt-1"><?php echo date('d.m.Y', strtotime($datum)); ?> – Mängel erfassen (werden beim Speichern der Anwesenheitsliste als Mängelberichte angelegt)</p>
                </div>
                <div class="card-body p-4">
                    <?php if ($message): ?><div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
                    <?php if ($error): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

                    <form method="post" id="maengelForm" enctype="multipart/form-data">
                        <input type="hidden" name="save_maengel_draft" value="1">
                        <div id="maengelContainer">
                            <?php foreach ($maengel_draft as $idx => $m): ?>
                            <div class="maengel-block card mb-4" data-index="<?php echo (int)$idx; ?>">
                                <div class="card-header py-2 d-flex justify-content-between align-items-center">
                                    <span class="fw-bold">Mangel <?php echo (int)$idx + 1; ?></span>
                                    <?php if ($idx > 0): ?>
                                    <button type="button" class="btn btn-sm btn-outline-danger btn-remove-maengel"><i class="fas fa-trash"></i></button>
                                    <?php endif; ?>
                                </div>
                                <div class="card-body">
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label">Standort</label>
                                            <select class="form-select" name="maengel[<?php echo (int)$idx; ?>][standort]">
                                                <?php foreach ($standort_options as $opt): ?>
                                                <option value="<?php echo htmlspecialchars($opt); ?>" <?php echo ($m['standort'] ?? '') === $opt ? 'selected' : ''; ?>><?php echo htmlspecialchars($opt); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Mangel/Wartung an</label>
                                            <select class="form-select" name="maengel[<?php echo (int)$idx; ?>][mangel_an]">
                                                <?php foreach ($mangel_an_options as $opt): ?>
                                                <option value="<?php echo htmlspecialchars($opt); ?>" <?php echo ($m['mangel_an'] ?? '') === $opt ? 'selected' : ''; ?>><?php echo htmlspecialchars($opt); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label">Bezeichnung, ggf. Gerätenummer</label>
                                            <input type="text" class="form-control" name="maengel[<?php echo (int)$idx; ?>][bezeichnung]" value="<?php echo htmlspecialchars($m['bezeichnung'] ?? ''); ?>" placeholder="z.B. TLF 16/25">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Fahrzeug (auf dem sich das Gerät befindet)</label>
                                            <select class="form-select" name="maengel[<?php echo (int)$idx; ?>][vehicle_id]">
                                                <option value="">— kein Fahrzeug —</option>
                                                <?php foreach ($vehicles_list as $v): ?>
                                                <option value="<?php echo (int)$v['id']; ?>" <?php echo (isset($m['vehicle_id']) && (int)($m['vehicle_id'] ?? 0) === (int)$v['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($v['name']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label">Mangel Beschreibung</label>
                                            <textarea class="form-control" name="maengel[<?php echo (int)$idx; ?>][mangel_beschreibung]" rows="2"><?php echo htmlspecialchars($m['mangel_beschreibung'] ?? ''); ?></textarea>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Ursache</label>
                                            <input type="text" class="form-control" name="maengel[<?php echo (int)$idx; ?>][ursache]" value="<?php echo htmlspecialchars($m['ursache'] ?? ''); ?>">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Verbleib</label>
                                            <input type="text" class="form-control" name="maengel[<?php echo (int)$idx; ?>][verbleib]" value="<?php echo htmlspecialchars($m['verbleib'] ?? ''); ?>">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Aufgenommen durch</label>
                                            <div class="position-relative">
                                                <input type="text" class="form-control aufgenommen-durch-display" placeholder="Buchstaben eingeben zum Filtern" autocomplete="off" value="<?php echo htmlspecialchars(_maengel_aufgenommen_display($m['aufgenommen_durch'] ?? '', $members_list)); ?>">
                                                <input type="hidden" class="aufgenommen-durch-hidden" name="maengel[<?php echo (int)$idx; ?>][aufgenommen_durch]" value="<?php echo htmlspecialchars($m['aufgenommen_durch'] ?? ''); ?>">
                                                <div class="list-group position-absolute w-100 mt-1 shadow aufgenommen-suggestions" style="z-index: 1050; max-height: 180px; overflow-y: auto; display: none;"></div>
                                            </div>
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label">Anhänge (Foto / PDF, optional)</label>
                                            <div class="d-flex flex-wrap gap-2 align-items-center">
                                                <input type="file" class="form-control form-control-sm maengel-anhaenge-input" style="max-width:260px" name="maengel[<?php echo (int)$idx; ?>][anhaenge][]" multiple accept="image/jpeg,image/png,image/webp,image/gif,application/pdf,.pdf">
                                                <button type="button" class="btn btn-sm btn-outline-secondary btn-maengel-kamera" title="Kamera"><i class="fas fa-camera"></i></button>
                                            </div>
                                            <input type="file" class="maengel-anhaenge-camera" accept="image/*" capture="environment" style="position:absolute;left:-9999px;width:1px;height:1px;opacity:0;pointer-events:none;" tabindex="-1" aria-hidden="true">
                                            <?php if (!empty($m['anhaenge_temp']) && is_array($m['anhaenge_temp'])): ?>
                                            <ul class="small text-muted mb-0 mt-1"><?php foreach ($m['anhaenge_temp'] as $at): ?>
                                                <li><?php echo htmlspecialchars($at['orig'] ?? ($at['path'] ?? '')); ?></li>
                                            <?php endforeach; ?></ul>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="mb-3">
                            <button type="button" class="btn btn-outline-secondary" id="btnAddMaengel"><i class="fas fa-plus"></i> Weiteren Mangel hinzufügen</button>
                        </div>
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-warning text-dark"><i class="fas fa-save"></i> Speichern & Zurück</button>
                            <a href="<?php echo htmlspecialchars($back_url); ?>" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Zurück (ohne Speichern)</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function() {
    var membersData = <?php echo $members_json; ?>;
    var nextIndex = <?php echo count($maengel_draft); ?>;
    var standortOpts = <?php echo json_encode($standort_options); ?>;
    var mangelAnOpts = <?php echo json_encode($mangel_an_options); ?>;
    var vehiclesList = <?php echo json_encode(array_map(function($v) { return ['id' => (int)$v['id'], 'name' => $v['name']]; }, $vehicles_list)); ?>;
    var datum = <?php echo json_encode($datum); ?>;

    function filterMembers(q) {
        q = (q || '').toLowerCase().trim();
        if (q === '') return membersData;
        return membersData.filter(function(m) { return (m.label || '').toLowerCase().indexOf(q) >= 0; });
    }

    function initAufgenommenDurch(block) {
        var display = block.querySelector('.aufgenommen-durch-display');
        var hidden = block.querySelector('.aufgenommen-durch-hidden');
        var suggestions = block.querySelector('.aufgenommen-suggestions');
        if (!display || !suggestions) return;
        function render(items) {
            suggestions.innerHTML = '';
            items.forEach(function(item) {
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'list-group-item list-group-item-action list-group-item-light text-start';
                btn.textContent = item.label;
                btn.dataset.id = item.id;
                btn.dataset.label = item.label;
                btn.addEventListener('click', function() {
                    display.value = this.dataset.label;
                    if (hidden) hidden.value = this.dataset.id;
                    suggestions.style.display = 'none';
                });
                suggestions.appendChild(btn);
            });
            suggestions.style.display = items.length > 0 ? 'block' : 'none';
        }
        display.addEventListener('input', function() {
            if (hidden) hidden.value = '';
            render(filterMembers(display.value.trim()));
        });
        display.addEventListener('focus', function() { render(filterMembers(display.value.trim())); });
        display.addEventListener('blur', function() { setTimeout(function() { suggestions.style.display = 'none'; }, 200); });
        document.addEventListener('click', function(e) {
            if (!display.contains(e.target) && !suggestions.contains(e.target)) suggestions.style.display = 'none';
        });
    }

    function bindMaengelKamera(block) {
        var btn = block.querySelector('.btn-maengel-kamera');
        var main = block.querySelector('.maengel-anhaenge-input');
        var cam = block.querySelector('.maengel-anhaenge-camera');
        if (!btn || !main || !cam) return;
        function openCameraPicker(input) {
            if (!input) return;
            input.setAttribute('accept', 'image/*');
            input.setAttribute('capture', 'environment');
            if (typeof input.showPicker === 'function') {
                try {
                    input.showPicker();
                    return;
                } catch (e) {}
            }
            input.click();
        }
        btn.addEventListener('click', function() { openCameraPicker(cam); });
        cam.addEventListener('change', function() {
            if (!this.files || !this.files.length) return;
            try {
                var dt = new DataTransfer();
                var i;
                for (i = 0; i < main.files.length; i++) { dt.items.add(main.files[i]); }
                for (i = 0; i < this.files.length; i++) { dt.items.add(this.files[i]); }
                main.files = dt.files;
            } catch (e) {}
            this.value = '';
        });
    }

    document.querySelectorAll('.maengel-block').forEach(function(b) { initAufgenommenDurch(b); bindMaengelKamera(b); });

    document.getElementById('btnAddMaengel').addEventListener('click', function() {
        var idx = nextIndex++;
        var html = '<div class="maengel-block card mb-4" data-index="' + idx + '">' +
            '<div class="card-header py-2 d-flex justify-content-between align-items-center">' +
            '<span class="fw-bold">Mangel ' + (idx + 1) + '</span>' +
            '<button type="button" class="btn btn-sm btn-outline-danger btn-remove-maengel"><i class="fas fa-trash"></i></button>' +
            '</div><div class="card-body"><div class="row g-3">' +
            '<div class="col-md-6"><label class="form-label">Standort</label><select class="form-select" name="maengel[' + idx + '][standort]">' +
            standortOpts.map(function(o){ return '<option value="' + o + '">' + o + '</option>'; }).join('') +
            '</select></div>' +
            '<div class="col-md-6"><label class="form-label">Mangel/Wartung an</label><select class="form-select" name="maengel[' + idx + '][mangel_an]">' +
            mangelAnOpts.map(function(o){ return '<option value="' + o + '">' + o + '</option>'; }).join('') +
            '</select></div>' +
            '<div class="col-12"><label class="form-label">Bezeichnung, ggf. Gerätenummer</label><input type="text" class="form-control" name="maengel[' + idx + '][bezeichnung]"></div>' +
            '<div class="col-md-6"><label class="form-label">Fahrzeug (auf dem sich das Gerät befindet)</label><select class="form-select" name="maengel[' + idx + '][vehicle_id]"><option value="">— kein Fahrzeug —</option>' +
            (vehiclesList || []).map(function(v){ return '<option value="' + v.id + '">' + (v.name || '').replace(/</g,'&lt;') + '</option>'; }).join('') +
            '</select></div>' +
            '<div class="col-12"><label class="form-label">Mangel Beschreibung</label><textarea class="form-control" name="maengel[' + idx + '][mangel_beschreibung]" rows="2"></textarea></div>' +
            '<div class="col-md-6"><label class="form-label">Ursache</label><input type="text" class="form-control" name="maengel[' + idx + '][ursache]"></div>' +
            '<div class="col-md-6"><label class="form-label">Verbleib</label><input type="text" class="form-control" name="maengel[' + idx + '][verbleib]"></div>' +
            '<div class="col-md-6"><label class="form-label">Aufgenommen durch</label><div class="position-relative">' +
            '<input type="text" class="form-control aufgenommen-durch-display" placeholder="Buchstaben eingeben zum Filtern" autocomplete="off">' +
            '<input type="hidden" class="aufgenommen-durch-hidden" name="maengel[' + idx + '][aufgenommen_durch]">' +
            '<div class="list-group position-absolute w-100 mt-1 shadow aufgenommen-suggestions" style="z-index:1050;max-height:180px;overflow-y:auto;display:none;"></div></div></div>' +
            '<div class="col-12"><label class="form-label">Anhänge (Foto / PDF, optional)</label><div class="d-flex flex-wrap gap-2 align-items-center">' +
            '<input type="file" class="form-control form-control-sm maengel-anhaenge-input" style="max-width:260px" name="maengel[' + idx + '][anhaenge][]" multiple accept="image/jpeg,image/png,image/webp,image/gif,application/pdf,.pdf">' +
            '<button type="button" class="btn btn-sm btn-outline-secondary btn-maengel-kamera" title="Kamera"><i class="fas fa-camera"></i></button></div>' +
            '<input type="file" class="maengel-anhaenge-camera" accept="image/*" capture="environment" style="position:absolute;left:-9999px;width:1px;height:1px;opacity:0;pointer-events:none;" tabindex="-1" aria-hidden="true">' +
            '</div></div></div></div>';
        var div = document.createElement('div');
        div.innerHTML = html;
        var block = div.firstElementChild;
        document.getElementById('maengelContainer').appendChild(block);
        initAufgenommenDurch(block);
        bindMaengelKamera(block);
    });

    document.getElementById('maengelContainer').addEventListener('click', function(e) {
        var btn = e.target.closest('.btn-remove-maengel');
        if (btn) {
            var block = btn.closest('.maengel-block');
            if (block && document.querySelectorAll('.maengel-block').length > 1) block.remove();
        }
    });

    document.getElementById('maengelForm').addEventListener('submit', function() {
        document.querySelectorAll('.maengel-block').forEach(function(block) {
            var hidden = block.querySelector('.aufgenommen-durch-hidden');
            var display = block.querySelector('.aufgenommen-durch-display');
            if (hidden && display) {
                var idVal = hidden.value.trim();
                if (!idVal && display.value.trim()) hidden.value = display.value.trim();
            }
        });
    });
})();
</script>
</body>
</html>
<?php
function _maengel_aufgenommen_display($val, $members_list) {
    if (empty($val)) return '';
    if (preg_match('/^\d+$/', $val)) {
        $id = (int)$val;
        foreach ($members_list as $m) {
            if ((int)$m['id'] === $id) return trim($m['last_name'] . ', ' . $m['first_name']);
        }
    }
    return $val;
}
