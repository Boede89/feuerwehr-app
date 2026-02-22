<?php
/**
 * Gerätewartmitteilung anzeigen und bearbeiten.
 */
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/anwesenheitsliste-helper.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('Location: ../login.php');
    exit;
}
if (!has_permission('forms')) {
    header('Location: dashboard.php?error=access_denied');
    exit;
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: formularcenter.php?tab=submissions');
    exit;
}

$typ_options = ['einsatz' => 'Einsatz', 'uebung' => 'Übung'];
$art_options = ['Brandeinsatz' => 'Brandeinsatz', 'Techn. Hilfe' => 'Techn. Hilfe', 'CBRN' => 'CBRN', 'Sonstiges' => 'Sonstiges'];
$einsatzbereitschaft_options = ['hergestellt' => 'Einsatzbereitschaft wurde hergestellt', 'nicht_hergestellt' => 'Einsatzbereitschaft wurde nicht hergestellt'];

$vehicles = [];
$vehicles_with_equipment = [];
try {
    $stmt = $db->query("SELECT id, name FROM vehicles ORDER BY name");
    $vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($vehicles as $v) {
        $vid = (int)$v['id'];
        $stmt2 = $db->prepare("SELECT id, name FROM vehicle_equipment WHERE vehicle_id = ? ORDER BY sort_order, name");
        $stmt2->execute([$vid]);
        $equipment = $stmt2->fetchAll(PDO::FETCH_ASSOC);
        $vehicles_with_equipment[$vid] = $equipment;
    }
} catch (Exception $e) {}

$members_for_einsatzleiter = anwesenheitsliste_members_for_leiter($db, []);
$members_all = [];
try {
    $stmt = $db->query("SELECT id, first_name, last_name FROM members ORDER BY last_name, first_name");
    $members_all = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$gwm = null;
try {
    $stmt = $db->prepare("
        SELECT g.*, COALESCE(u.first_name, '') AS user_first_name, COALESCE(u.last_name, '') AS user_last_name
        FROM geraetewartmitteilungen g
        LEFT JOIN users u ON u.id = g.user_id
        WHERE g.id = ?
    ");
    $stmt->execute([$id]);
    $gwm = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $gwm = null;
}
if (!$gwm) {
    header('Location: formularcenter.php?tab=submissions&filter_formular=geraetewartmitteilung&error=not_found');
    exit;
}

$fahrzeuge = [];
try {
    $stmt = $db->prepare("
        SELECT gf.*, v.name AS vehicle_name,
               m1.first_name AS masch_first, m1.last_name AS masch_last,
               m2.first_name AS einh_first, m2.last_name AS einh_last
        FROM geraetewartmitteilung_fahrzeuge gf
        LEFT JOIN vehicles v ON v.id = gf.vehicle_id
        LEFT JOIN members m1 ON m1.id = gf.maschinist_member_id
        LEFT JOIN members m2 ON m2.id = gf.einheitsfuehrer_member_id
        WHERE gf.geraetewartmitteilung_id = ?
        ORDER BY v.name
    ");
    $stmt->execute([$id]);
    $fahrzeuge = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_geraetewartmitteilung'])) {
    $typ = in_array(trim($_POST['typ'] ?? ''), ['einsatz', 'uebung']) ? trim($_POST['typ']) : 'uebung';
    $art = trim($_POST['einsatz_uebungsart'] ?? '');
    if (!isset($art_options[$art])) $art = 'Brandeinsatz';
    $datum = trim($_POST['datum'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum)) $datum = date('Y-m-d');
    $einsatzbereitschaft = in_array(trim($_POST['einsatzbereitschaft'] ?? ''), ['hergestellt', 'nicht_hergestellt']) ? trim($_POST['einsatzbereitschaft']) : 'hergestellt';
    $mangel_beschreibung = trim($_POST['mangel_beschreibung'] ?? '') ?: null;
    $einsatzleiter = trim($_POST['einsatzleiter'] ?? '');
    $einsatzleiter_member_id = null;
    $einsatzleiter_freitext = null;
    if ($einsatzleiter === '__freitext__') {
        $einsatzleiter_freitext = trim($_POST['einsatzleiter_freitext'] ?? '') ?: null;
    } elseif (preg_match('/^\d+$/', $einsatzleiter)) {
        $einsatzleiter_member_id = (int)$einsatzleiter;
    }

    $vehicle_ids = isset($_POST['vehicle_id']) && is_array($_POST['vehicle_id']) ? array_filter(array_map('intval', $_POST['vehicle_id']), fn($x) => $x > 0) : [];
    if (empty($vehicle_ids)) {
        $error = 'Bitte wählen Sie mindestens ein Fahrzeug aus.';
    } else {
        try {
            $stmt = $db->prepare("UPDATE geraetewartmitteilungen SET typ = ?, einsatz_uebungsart = ?, datum = ?, einsatzbereitschaft = ?, mangel_beschreibung = ?, einsatzleiter_member_id = ?, einsatzleiter_freitext = ? WHERE id = ?");
            $stmt->execute([$typ, $art, $datum, $einsatzbereitschaft, $mangel_beschreibung, $einsatzleiter_member_id, $einsatzleiter_freitext, $id]);

            $db->prepare("DELETE FROM geraetewartmitteilung_fahrzeuge WHERE geraetewartmitteilung_id = ?")->execute([$id]);

            $stmt_f = $db->prepare("INSERT INTO geraetewartmitteilung_fahrzeuge (geraetewartmitteilung_id, vehicle_id, maschinist_member_id, einheitsfuehrer_member_id, equipment_used, defective_equipment, defective_freitext, defective_mangel) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            foreach ($vehicle_ids as $vid) {
                $masch = isset($_POST['maschinist'][$vid]) && preg_match('/^\d+$/', $_POST['maschinist'][$vid]) ? (int)$_POST['maschinist'][$vid] : null;
                $einh = isset($_POST['einheitsfuehrer'][$vid]) && preg_match('/^\d+$/', $_POST['einheitsfuehrer'][$vid]) ? (int)$_POST['einheitsfuehrer'][$vid] : null;
                $equipment_used = isset($_POST['equipment'][$vid]) && is_array($_POST['equipment'][$vid]) ? array_values(array_filter(array_map('intval', $_POST['equipment'][$vid]), fn($x) => $x > 0)) : [];
                $defective = isset($_POST['defective_equipment'][$vid]) && is_array($_POST['defective_equipment'][$vid]) ? array_values(array_filter(array_map('intval', $_POST['defective_equipment'][$vid]), fn($x) => $x > 0)) : [];
                $defective_freitext = trim($_POST['defective_freitext'][$vid] ?? '') ?: null;
                $defective_mangel = trim($_POST['defective_mangel'][$vid] ?? '') ?: null;
                $stmt_f->execute([$id, $vid, $masch, $einh, json_encode($equipment_used), json_encode($defective), $defective_freitext, $defective_mangel]);
            }

            $message = 'Gerätewartmitteilung wurde gespeichert.';
            header('Location: geraetewartmitteilung-bearbeiten.php?id=' . $id . '&message=saved');
            exit;
        } catch (Exception $e) {
            $error = 'Speichern fehlgeschlagen: ' . $e->getMessage();
        }
    }
}

if (isset($_GET['message']) && $_GET['message'] === 'saved') {
    $message = 'Gerätewartmitteilung wurde gespeichert.';
    $gwm = null;
    $stmt = $db->prepare("SELECT g.*, COALESCE(u.first_name, '') AS user_first_name, COALESCE(u.last_name, '') AS user_last_name FROM geraetewartmitteilungen g LEFT JOIN users u ON u.id = g.user_id WHERE g.id = ?");
    $stmt->execute([$id]);
    $gwm = $stmt->fetch(PDO::FETCH_ASSOC);
    $fahrzeuge = [];
    $stmt = $db->prepare("SELECT gf.*, v.name AS vehicle_name FROM geraetewartmitteilung_fahrzeuge gf LEFT JOIN vehicles v ON v.id = gf.vehicle_id WHERE gf.geraetewartmitteilung_id = ? ORDER BY v.name");
    $stmt->execute([$id]);
    $fahrzeuge = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$existing_vehicle_ids = array_column($fahrzeuge, 'vehicle_id');
$fahrzeuge_by_vid = [];
foreach ($fahrzeuge as $f) {
    $fahrzeuge_by_vid[(int)$f['vehicle_id']] = $f;
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerätewartmitteilung bearbeiten - Feuerwehr App</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <style>
        .gwm-vehicle-card .vehicle-select { min-width: 220px; }
    </style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
    <div class="container-fluid">
        <a class="navbar-brand" href="../index.php"><i class="fas fa-fire"></i> Feuerwehr App</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <div class="navbar-nav ms-auto d-flex align-items-center">
                <?php $admin_menu_in_navbar = true; include __DIR__ . '/includes/admin-menu.inc.php'; ?>
            </div>
        </div>
    </div>
</nav>

<div class="container mt-4">
    <h1 class="h3 mb-0"><i class="fas fa-wrench text-info"></i> Gerätewartmitteilung bearbeiten</h1>
    <p class="text-muted small"><?php echo date('d.m.Y', strtotime($gwm['datum'])); ?> – <?php echo $gwm['typ'] === 'einsatz' ? 'Einsatz' : 'Übung'; ?> – <?php echo htmlspecialchars($gwm['einsatz_uebungsart']); ?></p>
    <p class="text-muted small">Eingereicht von <?php echo htmlspecialchars(trim($gwm['user_first_name'] . ' ' . $gwm['user_last_name']) ?: 'Unbekannt'); ?> am <?php echo format_datetime_berlin($gwm['created_at']); ?></p>

    <?php if ($message): ?><div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

    <form method="post" id="gwmForm">
        <input type="hidden" name="save_geraetewartmitteilung" value="1">

        <div class="card mb-4">
            <div class="card-header bg-light"><strong>Art des Einsatzes / der Übung</strong></div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Einsatz oder Übung</label>
                        <div class="d-flex gap-3">
                            <?php foreach ($typ_options as $k => $v): ?>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="typ" id="typ_<?php echo $k; ?>" value="<?php echo $k; ?>" <?php echo ($gwm['typ'] ?? 'uebung') === $k ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="typ_<?php echo $k; ?>"><?php echo htmlspecialchars($v); ?></label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label for="einsatz_uebungsart" class="form-label">Einsatz-/Übungsart</label>
                        <select class="form-select" name="einsatz_uebungsart" id="einsatz_uebungsart" required>
                            <?php foreach ($art_options as $k => $v): ?>
                            <option value="<?php echo htmlspecialchars($k); ?>" <?php echo ($gwm['einsatz_uebungsart'] ?? 'Brandeinsatz') === $k ? 'selected' : ''; ?>><?php echo htmlspecialchars($v); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label for="datum" class="form-label">Datum</label>
                        <input type="date" class="form-control" name="datum" id="datum" value="<?php echo htmlspecialchars($gwm['datum'] ?? date('Y-m-d')); ?>" required>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header bg-light"><strong>Eingesetzte Fahrzeuge</strong></div>
            <div class="card-body">
                <p class="text-muted small mb-3">Wählen Sie die eingesetzten Fahrzeuge aus und legen Sie Maschinist sowie Einheitsführer fest. Pro Fahrzeug können Sie die genutzten Geräte auswählen.</p>
                <div class="row g-3">
                    <?php foreach ($vehicles as $v): $vid = (int)$v['id']; $eq_list = $vehicles_with_equipment[$vid] ?? []; $fdata = $fahrzeuge_by_vid[$vid] ?? null; $is_selected = in_array($vid, $existing_vehicle_ids); ?>
                    <div class="col-12">
                        <div class="card gwm-vehicle-card vehicle-row" data-vehicle-id="<?php echo $vid; ?>">
                            <div class="card-body">
                                <div class="d-flex align-items-center gap-3 flex-wrap">
                                    <div class="form-check mb-0">
                                        <input type="checkbox" class="form-check-input vehicle-check" name="vehicle_selected[<?php echo $vid; ?>]" value="1" data-vid="<?php echo $vid; ?>" id="vchk_<?php echo $vid; ?>" <?php echo $is_selected ? 'checked' : ''; ?>>
                                        <label class="form-check-label fw-bold" for="vchk_<?php echo $vid; ?>"><?php echo htmlspecialchars($v['name']); ?></label>
                                    </div>
                                    <input type="hidden" name="vehicle_id[]" value="<?php echo $vid; ?>" class="vehicle-id-input" <?php echo $is_selected ? '' : 'disabled'; ?>>
                                    <div class="vehicle-fields ms-md-auto d-flex flex-column flex-md-row gap-3 flex-wrap <?php echo $is_selected ? '' : 'd-none'; ?>">
                                        <div style="min-width: 220px;">
                                            <label class="form-label small mb-0">Maschinist</label>
                                            <select class="form-select vehicle-select vehicle-maschinist" name="maschinist[<?php echo $vid; ?>]" <?php echo $is_selected ? '' : 'disabled'; ?>>
                                                <option value="">— keine Auswahl —</option>
                                                <?php foreach ($members_all as $m): $sel = ($fdata && (int)($fdata['maschinist_member_id'] ?? 0) === (int)$m['id']) ? ' selected' : ''; ?>
                                                <option value="<?php echo (int)$m['id']; ?>"<?php echo $sel; ?>><?php echo htmlspecialchars($m['last_name'] . ', ' . $m['first_name']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div style="min-width: 220px;">
                                            <label class="form-label small mb-0">Einheitsführer</label>
                                            <select class="form-select vehicle-select vehicle-einheitsfuehrer" name="einheitsfuehrer[<?php echo $vid; ?>]" <?php echo $is_selected ? '' : 'disabled'; ?>>
                                                <option value="">— keine Auswahl —</option>
                                                <?php foreach ($members_all as $m): $sel = ($fdata && (int)($fdata['einheitsfuehrer_member_id'] ?? 0) === (int)$m['id']) ? ' selected' : ''; ?>
                                                <option value="<?php echo (int)$m['id']; ?>"<?php echo $sel; ?>><?php echo htmlspecialchars($m['last_name'] . ', ' . $m['first_name']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                <div class="equipment-cell mt-2 vehicle-fields <?php echo $is_selected ? '' : 'd-none'; ?>">
                                    <?php
                                    $used_eq = [];
                                    if ($fdata && !empty($fdata['equipment_used'])) {
                                        $used_eq = json_decode($fdata['equipment_used'], true);
                                        if (!is_array($used_eq)) $used_eq = [];
                                    }
                                    $def_eq = [];
                                    if ($fdata && !empty($fdata['defective_equipment'])) {
                                        $def_eq = json_decode($fdata['defective_equipment'], true);
                                        if (!is_array($def_eq)) $def_eq = [];
                                    }
                                    ?>
                                    <?php if (!empty($eq_list)): ?>
                                    <div class="equipment-checkboxes mt-2" data-vid="<?php echo $vid; ?>">
                                        <label class="form-label small mb-1">Eingesetzte Geräte</label>
                                        <div class="d-flex flex-wrap gap-2">
                                            <?php foreach ($eq_list as $eq): $checked = in_array((int)$eq['id'], array_map('intval', $used_eq)); ?>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="equipment[<?php echo $vid; ?>][]" value="<?php echo (int)$eq['id']; ?>" id="eq_<?php echo $vid; ?>_<?php echo $eq['id']; ?>" <?php echo $checked ? 'checked' : ''; ?>>
                                                <label class="form-check-label small" for="eq_<?php echo $vid; ?>_<?php echo $eq['id']; ?>"><?php echo htmlspecialchars($eq['name']); ?></label>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <?php else: ?>
                                    <span class="text-muted small">Keine Geräte hinterlegt</span>
                                    <?php endif; ?>
                                    <div class="mt-2">
                                        <label class="form-label small">Defekte Geräte (Freitext)</label>
                                        <input type="text" class="form-control form-control-sm" name="defective_freitext[<?php echo $vid; ?>]" value="<?php echo htmlspecialchars($fdata['defective_freitext'] ?? ''); ?>" placeholder="z.B. Schlauch 123">
                                    </div>
                                    <div class="mt-2">
                                        <label class="form-label small">Mangel beschreiben</label>
                                        <textarea class="form-control form-control-sm" name="defective_mangel[<?php echo $vid; ?>]" rows="2" placeholder="Beschreibung des Mangels"><?php echo htmlspecialchars($fdata['defective_mangel'] ?? ''); ?></textarea>
                                    </div>
                                    <?php if (!empty($eq_list)): ?>
                                    <div class="mt-2">
                                        <label class="form-label small">Defekte Geräte (aus eingesetzten)</label>
                                        <div class="d-flex flex-wrap gap-2">
                                            <?php foreach ($eq_list as $eq): $def_checked = in_array((int)$eq['id'], array_map('intval', $def_eq)); ?>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="defective_equipment[<?php echo $vid; ?>][]" value="<?php echo (int)$eq['id']; ?>" <?php echo $def_checked ? 'checked' : ''; ?>>
                                                <label class="form-check-label small"><?php echo htmlspecialchars($eq['name']); ?></label>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header bg-light"><strong>Weitere Angaben</strong></div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label">Einsatzbereitschaft</label>
                    <div class="d-flex gap-3">
                        <?php foreach ($einsatzbereitschaft_options as $k => $v): ?>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="einsatzbereitschaft" id="eb_<?php echo $k; ?>" value="<?php echo $k; ?>" <?php echo ($gwm['einsatzbereitschaft'] ?? 'hergestellt') === $k ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="eb_<?php echo $k; ?>"><?php echo htmlspecialchars($v); ?></label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="mb-3">
                    <label for="mangel_beschreibung" class="form-label">Mangel beschreiben</label>
                    <textarea class="form-control" name="mangel_beschreibung" id="mangel_beschreibung" rows="3" placeholder="Beschreibung von Mängeln oder Auffälligkeiten"><?php echo htmlspecialchars($gwm['mangel_beschreibung'] ?? ''); ?></textarea>
                </div>
                <div class="mb-3">
                    <label for="einsatzleiter" class="form-label">Einsatzleiter</label>
                    <select class="form-select" id="einsatzleiter" name="einsatzleiter">
                        <option value="">— keine Auswahl —</option>
                        <?php foreach ($members_for_einsatzleiter as $m): $sel = (!empty($gwm['einsatzleiter_member_id']) && (int)$gwm['einsatzleiter_member_id'] === (int)$m['id']) ? ' selected' : ''; ?>
                        <option value="<?php echo (int)$m['id']; ?>"<?php echo $sel; ?>><?php echo htmlspecialchars($m['last_name'] . ', ' . $m['first_name']); ?></option>
                        <?php endforeach; ?>
                        <option value="__freitext__" <?php echo !empty($gwm['einsatzleiter_freitext']) ? 'selected' : ''; ?>>— Freitext (z. B. andere Feuerwehr) —</option>
                    </select>
                    <div class="mt-2" id="einsatzleiter_freitext_wrap" style="display:<?php echo !empty($gwm['einsatzleiter_freitext']) ? 'block' : 'none'; ?>">
                        <input type="text" class="form-control" name="einsatzleiter_freitext" id="einsatzleiter_freitext" placeholder="Name Einsatzleiter" value="<?php echo htmlspecialchars($gwm['einsatzleiter_freitext'] ?? ''); ?>">
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex flex-wrap gap-2">
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Speichern</button>
            <a href="formularcenter.php?tab=submissions&filter_formular=geraetewartmitteilung" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Zurück</a>
            <a href="../api/geraetewartmitteilung-pdf.php?id=<?php echo (int)$id; ?>" class="btn btn-outline-success" download><i class="fas fa-file-pdf"></i> PDF</a>
        </div>
    </form>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function() {
    function toggleVehicleRow(checkbox) {
        var vid = parseInt(checkbox.dataset.vid, 10);
        var card = checkbox.closest('.vehicle-row');
        var hid = card.querySelector('.vehicle-id-input');
        var fields = card.querySelectorAll('.vehicle-fields');
        var selects = card.querySelectorAll('.vehicle-select');
        if (checkbox.checked) {
            if (hid) hid.disabled = false;
            fields.forEach(function(f) { f.classList.remove('d-none'); });
            selects.forEach(function(s) { s.disabled = false; });
        } else {
            if (hid) hid.disabled = true;
            fields.forEach(function(f) { f.classList.add('d-none'); });
            selects.forEach(function(s) { s.disabled = true; s.value = ''; });
        }
    }
    document.querySelectorAll('.vehicle-check').forEach(function(cb) {
        cb.addEventListener('change', function() { toggleVehicleRow(this); });
    });
    document.getElementById('einsatzleiter').addEventListener('change', function() {
        document.getElementById('einsatzleiter_freitext_wrap').style.display = this.value === '__freitext__' ? 'block' : 'none';
    });
    document.getElementById('gwmForm').addEventListener('submit', function() {
        var checked = document.querySelectorAll('.vehicle-check:checked');
        if (checked.length === 0) {
            alert('Bitte wählen Sie mindestens ein Fahrzeug aus.');
            return false;
        }
        checked.forEach(function(cb) {
            var hid = cb.closest('.vehicle-row').querySelector('.vehicle-id-input');
            if (hid) hid.disabled = false;
        });
        return true;
    });
})();
</script>
</body>
</html>
