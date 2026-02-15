<?php
/**
 * Anwesenheitsliste anzeigen und bearbeiten (Formularcenter – eingegangene Formulare).
 */
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/dienstplan-typen.php';
require_once __DIR__ . '/../includes/anwesenheitsliste-helper.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('Location: ../login.php');
    exit;
}
if (!has_permission('forms')) {
    header('Location: dashboard.php?error=access_denied');
    exit;
}
if (empty($_SESSION['form_center_csrf'])) {
    $_SESSION['form_center_csrf'] = bin2hex(random_bytes(32));
}

$id = (int)($_GET['id'] ?? $_POST['anwesenheitsliste_id'] ?? 0);
if ($id <= 0) {
    header('Location: formularcenter.php?tab=submissions');
    exit;
}

try {
    $db->exec("ALTER TABLE anwesenheitslisten ADD COLUMN einsatzbericht_nummer VARCHAR(50) NULL");
} catch (Exception $e) { /* Spalte existiert evtl. bereits */ }

$liste = null;
try {
    $stmt = $db->prepare("
        SELECT a.*, d.bezeichnung AS dienst_bezeichnung, d.typ AS dienst_typ,
               COALESCE(u.first_name, '') AS user_first_name, COALESCE(u.last_name, '') AS user_last_name
        FROM anwesenheitslisten a
        LEFT JOIN dienstplan d ON d.id = a.dienstplan_id
        LEFT JOIN users u ON u.id = a.user_id
        WHERE a.id = ?
    ");
    $stmt->execute([$id]);
    $liste = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $liste = null;
}
if (!$liste) {
    header('Location: formularcenter.php?tab=submissions&error=not_found');
    exit;
}

$vehicles_list = [];
try {
    $stmt = $db->query("SELECT id, name FROM vehicles ORDER BY name ASC");
    $vehicles_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$liste_members = [];
$liste_vehicles = [];
try {
    $stmt = $db->prepare("
        SELECT am.member_id, am.vehicle_id, m.first_name, m.last_name, v.name AS vehicle_name
        FROM anwesenheitsliste_mitglieder am
        LEFT JOIN members m ON m.id = am.member_id
        LEFT JOIN vehicles v ON v.id = am.vehicle_id
        WHERE am.anwesenheitsliste_id = ?
        ORDER BY m.last_name, m.first_name
    ");
    $stmt->execute([$id]);
    $liste_members = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$members_list = anwesenheitsliste_members_for_leiter($db, array_column($liste_members, 'member_id'));
try {
    $stmt = $db->prepare("
        SELECT af.vehicle_id, af.maschinist_member_id, af.einheitsfuehrer_member_id,
               v.name AS vehicle_name, m1.first_name AS masch_first, m1.last_name AS masch_last,
               m2.first_name AS einh_first, m2.last_name AS einh_last
        FROM anwesenheitsliste_fahrzeuge af
        LEFT JOIN vehicles v ON v.id = af.vehicle_id
        LEFT JOIN members m1 ON m1.id = af.maschinist_member_id
        LEFT JOIN members m2 ON m2.id = af.einheitsfuehrer_member_id
        WHERE af.anwesenheitsliste_id = ?
    ");
    $stmt->execute([$id]);
    $liste_vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$anwesenheitsliste_felder = anwesenheitsliste_felder_laden();
$custom_data = [];
if (!empty($liste['custom_data'])) {
    $dec = json_decode($liste['custom_data'], true);
    $custom_data = is_array($dec) ? $dec : [];
}
$uebungsleiter_ids = $custom_data['uebungsleiter_member_ids'] ?? [];
if (!is_array($uebungsleiter_ids)) $uebungsleiter_ids = [];
$is_uebungsdienst_edit = !empty($uebungsleiter_ids)
    || (($liste['typ'] ?? '') === 'dienst' && in_array($liste['dienst_typ'] ?? '', ['uebungsdienst', 'jahreshauptversammlung']))
    || (($liste['typ'] ?? '') === 'manuell' && in_array($liste['bezeichnung'] ?? '', ['Übungsdienst', 'Jahreshauptversammlung']));
$uebungsdienst_hide_ids = ['alarmierung_durch', 'eigentuemer', 'geschaedigter', 'kostenpflichtiger_einsatz', 'personenschaeden', 'brandwache'];

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_anwesenheitsliste') {
    if (!empty($_SESSION['form_center_csrf']) && isset($_POST['form_center_csrf']) && $_POST['form_center_csrf'] === $_SESSION['form_center_csrf']) {
        $del_id = (int)($_POST['anwesenheitsliste_id'] ?? 0);
        if ($del_id === $id) {
            try {
                $db->prepare("DELETE FROM anwesenheitslisten WHERE id = ?")->execute([$del_id]);
                header('Location: formularcenter.php?tab=submissions&message=' . urlencode('Anwesenheitsliste wurde gelöscht.'));
                exit;
            } catch (Exception $e) {
                $error = 'Löschen fehlgeschlagen.';
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_anwesenheitsliste'])) {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Ungültiger Sicherheitstoken.';
    } else {
        try {
            $builtin = ['uhrzeit_von','uhrzeit_bis','alarmierung_durch','einsatzstelle','einsatzstichwort','einsatzbericht_nummer','objekt','eigentuemer','geschaedigter','klassifizierung','kostenpflichtiger_einsatz','personenschaeden','brandwache','bemerkung'];
            $updates = [];
            $params = [];
            foreach ($anwesenheitsliste_felder as $f) {
                if (empty($f['visible'])) continue;
                $fid = $f['id'] ?? '';
                if ($fid === 'einsatzleiter') continue;
                if ($fid === 'einsatzstichwort' && $is_uebungsdienst_edit) continue;
                $val = trim($_POST[$fid] ?? '');
                if (in_array($fid, $builtin)) {
                    $updates[] = $fid . ' = ?';
                    $params[] = $val !== '' ? $val : null;
                }
            }
            $einsatzleiter_val = trim($_POST['einsatzleiter'] ?? '');
            $einsatzleiter_freitext = trim($_POST['einsatzleiter_freitext'] ?? '');
            $einsatzleiter_member_id = null;
            if ($is_uebungsdienst_edit && !empty($_POST['uebungsleiter']) && is_array($_POST['uebungsleiter'])) {
                $einsatzleiter_member_id = null;
                $einsatzleiter_freitext = '';
            } elseif (!$is_uebungsdienst_edit) {
                if ($einsatzleiter_val === '__freitext__') {
                    $einsatzleiter_member_id = null;
                } elseif ($einsatzleiter_val !== '' && ctype_digit($einsatzleiter_val)) {
                    $einsatzleiter_member_id = (int)$einsatzleiter_val;
                }
            }
            $updates[] = 'einsatzleiter_member_id = ?';
            $params[] = $einsatzleiter_member_id;
            $updates[] = 'einsatzleiter_freitext = ?';
            $params[] = $einsatzleiter_freitext !== '' ? $einsatzleiter_freitext : null;
            $einsatzbericht_nummer = $is_uebungsdienst_edit ? null : (trim($_POST['einsatzbericht_nummer'] ?? '') !== '' ? trim($_POST['einsatzbericht_nummer']) : null);
            $updates[] = 'einsatzbericht_nummer = ?';
            $params[] = $einsatzbericht_nummer;
            if ($is_uebungsdienst_edit) {
                $updates[] = 'bezeichnung = ?';
                $params[] = trim($_POST['thema'] ?? '') !== '' ? trim($_POST['thema']) : null;
            }
            $custom_post = [];
            foreach ($anwesenheitsliste_felder as $f) {
                if (empty($f['visible'])) continue;
                $fid = $f['id'] ?? '';
                if (in_array($fid, $uebungsdienst_hide_ids)) continue;
                if (!in_array($fid, $builtin) && $fid !== 'einsatzleiter') {
                    $custom_post[$fid] = trim($_POST[$fid] ?? '');
                }
            }
            if ($is_uebungsdienst_edit && !empty($_POST['uebungsleiter']) && is_array($_POST['uebungsleiter'])) {
                $custom_post['uebungsleiter_member_ids'] = array_values(array_map('intval', array_filter($_POST['uebungsleiter'], function($x){return $x!==''&&ctype_digit((string)$x);})));
            } else {
                $custom_post['uebungsleiter_member_ids'] = [];
            }
            $custom_post['vehicle_equipment'] = [];
            $custom_post['vehicle_equipment_sonstiges'] = [];
            if (!empty($_POST['equipment']) && is_array($_POST['equipment'])) {
                foreach ($_POST['equipment'] as $vid => $ids) {
                    $vid = (int)$vid;
                    if ($vid > 0 && is_array($ids)) {
                        $ids = array_filter(array_map('intval', $ids), function($x) { return $x > 0; });
                        if (!empty($ids)) {
                            $custom_post['vehicle_equipment'][$vid] = array_values($ids);
                        }
                    }
                }
            }
            if (!empty($_POST['equipment_sonstiges']) && is_array($_POST['equipment_sonstiges'])) {
                foreach ($_POST['equipment_sonstiges'] as $vid => $txt) {
                    $vid = (int)$vid;
                    if ($vid > 0 && trim((string)$txt) !== '') {
                        $custom_post['vehicle_equipment_sonstiges'][$vid] = trim((string)$txt);
                    }
                }
            }
            $updates[] = 'custom_data = ?';
            $params[] = !empty($custom_post) ? json_encode($custom_post) : null;
            $params[] = $id;
            if (!empty($updates)) {
                $sql = "UPDATE anwesenheitslisten SET " . implode(', ', $updates) . " WHERE id = ?";
                $db->prepare($sql)->execute($params);
            }

            $db->prepare("DELETE FROM anwesenheitsliste_mitglieder WHERE anwesenheitsliste_id = ?")->execute([$id]);
            $member_ids = $_POST['member_id'] ?? [];
            $member_vehicles = $_POST['member_vehicle'] ?? [];
            if (is_array($member_ids) && !empty($member_ids)) {
                $stmt = $db->prepare("INSERT INTO anwesenheitsliste_mitglieder (anwesenheitsliste_id, member_id, vehicle_id) VALUES (?, ?, ?)");
                foreach ($member_ids as $i => $mid) {
                    $mid = (int)$mid;
                    if ($mid > 0) {
                        $vid = isset($member_vehicles[$i]) ? (int)$member_vehicles[$i] : null;
                        $stmt->execute([$id, $mid, $vid > 0 ? $vid : null]);
                    }
                }
            }

            try {
                $db->prepare("DELETE FROM anwesenheitsliste_fahrzeuge WHERE anwesenheitsliste_id = ?")->execute([$id]);
                $all_vids = [];
                $member_vehicles_post = $_POST['member_vehicle'] ?? [];
                if (is_array($member_ids) && is_array($member_vehicles_post)) {
                    foreach ($member_ids as $i => $mid) {
                        $vid = isset($member_vehicles_post[$i]) ? (int)$member_vehicles_post[$i] : 0;
                        if ($vid > 0) $all_vids[$vid] = true;
                    }
                }
                $vehicle_ids_post = $_POST['vehicle_id'] ?? [];
                if (is_array($vehicle_ids_post)) {
                    foreach ($vehicle_ids_post as $vid) {
                        if ((int)$vid > 0) $all_vids[(int)$vid] = true;
                    }
                }
                $stmt = $db->prepare("INSERT INTO anwesenheitsliste_fahrzeuge (anwesenheitsliste_id, vehicle_id, maschinist_member_id, einheitsfuehrer_member_id) VALUES (?, ?, ?, ?)");
                foreach (array_keys($all_vids) as $vid) {
                    $masch = isset($_POST['vehicle_maschinist'][$vid]) ? (int)$_POST['vehicle_maschinist'][$vid] : null;
                    $einh = isset($_POST['vehicle_einheitsfuehrer'][$vid]) ? (int)$_POST['vehicle_einheitsfuehrer'][$vid] : null;
                    $stmt->execute([$id, $vid, $masch > 0 ? $masch : null, $einh > 0 ? $einh : null]);
                }
            } catch (Exception $e) {
                // Tabelle evtl. nicht vorhanden
            }

            $message = 'Anwesenheitsliste wurde gespeichert.';
            header('Location: anwesenheitsliste-bearbeiten.php?id=' . $id . '&message=saved');
            exit;
        } catch (Exception $e) {
            $error = 'Speichern fehlgeschlagen: ' . $e->getMessage();
        }
    }
}

if (isset($_GET['message']) && $_GET['message'] === 'saved') {
    $message = 'Anwesenheitsliste wurde gespeichert.';
}

function _al_val($liste, $key, $custom_data = []) {
    $builtin = ['uhrzeit_von','uhrzeit_bis','alarmierung_durch','einsatzstelle','einsatzstichwort','einsatzbericht_nummer','objekt','eigentuemer','geschaedigter','klassifizierung','kostenpflichtiger_einsatz','personenschaeden','brandwache','bemerkung'];
    if (in_array($key, $builtin)) return $liste[$key] ?? '';
    return $custom_data[$key] ?? '';
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Anwesenheitsliste bearbeiten – Feuerwehr App</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
    <div class="container-fluid">
        <a class="navbar-brand" href="../index.php"><i class="fas fa-fire"></i> Feuerwehr App</a>
        <div class="navbar-nav ms-auto d-flex align-items-center gap-2">
            <a class="nav-link" href="formularcenter.php?tab=submissions"><i class="fas fa-arrow-left"></i> Zurück zu eingegangenen Formularen</a>
            <form method="post" class="d-inline" onsubmit="return confirm('Anwesenheitsliste wirklich löschen?');">
                <input type="hidden" name="form_center_csrf" value="<?php echo htmlspecialchars($_SESSION['form_center_csrf'] ?? ''); ?>">
                <input type="hidden" name="action" value="delete_anwesenheitsliste">
                <input type="hidden" name="anwesenheitsliste_id" value="<?php echo (int)$id; ?>">
                <input type="hidden" name="redirect" value="formularcenter">
                <button type="submit" class="btn btn-outline-light btn-sm"><i class="fas fa-trash"></i> Löschen</button>
            </form>
        </div>
    </div>
</nav>

<div class="container-fluid mt-4">
    <h1 class="h3 mb-4"><i class="fas fa-clipboard-list"></i> Anwesenheitsliste – <?php echo htmlspecialchars($liste['datum']); ?> <?php echo htmlspecialchars($liste['bezeichnung'] ?? $liste['dienst_bezeichnung'] ?? 'Anwesenheit'); ?></h1>
    <?php if ($message): ?><div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
        <input type="hidden" name="save_anwesenheitsliste" value="1">
        <input type="hidden" name="anwesenheitsliste_id" value="<?php echo (int)$id; ?>">

        <div class="card mb-4">
            <div class="card-header">Stammdaten</div>
            <div class="card-body">
                <p class="text-muted small">Erstellt von <?php echo htmlspecialchars(trim($liste['user_first_name'] . ' ' . $liste['user_last_name']) ?: 'Unbekannt'); ?> am <?php echo format_datetime_berlin($liste['created_at']); ?></p>
                <div class="row g-3">
                    <?php if (!$is_uebungsdienst_edit): ?>
                    <div class="col-md-6">
                        <label for="einsatzbericht_nummer" class="form-label">Einsatzbericht Nummer</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light" style="min-width: 2.5rem;">A</span>
                            <input type="text" class="form-control" id="einsatzbericht_nummer" name="einsatzbericht_nummer" placeholder="Nummer eingeben" value="<?php echo htmlspecialchars($liste['einsatzbericht_nummer'] ?? ''); ?>">
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php foreach ($anwesenheitsliste_felder as $f):
                        if (empty($f['visible'])) continue;
                        $fid = $f['id'] ?? '';
                        if ($is_uebungsdienst_edit && in_array($fid, $uebungsdienst_hide_ids)) continue;
                        if ($fid === 'einsatzstichwort' && $is_uebungsdienst_edit) {
                            $fid = 'thema';
                            $label = 'Thema';
                            $val = trim((string)($liste['bezeichnung'] ?? ''));
                        } else {
                            $label = $f['label'] ?? $fid;
                            $val = '';
                            if ($fid === 'einsatzleiter') {
                                if (!empty($liste['einsatzleiter_freitext'])) $val = '__freitext__';
                                elseif (!empty($liste['einsatzleiter_member_id'])) $val = (string)$liste['einsatzleiter_member_id'];
                            } else {
                                $val = _al_val($liste, $f['id'] ?? '', $custom_data);
                            }
                        }
                        $type = $f['type'] ?? 'text';
                        $opts = $f['options'] ?? [];
                        if ($type === 'time' && ($f['id'] ?? '') === 'uhrzeit_bis' && $val === '') $val = date('H:i');
                    ?>
                    <div class="col-md-6">
                        <?php if ($type === 'einsatzleiter'): ?>
                        <?php if ($is_uebungsdienst_edit): ?>
                        <label class="form-label">Übungsleiter <span id="uebungsleiter_count_edit" class="badge bg-secondary ms-1">0 ausgewählt</span></label>
                        <div class="uebungsleiter-list border rounded p-2" style="max-height: 220px; overflow-y: auto; display: flex; flex-direction: column; gap: 0.35rem;">
                            <?php foreach ($members_list as $m):
                                $checked = in_array((int)$m['id'], array_map('intval', $uebungsleiter_ids)) ? ' checked' : '';
                                $sel_cls = $checked ? 'uebungsleiter-item-selected' : '';
                            ?>
                            <div class="uebungsleiter-item <?php echo $sel_cls; ?>" data-member-id="<?php echo (int)$m['id']; ?>" role="button" tabindex="0" style="cursor:pointer;padding:0.5rem 0.75rem;border-radius:6px;border:2px solid #e9ecef;transition:all 0.2s">
                                <input type="checkbox" name="uebungsleiter[]" value="<?php echo (int)$m['id']; ?>" style="display:none"<?php echo $checked; ?>>
                                <?php echo htmlspecialchars($m['last_name'] . ', ' . $m['first_name']); ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <small class="text-muted">Klicken zum Auswählen/Abwählen</small>
                        <?php else: ?>
                        <label class="form-label"><?php echo htmlspecialchars($label); ?></label>
                        <select class="form-select" name="einsatzleiter">
                            <option value="">— keine Auswahl —</option>
                            <?php foreach ($members_list as $m): ?>
                            <option value="<?php echo (int)$m['id']; ?>" <?php echo $val === (string)$m['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($m['last_name'] . ', ' . $m['first_name']); ?></option>
                            <?php endforeach; ?>
                            <option value="__freitext__" <?php echo $val === '__freitext__' ? 'selected' : ''; ?>>— Freitext —</option>
                        </select>
                        <input type="text" class="form-control mt-2" name="einsatzleiter_freitext" placeholder="Name (wenn Freitext)" value="<?php echo htmlspecialchars($liste['einsatzleiter_freitext'] ?? ''); ?>">
                        <?php endif; ?>
                        <?php elseif ($type === 'select'): ?>
                        <label class="form-label"><?php echo htmlspecialchars($label); ?></label>
                        <select class="form-select" name="<?php echo htmlspecialchars($fid); ?>">
                            <option value="">— keine Auswahl —</option>
                            <?php foreach ($opts as $o): ?>
                            <option value="<?php echo htmlspecialchars($o); ?>" <?php echo $val === $o ? 'selected' : ''; ?>><?php echo htmlspecialchars($o); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php elseif ($type === 'radio'): ?>
                        <label class="form-label"><?php echo htmlspecialchars($label); ?></label>
                        <div class="d-flex gap-3">
                            <?php foreach ($opts as $o): ?>
                            <div class="form-check"><input class="form-check-input" type="radio" name="<?php echo htmlspecialchars($fid); ?>" value="<?php echo htmlspecialchars($o); ?>" <?php echo ($val === $o || strtolower($val) === strtolower($o)) ? 'checked' : ''; ?>><label class="form-check-label"><?php echo htmlspecialchars($o); ?></label></div>
                            <?php endforeach; ?>
                        </div>
                        <?php elseif ($type === 'textarea'): ?>
                        <label class="form-label"><?php echo htmlspecialchars($label); ?></label>
                        <textarea class="form-control" name="<?php echo htmlspecialchars($fid); ?>" rows="3"><?php echo htmlspecialchars($val); ?></textarea>
                        <?php else: ?>
                        <label class="form-label"><?php echo htmlspecialchars($label); ?></label>
                        <input type="<?php echo $type === 'time' ? 'time' : 'text'; ?>" class="form-control" name="<?php echo htmlspecialchars($fid); ?>" value="<?php echo htmlspecialchars($val); ?>">
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header">Personal</div>
            <div class="card-body">
                <p class="text-muted small">Anwesende Mitglieder und zugeordnete Fahrzeuge.</p>
                <div id="membersContainer">
                    <?php
                    $member_ids = array_column($liste_members, 'member_id');
                    $member_vehicles = [];
                    foreach ($liste_members as $lm) {
                        $member_vehicles[$lm['member_id']] = $lm['vehicle_id'];
                    }
                    $idx = 0;
                    foreach ($liste_members as $lm):
                        $mid = $lm['member_id'];
                        $vid = $lm['vehicle_id'];
                    ?>
                    <div class="row g-2 mb-2 align-items-center member-row">
                        <div class="col-md-5">
                            <select class="form-select form-select-sm" name="member_id[]">
                                <option value="">— auswählen —</option>
                                <?php foreach ($members_list as $m): ?>
                                <option value="<?php echo (int)$m['id']; ?>" <?php echo (int)$mid === (int)$m['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($m['last_name'] . ', ' . $m['first_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <select class="form-select form-select-sm" name="member_vehicle[]">
                                <option value="">— kein Fahrzeug —</option>
                                <?php foreach ($vehicles_list as $v): ?>
                                <option value="<?php echo (int)$v['id']; ?>" <?php echo (int)$vid === (int)$v['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($v['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2"><button type="button" class="btn btn-sm btn-outline-danger btn-remove-member"><i class="fas fa-times"></i></button></div>
                    </div>
                    <?php $idx++; endforeach; ?>
                </div>
                <button type="button" class="btn btn-sm btn-outline-primary mt-2" id="btnAddMember"><i class="fas fa-plus"></i> Mitglied hinzufügen</button>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header">Fahrzeuge (Maschinist / Einheitsführer)</div>
            <div class="card-body">
                <?php
                $vehicle_ids = array_unique(array_merge(array_column($liste_members, 'vehicle_id'), array_column($liste_vehicles, 'vehicle_id')));
                $vehicle_ids = array_filter(array_map('intval', $vehicle_ids));
                $vehicle_ids = array_unique($vehicle_ids);
                $vehicle_roles = [];
                foreach ($liste_vehicles as $lv) {
                    $vehicle_roles[$lv['vehicle_id']] = ['maschinist' => $lv['maschinist_member_id'], 'einheitsfuehrer' => $lv['einheitsfuehrer_member_id']];
                }
                $saved_vehicle_equipment = $custom_data['vehicle_equipment'] ?? [];
                if (!is_array($saved_vehicle_equipment)) $saved_vehicle_equipment = [];
                $saved_vehicle_equipment_sonstiges = $custom_data['vehicle_equipment_sonstiges'] ?? [];
                if (!is_array($saved_vehicle_equipment_sonstiges)) $saved_vehicle_equipment_sonstiges = [];
                $vehicles_with_equipment = [];
                if (!empty($vehicle_ids)) {
                    try {
                        foreach ($vehicle_ids as $vid) {
                            if ($vid <= 0) continue;
                            $vname = '';
                            foreach ($vehicles_list as $v) { if ((int)$v['id'] === $vid) { $vname = $v['name']; break; } }
                            if ($vname === '') $vname = 'Fahrzeug ' . $vid;
                            try {
                                $stmt = $db->prepare("
                                    SELECT e.id, e.name, e.category_id, c.name AS category_name
                                    FROM vehicle_equipment e
                                    LEFT JOIN vehicle_equipment_category c ON c.id = e.category_id
                                    WHERE e.vehicle_id = ?
                                    ORDER BY COALESCE(c.sort_order, 999), c.name, e.sort_order, e.name
                                ");
                                $stmt->execute([$vid]);
                                $equipment_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
                            } catch (Exception $e2) {
                                $stmt = $db->prepare("SELECT id, name, NULL AS category_id, NULL AS category_name FROM vehicle_equipment WHERE vehicle_id = ? ORDER BY sort_order, name");
                                $stmt->execute([$vid]);
                                $equipment_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
                            }
                            $by_cat = [];
                            foreach ($equipment_raw as $eq) {
                                $cat = trim($eq['category_name'] ?? '') !== '' ? $eq['category_name'] : null;
                                if ($cat === null) $cat = '';
                                if (!isset($by_cat[$cat])) $by_cat[$cat] = [];
                                $by_cat[$cat][] = $eq;
                            }
                            $vehicles_with_equipment[$vid] = ['name' => $vname, 'equipment_by_category' => $by_cat];
                        }
                    } catch (Exception $e) {}
                }
                foreach ($vehicle_ids as $vid):
                    if ($vid <= 0) continue;
                    $vname = '';
                    foreach (array_merge($liste_members, $liste_vehicles) as $x) {
                        if (isset($x['vehicle_id']) && (int)$x['vehicle_id'] === $vid && !empty($x['vehicle_name'])) { $vname = $x['vehicle_name']; break; }
                        if (isset($x['vehicle_id']) && (int)$x['vehicle_id'] === $vid) { $vname = 'Fahrzeug ' . $vid; break; }
                    }
                    foreach ($vehicles_list as $v) { if ((int)$v['id'] === $vid) { $vname = $v['name']; break; } }
                    $roles = $vehicle_roles[$vid] ?? ['maschinist' => null, 'einheitsfuehrer' => null];
                ?>
                <div class="row g-2 mb-2 align-items-center">
                    <div class="col-md-3"><strong><?php echo htmlspecialchars($vname); ?></strong></div>
                    <div class="col-md-4">
                        <select class="form-select form-select-sm" name="vehicle_maschinist[<?php echo $vid; ?>]">
                            <option value="">— Maschinist —</option>
                            <?php foreach ($members_list as $m): ?>
                            <option value="<?php echo (int)$m['id']; ?>" <?php echo (int)($roles['maschinist'] ?? 0) === (int)$m['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($m['last_name'] . ', ' . $m['first_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <select class="form-select form-select-sm" name="vehicle_einheitsfuehrer[<?php echo $vid; ?>]">
                            <option value="">— Einheitsführer —</option>
                            <?php foreach ($members_list as $m): ?>
                            <option value="<?php echo (int)$m['id']; ?>" <?php echo (int)($roles['einheitsfuehrer'] ?? 0) === (int)$m['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($m['last_name'] . ', ' . $m['first_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <input type="hidden" name="vehicle_id[]" value="<?php echo $vid; ?>">
                </div>
                <?php endforeach; ?>
                <?php if (empty($vehicle_ids) || (count($vehicle_ids) === 1 && in_array(0, $vehicle_ids))): ?>
                <p class="text-muted small">Fahrzeuge werden über die Mitglieder-Zuordnung erfasst.</p>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($vehicles_with_equipment)): ?>
        <div class="card mb-4">
            <div class="card-header"><i class="fas fa-tools"></i> Geräte – eingesetzte Gerätschaften pro Fahrzeug</div>
            <div class="card-body">
                <p class="text-muted small">Klicken Sie auf eine Kategorie, um die Geräte anzuzeigen. Dann klicken Sie auf die Geräte zur Auswahl.</p>
                <style>.geraete-item-edit{cursor:pointer;padding:0.5rem 0.75rem;border-radius:6px;border:2px solid #e9ecef;transition:all 0.2s;display:inline-block;margin:0.2rem}.geraete-item-edit:hover{background:#f8f9fa}.geraete-item-edit-selected{background:#0d6efd!important;color:#fff!important;border-color:#0d6efd!important}.geraete-cat-header-edit{cursor:pointer;padding:0.5rem 0.75rem;border-radius:6px;border:2px solid #dee2e6;background:#f8f9fa;margin-bottom:0.5rem;display:flex;align-items:center;justify-content:space-between}.geraete-cat-header-edit:hover{background:#e9ecef}.geraete-cat-header-edit .fa-chevron-down{transition:transform 0.2s}.geraete-cat-header-edit.expanded .fa-chevron-down{transform:rotate(180deg)}.geraete-cat-content-edit{display:none;padding-left:0.5rem;margin-bottom:0.75rem}.geraete-cat-content-edit.expanded{display:block}</style>
                <?php foreach ($vehicles_with_equipment as $vid => $data): ?>
                <div class="card mb-3">
                    <div class="card-header py-2"><strong><?php echo htmlspecialchars($data['name']); ?></strong></div>
                    <div class="card-body py-3">
                        <?php if (empty($data['equipment_by_category'])): ?>
                        <p class="text-muted small mb-2">Keine Geräte hinterlegt. <a href="vehicles-geraete.php?vehicle_id=<?php echo (int)$vid; ?>">Geräte verwalten</a></p>
                        <?php else: ?>
                        <?php foreach ($data['equipment_by_category'] as $cat_name => $items): ?>
                        <?php $cat_label = $cat_name !== '' ? $cat_name : 'Ohne Kategorie'; $cat_id_attr = 'cat_edit_' . (int)$vid . '_' . md5($cat_label); ?>
                        <div class="geraete-cat-block-edit mb-2">
                            <div class="geraete-cat-header-edit" data-target="<?php echo htmlspecialchars($cat_id_attr); ?>" role="button" tabindex="0">
                                <span><?php echo htmlspecialchars($cat_label); ?></span>
                                <i class="fas fa-chevron-down"></i>
                            </div>
                            <div class="geraete-cat-content-edit" id="<?php echo htmlspecialchars($cat_id_attr); ?>">
                                <div class="d-flex flex-wrap">
                                    <?php foreach ($items as $eq):
                                        $checked = isset($saved_vehicle_equipment[$vid]) && in_array((int)$eq['id'], array_map('intval', $saved_vehicle_equipment[$vid]));
                                    ?>
                                    <div class="geraete-item-edit geraete-equipment-edit <?php echo $checked ? 'geraete-item-edit-selected' : ''; ?>" data-vid="<?php echo (int)$vid; ?>" data-eq-id="<?php echo (int)$eq['id']; ?>" role="button" tabindex="0"><?php echo htmlspecialchars($eq['name']); ?></div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                        <div class="mt-2">
                            <div class="geraete-item-edit geraete-sonstiges-trigger-edit <?php echo !empty($saved_vehicle_equipment_sonstiges[$vid]) ? 'geraete-item-edit-selected' : ''; ?>" data-vid="<?php echo (int)$vid; ?>" role="button" tabindex="0"><i class="fas fa-plus-circle"></i> Sonstiges</div>
                            <div class="mt-2 geraete-sonstiges-wrap-edit" id="sonstiges_edit_<?php echo (int)$vid; ?>" style="<?php echo !empty($saved_vehicle_equipment_sonstiges[$vid]) ? '' : 'display:none'; ?>">
                                <input type="text" class="form-control form-control-sm" name="equipment_sonstiges[<?php echo (int)$vid; ?>]" placeholder="Weiteres Gerät manuell eingeben" value="<?php echo htmlspecialchars($saved_vehicle_equipment_sonstiges[$vid] ?? ''); ?>" style="max-width:300px">
                            </div>
                        </div>
                        <div class="geraete-hidden-inputs-edit" data-vid="<?php echo (int)$vid; ?>">
                            <?php if (isset($saved_vehicle_equipment[$vid])): foreach ($saved_vehicle_equipment[$vid] as $eqid): ?>
                            <input type="hidden" name="equipment[<?php echo (int)$vid; ?>][]" value="<?php echo (int)$eqid; ?>">
                            <?php endforeach; endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <script>
        (function(){
            document.querySelectorAll('.geraete-cat-header-edit').forEach(function(header){
                header.onclick=function(){
                    var targetId=this.getAttribute('data-target');
                    var content=document.getElementById(targetId);
                    if(content){content.classList.toggle('expanded');this.classList.toggle('expanded');}
                };
                header.onkeydown=function(e){if(e.key==='Enter'||e.key===' '){e.preventDefault();this.click();}};
            });
            function syncEdit(vid){
                var c=document.querySelector('.geraete-hidden-inputs-edit[data-vid="'+vid+'"]');
                if(!c)return;
                c.innerHTML='';
                document.querySelectorAll('.geraete-equipment-edit.geraete-item-edit-selected[data-vid="'+vid+'"]').forEach(function(el){
                    var eqId=el.getAttribute('data-eq-id');
                    if(eqId){var i=document.createElement('input');i.type='hidden';i.name='equipment['+vid+'][]';i.value=eqId;c.appendChild(i);}
                });
            }
            document.querySelectorAll('.geraete-equipment-edit').forEach(function(el){
                el.onclick=function(){this.classList.toggle('geraete-item-edit-selected');syncEdit(this.getAttribute('data-vid'));};
                el.onkeydown=function(e){if(e.key==='Enter'||e.key===' '){e.preventDefault();this.click();}};
            });
            document.querySelectorAll('.geraete-sonstiges-trigger-edit').forEach(function(el){
                el.onclick=function(){
                    var vid=this.getAttribute('data-vid'),w=document.getElementById('sonstiges_edit_'+vid);
                    if(w){var s=w.style.display==='none';w.style.display=s?'block':'none';this.classList.toggle('geraete-item-edit-selected',s);if(s)w.querySelector('input').focus();}
                };
                el.onkeydown=function(e){if(e.key==='Enter'||e.key===' '){e.preventDefault();this.click();}};
            });
        })();
        </script>
        <?php endif; ?>

        <div class="mb-4">
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Speichern</button>
            <a href="../api/anwesenheitsliste-pdf.php?id=<?php echo (int)$id; ?>" class="btn btn-outline-success" download><i class="fas fa-file-pdf"></i> PDF herunterladen</a>
            <a href="formularcenter.php?tab=submissions" class="btn btn-secondary">Abbrechen</a>
        </div>
    </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<style>.uebungsleiter-item:hover{background:#f8f9fa}.uebungsleiter-item-selected{background:#0d6efd!important;color:#fff!important;border-color:#0d6efd!important}</style>
<script>
document.querySelectorAll('.uebungsleiter-item').forEach(function(el){
    el.addEventListener('click',function(){
        var cb=this.querySelector('input[type=checkbox]');
        cb.checked=!cb.checked;
        this.classList.toggle('uebungsleiter-item-selected',cb.checked);
        var cnt=document.querySelectorAll('.uebungsleiter-item-selected').length;
        var badge=document.getElementById('uebungsleiter_count_edit');
        if(badge){badge.textContent=cnt+' ausgewählt';badge.className='badge ms-1 '+(cnt>0?'bg-primary':'bg-secondary');}
    });
});
(function(){var cnt=document.querySelectorAll('.uebungsleiter-item-selected').length;var badge=document.getElementById('uebungsleiter_count_edit');if(badge){badge.textContent=cnt+' ausgewählt';badge.className='badge ms-1 '+(cnt>0?'bg-primary':'bg-secondary');}})();
</script>
<script>
(function() {
    var membersList = <?php echo json_encode(array_map(function($m) { return ['id' => $m['id'], 'name' => $m['last_name'] . ', ' . $m['first_name']]; }, $members_list)); ?>;
    var vehiclesList = <?php echo json_encode(array_map(function($v) { return ['id' => $v['id'], 'name' => $v['name']]; }, $vehicles_list)); ?>;
    function addMemberRow(mid, vid) {
        var c = document.getElementById('membersContainer');
        var div = document.createElement('div');
        div.className = 'row g-2 mb-2 align-items-center member-row';
        var selM = '<select class="form-select form-select-sm" name="member_id[]"><option value="">— auswählen —</option>';
        membersList.forEach(function(m) { selM += '<option value="' + m.id + '"' + (mid == m.id ? ' selected' : '') + '>' + (m.name || '').replace(/</g,'&lt;') + '</option>'; });
        selM += '</select>';
        var selV = '<select class="form-select form-select-sm" name="member_vehicle[]"><option value="">— kein Fahrzeug —</option>';
        vehiclesList.forEach(function(v) { selV += '<option value="' + v.id + '"' + (vid == v.id ? ' selected' : '') + '>' + (v.name || '').replace(/</g,'&lt;') + '</option>'; });
        selV += '</select>';
        div.innerHTML = '<div class="col-md-5">' + selM + '</div><div class="col-md-4">' + selV + '</div><div class="col-md-2"><button type="button" class="btn btn-sm btn-outline-danger btn-remove-member"><i class="fas fa-times"></i></button></div>';
        c.appendChild(div);
        div.querySelector('.btn-remove-member').onclick = function() { div.remove(); };
    }
    document.getElementById('btnAddMember').onclick = function() { addMemberRow('', ''); };
    document.querySelectorAll('.btn-remove-member').forEach(function(btn) {
        btn.onclick = function() { btn.closest('.member-row').remove(); };
    });
})();
</script>
</body>
</html>
