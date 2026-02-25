<?php
/**
 * Anwesenheitsliste – Fahrzeuge: eingesetzte Fahrzeuge, Maschinist und Einheitsführer pro Fahrzeug.
 * Personen, die hier ausgewählt werden, werden ggf. automatisch dem Personal hinzugefügt.
 */
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/anwesenheitsliste-helper.php';
require_once __DIR__ . '/includes/dienstplan-typen.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
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
// typ_sonstige und uebungsleiter aus URL übernehmen (vom Hauptformular beim Klick auf Fahrzeuge)
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

// Mitglieder und Fahrzeuge für Dropdowns (einheitsspezifisch)
$members = [];
$vehicles = [];
try {
    if ($einheit_id > 0) {
        $stmt = $db->prepare("SELECT id, first_name, last_name FROM members WHERE einheit_id = ? OR einheit_id IS NULL ORDER BY last_name, first_name");
        $stmt->execute([$einheit_id]);
    } else {
        $stmt = $db->query("SELECT id, first_name, last_name FROM members ORDER BY last_name, first_name");
    }
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}
try {
    if ($einheit_id > 0) {
        $stmt = $db->prepare("SELECT id, name FROM vehicles WHERE einheit_id = ? OR einheit_id IS NULL ORDER BY name ASC");
        $stmt->execute([$einheit_id]);
    } else {
        $stmt = $db->query("SELECT id, name FROM vehicles ORDER BY name ASC");
    }
    $vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// POST: Besatzung hinzufügen/entfernen (Modal) – vor dem Hauptformular prüfen
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $redirect_self = 'Location: anwesenheitsliste-fahrzeuge.php?datum=' . urlencode($datum) . '&auswahl=' . urlencode($auswahl);
    if (!empty($_POST['add_crew_member'])) {
        $vid = (int)($_POST['besatzung_vehicle_id'] ?? 0);
        $mid = (int)($_POST['besatzung_member_id'] ?? 0);
        if ($vid > 0 && $mid > 0) {
            $draft['member_vehicle'][$mid] = $vid;
            if (!in_array($mid, $draft['members'])) {
                $draft['members'][] = $mid;
            }
            // Person von anderen Fahrzeugen als Maschinist/EF entfernen (1 Person = 1 Fahrzeug)
            foreach ($draft['vehicle_maschinist'] ?? [] as $v => $m) {
                if ((int)$m === $mid && (int)$v !== $vid) unset($draft['vehicle_maschinist'][$v]);
            }
            foreach ($draft['vehicle_einheitsfuehrer'] ?? [] as $v => $m) {
                if ((int)$m === $mid && (int)$v !== $vid) unset($draft['vehicle_einheitsfuehrer'][$v]);
            }
        }
        header($redirect_self);
        exit;
    }
    if (!empty($_POST['remove_crew_member'])) {
        $vid = (int)($_POST['besatzung_vehicle_id'] ?? 0);
        $mid = (int)($_POST['besatzung_member_id'] ?? 0);
        if ($vid > 0 && $mid > 0) {
            unset($draft['member_vehicle'][$mid]);
            if (isset($draft['vehicle_maschinist'][$vid]) && (int)$draft['vehicle_maschinist'][$vid] === $mid) {
                unset($draft['vehicle_maschinist'][$vid]);
            }
            if (isset($draft['vehicle_einheitsfuehrer'][$vid]) && (int)$draft['vehicle_einheitsfuehrer'][$vid] === $mid) {
                unset($draft['vehicle_einheitsfuehrer'][$vid]);
            }
        }
        header($redirect_self);
        exit;
    }
}

// POST: Fahrzeuge + Maschinist/Einheitsführer speichern; Personen die nur hier gewählt wurden dem Personal hinzufügen inkl. Fahrzeugzuordnung und Rolle
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $draft['vehicles'] = [];
    $draft['vehicle_maschinist'] = [];
    $draft['vehicle_einheitsfuehrer'] = [];
    $members_set = array_flip($draft['members']);
    if (!empty($_POST['vehicle_id']) && is_array($_POST['vehicle_id'])) {
        // Validierung: 1 Person = 1 Fahrzeug; 1 Maschinist + 1 EF pro Fahrzeug
        $masch_by_person = [];
        $ef_by_person = [];
        foreach ($_POST['vehicle_id'] as $vid) {
            $vid = (int)$vid;
            if ($vid > 0) {
                $masch = isset($_POST['maschinist'][$vid]) ? (int)$_POST['maschinist'][$vid] : 0;
                $einh = isset($_POST['einheitsfuehrer'][$vid]) ? (int)$_POST['einheitsfuehrer'][$vid] : 0;
                if ($masch > 0) {
                    if (isset($masch_by_person[$masch]) || isset($ef_by_person[$masch])) {
                        $stmt_n = $db->prepare("SELECT first_name, last_name FROM members WHERE id = ?");
                        $stmt_n->execute([$masch]);
                        $n = $stmt_n->fetch(PDO::FETCH_ASSOC);
                        $name = $n ? trim($n['last_name'] . ', ' . $n['first_name']) : 'Person';
                        $_SESSION['anwesenheit_error'] = "Eine Person kann nur einem Fahrzeug zugeordnet werden. „{$name}“ ist bereits als Maschinist oder Einheitsführer bei einem anderen Fahrzeug eingetragen.";
                        header('Location: anwesenheitsliste-fahrzeuge.php?datum=' . urlencode($datum) . '&auswahl=' . urlencode($auswahl));
                        exit;
                    }
                    $masch_by_person[$masch] = $vid;
                }
                if ($einh > 0) {
                    if (isset($masch_by_person[$einh]) || isset($ef_by_person[$einh])) {
                        $stmt_n = $db->prepare("SELECT first_name, last_name FROM members WHERE id = ?");
                        $stmt_n->execute([$einh]);
                        $n = $stmt_n->fetch(PDO::FETCH_ASSOC);
                        $name = $n ? trim($n['last_name'] . ', ' . $n['first_name']) : 'Person';
                        $_SESSION['anwesenheit_error'] = "Eine Person kann nur einem Fahrzeug zugeordnet werden. „{$name}“ ist bereits als Maschinist oder Einheitsführer bei einem anderen Fahrzeug eingetragen.";
                        header('Location: anwesenheitsliste-fahrzeuge.php?datum=' . urlencode($datum) . '&auswahl=' . urlencode($auswahl));
                        exit;
                    }
                    $ef_by_person[$einh] = $vid;
                }
                if ($masch > 0 && $einh > 0 && $masch === $einh) {
                    $_SESSION['anwesenheit_error'] = "Maschinist und Einheitsführer müssen unterschiedliche Personen sein.";
                    header('Location: anwesenheitsliste-fahrzeuge.php?datum=' . urlencode($datum) . '&auswahl=' . urlencode($auswahl));
                    exit;
                }
            }
        }
        // Zuerst alle Maschinisten setzen (höhere Priorität für Fahrzeugzuordnung)
        foreach ($_POST['vehicle_id'] as $vid) {
            $vid = (int)$vid;
            if ($vid > 0) {
                $draft['vehicles'][] = $vid;
                $masch = isset($_POST['maschinist'][$vid]) ? (int)$_POST['maschinist'][$vid] : 0;
                if ($masch > 0) {
                    $draft['vehicle_maschinist'][$vid] = $masch;
                    $draft['member_vehicle'][$masch] = $vid;
                    if (!isset($members_set[$masch])) {
                        $draft['members'][] = $masch;
                        $members_set[$masch] = true;
                    }
                }
            }
        }
        // Einheitsführer: nur setzen wenn Person noch kein Fahrzeug hat (Maschinist hat Priorität)
        foreach ($_POST['vehicle_id'] as $vid) {
            $vid = (int)$vid;
            if ($vid > 0) {
                $einh = isset($_POST['einheitsfuehrer'][$vid]) ? (int)$_POST['einheitsfuehrer'][$vid] : 0;
                if ($einh > 0) {
                    $draft['vehicle_einheitsfuehrer'][$vid] = $einh;
                    if (!isset($draft['member_vehicle'][$einh])) {
                        $draft['member_vehicle'][$einh] = $vid;
                    }
                    if (!isset($members_set[$einh])) {
                        $draft['members'][] = $einh;
                        $members_set[$einh] = true;
                    }
                }
            }
        }
        $maengel = [];
        if (!empty($_POST['maengel']) && is_array($_POST['maengel'])) {
            $standort_opts = ['GH Amern', 'GH Hehler', 'GH Waldniel'];
            $mangel_an_opts = ['Gebäude', 'Fahrzeug', 'Gerät', 'PSA'];
            foreach ($_POST['maengel'] as $m) {
                $standort = in_array(trim($m['standort'] ?? ''), $standort_opts) ? trim($m['standort']) : $standort_opts[0];
                $mangel_an = in_array(trim($m['mangel_an'] ?? ''), $mangel_an_opts) ? trim($m['mangel_an']) : $mangel_an_opts[0];
                $bezeichnung = trim($m['bezeichnung'] ?? '');
                $mangel_beschreibung = trim($m['mangel_beschreibung'] ?? '');
                $ursache = trim($m['ursache'] ?? '');
                $verbleib = trim($m['verbleib'] ?? '');
                $aufgenommen_durch = trim($m['aufgenommen_durch'] ?? '');
                $vehicle_id = isset($m['vehicle_id']) && preg_match('/^\d+$/', (string)$m['vehicle_id']) ? (int)$m['vehicle_id'] : null;
                if ($bezeichnung !== '' || $mangel_beschreibung !== '' || $ursache !== '' || $verbleib !== '' || $aufgenommen_durch !== '') {
                    $maengel[] = ['standort' => $standort, 'mangel_an' => $mangel_an, 'bezeichnung' => $bezeichnung ?: null, 'mangel_beschreibung' => $mangel_beschreibung ?: null, 'ursache' => $ursache ?: null, 'verbleib' => $verbleib ?: null, 'aufgenommen_durch' => $aufgenommen_durch ?: null, 'vehicle_id' => $vehicle_id];
                }
            }
        }
        $draft['maengel'] = $maengel;
        anwesenheitsliste_draft_persist($db, $draft, (int)$_SESSION['user_id'], $einheit_id > 0 ? $einheit_id : null);
    }
    // typ_sonstige und uebungsleiter aus POST übernehmen (vom Hauptformular, damit sie beim Zurückkehren erhalten bleiben)
    if (isset($_POST['typ_sonstige']) && ($draft['typ'] ?? '') === 'einsatz') {
        $ts = trim((string)$_POST['typ_sonstige']);
        $typen = get_dienstplan_typen_auswahl();
        $draft['bezeichnung_sonstige'] = $typen[$ts] ?? ($draft['bezeichnung_sonstige'] ?? 'Einsatz');
    }
    if (!empty($_POST['uebungsleiter']) && is_array($_POST['uebungsleiter'])) {
        $draft['uebungsleiter_member_ids'] = array_values(array_map('intval', array_filter($_POST['uebungsleiter'], function($x){return $x!==''&&ctype_digit((string)$x);})));
    }
    $redirect = 'anwesenheitsliste-eingaben.php?datum=' . urlencode($datum) . '&auswahl=' . urlencode($auswahl) . $url_suffix;
    if (($draft['typ'] ?? '') === 'einsatz') {
        $typen_map = get_dienstplan_typen_auswahl();
        $ts = trim($draft['bezeichnung_sonstige'] ?? 'Einsatz');
        $typ_key = array_search($ts, $typen_map);
        if ($typ_key === false) $typ_key = '__custom__';
        $redirect .= '&typ_sonstige=' . urlencode($typ_key);
        foreach ($draft['uebungsleiter_member_ids'] ?? [] as $uid) {
            if ((int)$uid > 0) $redirect .= '&uebungsleiter[]=' . (int)$uid;
        }
    }
    header('Location: ' . $redirect);
    exit;
}

if (!isset($draft['maengel']) || !is_array($draft['maengel'])) $draft['maengel'] = [];

$standort_options = ['GH Amern', 'GH Hehler', 'GH Waldniel'];
$mangel_an_options = ['Gebäude', 'Fahrzeug', 'Gerät', 'PSA'];
$settings = [];
try {
    require_once __DIR__ . '/includes/einheit-settings-helper.php';
    $settings = load_settings_for_einheit($db, $einheit_id > 0 ? $einheit_id : null);
} catch (Exception $e) {}
$standort_default = trim($settings['maengelbericht_standort_default'] ?? '');
if (!in_array($standort_default, $standort_options)) $standort_default = $standort_options[0];
$mangel_an_default = 'Fahrzeug';

$members_list = $members;
$berichtersteller = $draft['berichtersteller'] ?? null;
$berichtersteller_vehicle = '';
if ($berichtersteller !== '' && $berichtersteller !== null && preg_match('/^\d+$/', (string)$berichtersteller)) {
    $ber_vid = $draft['member_vehicle'][(int)$berichtersteller] ?? null;
    if ($ber_vid) {
        foreach ($vehicles as $v) {
            if ((int)$v['id'] === (int)$ber_vid) {
                $berichtersteller_vehicle = $v['name'] ?? '';
                break;
            }
        }
        if ($berichtersteller_vehicle === '') {
            try {
                $stmt = $db->prepare("SELECT name FROM vehicles WHERE id = ?");
                $stmt->execute([(int)$ber_vid]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) $berichtersteller_vehicle = $row['name'];
            } catch (Exception $e) {}
        }
    }
}
$berichtersteller_display = '';
if ($berichtersteller !== '' && $berichtersteller !== null) {
    if (preg_match('/^\d+$/', (string)$berichtersteller)) {
        foreach ($members_list as $m) {
            if ((int)$m['id'] === (int)$berichtersteller) {
                $berichtersteller_display = trim($m['last_name'] . ', ' . $m['first_name']);
                break;
            }
        }
    }
    if ($berichtersteller_display === '') $berichtersteller_display = (string)$berichtersteller;
}

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
// Angezeigte Auswahl: explizit gewählte Fahrzeuge + Fahrzeuge, die unter Personal zugeordnet wurden
$selected_vehicles = array_flip($draft['vehicles']);
foreach ($draft['member_vehicle'] as $mid => $vid) {
    if ($vid > 0) {
        $selected_vehicles[$vid] = true;
    }
}

// Pro Fahrzeug: Nur Mitglieder anzeigen, die diesem Fahrzeug zugeordnet sind oder noch keinem Fahrzeug zugeordnet sind.
// Eine Person, die bereits einem anderen Fahrzeug zugeordnet ist, erscheint nicht in der Liste anderer Fahrzeuge.
function members_for_vehicle_dropdown($members, $member_vehicle, $vehicle_id) {
    $on_vehicle = [];
    $others = [];
    $vehicle_id = (int)$vehicle_id;
    foreach ($members as $m) {
        $assigned_vehicle = isset($member_vehicle[$m['id']]) ? (int)$member_vehicle[$m['id']] : 0;
        if ($assigned_vehicle === $vehicle_id) {
            $on_vehicle[] = $m;
        } elseif ($assigned_vehicle === 0) {
            $others[] = $m;
        }
        // Wenn $assigned_vehicle !== 0 und !== $vehicle_id: Person ist anderem Fahrzeug zugeordnet → nicht anzeigen
    }
    return ['on_vehicle' => $on_vehicle, 'others' => $others];
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Anwesenheitsliste – Fahrzeuge - Feuerwehr App</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <style>
        tr.anw-row { cursor: pointer; }
        .anw-row .no-click { cursor: default; }
    </style>
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
                        <h3 class="mb-0"><i class="fas fa-truck"></i> Fahrzeuge – eingesetzte Fahrzeuge</h3>
                        <p class="text-muted mb-0 mt-1"><?php echo date('d.m.Y', strtotime($datum)); ?> – Maschinist und Einheitsführer pro Fahrzeug. Personen, die Sie hier auswählen, werden automatisch dem Personal hinzugefügt.</p>
                    </div>
                    <div class="card-body p-4">
                        <?php if (!empty($_SESSION['anwesenheit_error'])): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($_SESSION['anwesenheit_error']); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Schließen"></button>
                        </div>
                        <?php unset($_SESSION['anwesenheit_error']); endif; ?>
                        <form method="post" id="fahrzeugeForm">
                            <?php
                            if (($draft['typ'] ?? '') === 'einsatz') {
                                $typen_map = get_dienstplan_typen_auswahl();
                                $bez_cur = trim($draft['bezeichnung_sonstige'] ?? 'Einsatz');
                                $typ_key = array_search($bez_cur, $typen_map);
                                if ($typ_key === false) $typ_key = 'einsatz';
                                $ueb_ids = $draft['uebungsleiter_member_ids'] ?? [];
                                if (!is_array($ueb_ids)) $ueb_ids = [];
                                echo '<input type="hidden" name="typ_sonstige" value="' . htmlspecialchars($typ_key) . '">';
                                foreach ($ueb_ids as $uid) {
                                    if ((int)$uid > 0) echo '<input type="hidden" name="uebungsleiter[]" value="' . (int)$uid . '">';
                                }
                            }
                            ?>
                            <p class="text-muted small">Klicken Sie auf einen Fahrzeugnamen, um es als eingesetzt auszuwählen (nochmal klicken zum Abwählen). Pro Fahrzeug können Sie genau einen Maschinisten und einen Einheitsführer festlegen. Jede Person kann nur einem Fahrzeug zugeordnet werden.</p>
                            <?php if (empty($vehicles)): ?>
                                <p class="text-muted">Keine Fahrzeuge in der Datenbank. Bitte in der Fahrzeugverwaltung anlegen.</p>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover anwesenheit-tabelle">
                                        <thead>
                                            <tr>
                                                <th>Fahrzeug</th>
                                                <th>Maschinist</th>
                                                <th>Einheitsführer</th>
                                                <th class="text-center">Besatzung</th>
                                                <th class="text-center">Besatzungsstärke</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($vehicles as $v): ?>
                                            <?php
                                            $groups = members_for_vehicle_dropdown($members, $draft['member_vehicle'], $v['id']);
                                            $masch_val = isset($draft['vehicle_maschinist'][$v['id']]) ? $draft['vehicle_maschinist'][$v['id']] : '';
                                            $einh_val = isset($draft['vehicle_einheitsfuehrer'][$v['id']]) ? $draft['vehicle_einheitsfuehrer'][$v['id']] : '';
                                            $is_selected = isset($selected_vehicles[$v['id']]);
                                            $row_bg = $is_selected ? ' style="background-color: #b6d4fe;"' : '';
                                            $crew_for_vehicle = $groups['on_vehicle'];
                                            $available_for_vehicle = $groups['others'];
                                            $crew_ids = array_column($crew_for_vehicle, 'id');
                                            $besatzungsstaerke = get_besatzungsstaerke($crew_ids, $db);
                                            $crew_json = htmlspecialchars(json_encode(array_map(function($m) { return ['id' => (int)$m['id'], 'name' => $m['last_name'] . ', ' . $m['first_name']]; }, $crew_for_vehicle)), ENT_QUOTES, 'UTF-8');
                                            $available_json = htmlspecialchars(json_encode(array_map(function($m) { return ['id' => (int)$m['id'], 'name' => $m['last_name'] . ', ' . $m['first_name']]; }, $available_for_vehicle)), ENT_QUOTES, 'UTF-8');
                                            ?>
                                            <tr class="anw-row <?php echo $is_selected ? 'selected' : ''; ?>" data-vehicle-id="<?php echo (int)$v['id']; ?>">
                                                <td class="name-cell"<?php echo $row_bg; ?>>
                                                    <input type="hidden" name="vehicle_id[]" value="<?php echo (int)$v['id']; ?>" class="vehicle-id-input" <?php echo $is_selected ? '' : 'disabled'; ?>>
                                                    <span class="d-block py-1 fw-bold"><?php echo htmlspecialchars($v['name']); ?></span>
                                                </td>
                                                <td class="no-click"<?php echo $row_bg; ?>>
                                                    <select class="form-select form-select-sm" name="maschinist[<?php echo (int)$v['id']; ?>]">
                                                        <option value="">— keine Auswahl —</option>
                                                        <?php foreach (array_merge($groups['on_vehicle'], $groups['others']) as $m): ?>
                                                        <option value="<?php echo (int)$m['id']; ?>" <?php echo (int)$masch_val === (int)$m['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($m['last_name'] . ', ' . $m['first_name']); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </td>
                                                <td class="no-click"<?php echo $row_bg; ?>>
                                                    <select class="form-select form-select-sm" name="einheitsfuehrer[<?php echo (int)$v['id']; ?>]">
                                                        <option value="">— keine Auswahl —</option>
                                                        <?php foreach (array_merge($groups['on_vehicle'], $groups['others']) as $m): ?>
                                                        <option value="<?php echo (int)$m['id']; ?>" <?php echo (int)$einh_val === (int)$m['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($m['last_name'] . ', ' . $m['first_name']); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </td>
                                                <td class="no-click text-center"<?php echo $row_bg; ?>>
                                                    <button type="button" class="btn btn-sm btn-outline-primary besatzung-btn" data-vehicle-id="<?php echo (int)$v['id']; ?>" data-vehicle-name="<?php echo htmlspecialchars($v['name'], ENT_QUOTES, 'UTF-8'); ?>" data-crew="<?php echo $crew_json; ?>" data-available="<?php echo $available_json; ?>" title="Besatzung anzeigen und verwalten">
                                                        <i class="fas fa-users"></i> Besatzung
                                                    </button>
                                                </td>
                                                <td class="no-click text-center"<?php echo $row_bg; ?>>
                                                    <span class="badge bg-secondary"><?php echo htmlspecialchars($besatzungsstaerke); ?></span>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                            <div id="maengelHiddenContainer">
                                <?php foreach ($draft['maengel'] as $idx => $m): ?>
                                <input type="hidden" name="maengel[<?php echo (int)$idx; ?>][standort]" value="<?php echo htmlspecialchars($m['standort'] ?? $standort_default); ?>">
                                <input type="hidden" name="maengel[<?php echo (int)$idx; ?>][mangel_an]" value="<?php echo htmlspecialchars($m['mangel_an'] ?? $mangel_an_default); ?>">
                                <input type="hidden" name="maengel[<?php echo (int)$idx; ?>][bezeichnung]" value="<?php echo htmlspecialchars($m['bezeichnung'] ?? ''); ?>">
                                <input type="hidden" name="maengel[<?php echo (int)$idx; ?>][mangel_beschreibung]" value="<?php echo htmlspecialchars($m['mangel_beschreibung'] ?? ''); ?>">
                                <input type="hidden" name="maengel[<?php echo (int)$idx; ?>][ursache]" value="<?php echo htmlspecialchars($m['ursache'] ?? ''); ?>">
                                <input type="hidden" name="maengel[<?php echo (int)$idx; ?>][verbleib]" value="<?php echo htmlspecialchars($m['verbleib'] ?? ''); ?>">
                                <input type="hidden" name="maengel[<?php echo (int)$idx; ?>][aufgenommen_durch]" value="<?php echo htmlspecialchars($m['aufgenommen_durch'] ?? ''); ?>">
                                <input type="hidden" name="maengel[<?php echo (int)$idx; ?>][vehicle_id]" value="<?php echo htmlspecialchars($m['vehicle_id'] ?? ''); ?>">
                                <?php endforeach; ?>
                            </div>
                            <div class="d-flex flex-wrap gap-2 mt-3 align-items-center">
                                <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Übernehmen und zurück</button>
                                <button type="button" class="btn btn-outline-warning btn-sm" data-bs-toggle="modal" data-bs-target="#mangelMeldenModal"><i class="fas fa-exclamation-triangle"></i> Mangel melden</button>
                                <a href="<?php echo htmlspecialchars($back_url); ?>" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Zurück (ohne Speichern)</a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal: Besatzung pro Fahrzeug -->
        <div class="modal fade" id="besatzungModal" tabindex="-1" data-datum="<?php echo htmlspecialchars($datum); ?>" data-auswahl="<?php echo htmlspecialchars($auswahl); ?>">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="besatzungModalTitle"><i class="fas fa-users"></i> Besatzung</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <h6 class="mb-2">Aktuelle Besatzung</h6>
                        <ul class="list-group list-group-flush mb-3" id="besatzungCrewList"></ul>
                        <hr>
                        <h6 class="mb-2">Person hinzufügen</h6>
                        <form method="post" id="besatzungAddForm" class="d-flex gap-2 flex-wrap align-items-end" action="anwesenheitsliste-fahrzeuge.php?datum=<?php echo urlencode($datum); ?>&auswahl=<?php echo urlencode($auswahl); ?>">
                            <input type="hidden" name="add_crew_member" value="1">
                            <input type="hidden" name="besatzung_vehicle_id" id="besatzungVehicleIdInput" value="">
                            <input type="hidden" name="datum" value="<?php echo htmlspecialchars($datum); ?>">
                            <input type="hidden" name="auswahl" value="<?php echo htmlspecialchars($auswahl); ?>">
                            <div class="flex-grow-1" style="min-width: 180px;">
                                <select class="form-select form-select-sm" name="besatzung_member_id" id="besatzungMemberSelect">
                                    <option value="">— Person wählen —</option>
                                </select>
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Hinzufügen</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal Mangel melden -->
        <div class="modal fade" id="mangelMeldenModal" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="fas fa-exclamation-triangle text-warning"></i> Mangel melden</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div id="mangelModalBereitsErfasst" class="mb-3" style="display: none;">
                            <label class="form-label">Bereits erfasste Mängel</label>
                            <ul id="mangelModalBereitsListe" class="list-group list-group-flush small"></ul>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Fahrzeug mit Mangel</label>
                            <select class="form-select" id="mangelModalMaterial">
                                <option value="">-- Bitte wählen --</option>
                                <option value="__anderes__">Anderes Fahrzeug</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Bezeichnung, ggf. Gerätenummer</label>
                            <input type="text" class="form-control" id="mangelModalBezeichnung" placeholder="Wird bei Auswahl vorbelegt, bearbeitbar">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Mangel Beschreibung <span class="text-danger">*</span></label>
                            <textarea class="form-control" id="mangelModalMangelBeschreibung" rows="2" required></textarea>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Ursache</label>
                                <input type="text" class="form-control" id="mangelModalUrsache">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Verbleib</label>
                                <input type="text" class="form-control" id="mangelModalVerbleib" placeholder="Standard: Gerätehaus (bei Fahrzeugen)">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Aufgenommen durch <span class="text-danger">*</span></label>
                            <div class="position-relative">
                                <input type="text" class="form-control" id="mangelModalAufgenommenDisplay" placeholder="Buchstaben eingeben zum Filtern" autocomplete="off">
                                <input type="hidden" id="mangelModalAufgenommenHidden">
                                <div class="list-group position-absolute w-100 mt-1 shadow" id="mangelModalAufgenommenSuggestions" style="z-index: 1055; max-height: 180px; overflow-y: auto; display: none;"></div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Schließen</button>
                        <button type="button" class="btn btn-warning text-dark" id="mangelModalHinzufuegen"><i class="fas fa-save"></i> Speichern</button>
                        <button type="button" class="btn btn-outline-warning text-dark" id="mangelModalWeiterer"><i class="fas fa-plus-circle"></i> Weiterer Mangel</button>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <footer class="bg-light mt-5 py-4">
        <div class="container text-center">
            <p class="text-muted mb-0">&copy; 2025 Boedes Feuerwehr App</p>
        </div>
    </footer>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    (function() {
        var membersData = <?php echo json_encode(array_map(function($m) { return ['id' => (int)$m['id'], 'label' => trim($m['last_name'] . ', ' . $m['first_name'])]; }, $members_list)); ?>;
        var standortDefault = <?php echo json_encode($standort_default); ?>;
        var mangelAnDefault = <?php echo json_encode($mangel_an_default); ?>;
        var maengelIndex = <?php echo count($draft['maengel']); ?>;
        var berichterstellerDisplay = <?php echo json_encode($berichtersteller_display); ?>;
        var berichterstellerId = <?php echo json_encode($berichtersteller); ?>;
        var berichterstellerVehicle = <?php echo json_encode($berichtersteller_vehicle); ?>;

        var matSelect = document.getElementById('mangelModalMaterial');
        var bezeichnungInput = document.getElementById('mangelModalBezeichnung');
        var mangelBeschr = document.getElementById('mangelModalMangelBeschreibung');
        var ursacheInput = document.getElementById('mangelModalUrsache');
        var verbleibInput = document.getElementById('mangelModalVerbleib');
        var aufgenommenDisplay = document.getElementById('mangelModalAufgenommenDisplay');
        var aufgenommenHidden = document.getElementById('mangelModalAufgenommenHidden');
        var aufgenommenSuggestions = document.getElementById('mangelModalAufgenommenSuggestions');
        var modal = document.getElementById('mangelMeldenModal');
        var hinzufuegenBtn = document.getElementById('mangelModalHinzufuegen');
        var weitererBtn = document.getElementById('mangelModalWeiterer');
        var bereitsWrap = document.getElementById('mangelModalBereitsErfasst');
        var bereitsListe = document.getElementById('mangelModalBereitsListe');

        function filterMembers(q) {
            q = (q || '').toLowerCase().trim();
            if (q === '') return membersData;
            return membersData.filter(function(m) { return (m.label || '').toLowerCase().indexOf(q) >= 0; });
        }
        function renderSuggestions(items) {
            aufgenommenSuggestions.innerHTML = '';
            items.forEach(function(item) {
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'list-group-item list-group-item-action list-group-item-light text-start';
                btn.textContent = item.label;
                btn.dataset.id = item.id;
                btn.dataset.label = item.label;
                btn.addEventListener('click', function() {
                    aufgenommenDisplay.value = this.dataset.label;
                    aufgenommenHidden.value = this.dataset.id;
                    aufgenommenSuggestions.style.display = 'none';
                });
                aufgenommenSuggestions.appendChild(btn);
            });
            aufgenommenSuggestions.style.display = items.length > 0 ? 'block' : 'none';
        }
        aufgenommenDisplay.addEventListener('input', function() {
            aufgenommenHidden.value = '';
            renderSuggestions(filterMembers(aufgenommenDisplay.value.trim()));
        });
        aufgenommenDisplay.addEventListener('focus', function() { renderSuggestions(filterMembers(aufgenommenDisplay.value.trim())); });
        aufgenommenDisplay.addEventListener('blur', function() { setTimeout(function() { aufgenommenSuggestions.style.display = 'none'; }, 200); });

        function buildVehicleOptions() {
            var options = [];
            document.querySelectorAll('.anw-row.selected').forEach(function(row) {
                var vid = row.getAttribute('data-vehicle-id') || '';
                var nameCell = row.querySelector('.name-cell span');
                var vname = nameCell ? (nameCell.textContent || '').trim() : '';
                if (vname) options.push({ bezeichnung: vname, label: vname, fahrzeug: vname, vehicle_id: vid });
            });
            return options;
        }

        function populateMaterialSelect() {
            var opts = buildVehicleOptions();
            while (matSelect.options.length > 2) matSelect.remove(2);
            opts.forEach(function(o) {
                var opt = document.createElement('option');
                opt.value = o.bezeichnung;
                opt.dataset.bezeichnung = o.bezeichnung;
                opt.dataset.fahrzeug = o.fahrzeug || '';
                opt.dataset.vehicleId = o.vehicle_id || '';
                opt.textContent = o.label;
                matSelect.insertBefore(opt, matSelect.options[matSelect.options.length - 1]);
            });
        }

        var verbleibDefault = 'Gerätehaus';

        matSelect.addEventListener('change', function() {
            var val = this.value;
            var opt = this.options[this.selectedIndex];
            if (val === '__anderes__') {
                bezeichnungInput.value = '';
            } else if (val && opt) {
                bezeichnungInput.value = (opt.dataset && opt.dataset.bezeichnung) ? opt.dataset.bezeichnung : val;
            }
            verbleibInput.value = verbleibDefault;
        });

        function getExistingMaengel() {
            var container = document.getElementById('maengelHiddenContainer');
            if (!container) return [];
            var items = [];
            var bezeichnungen = container.querySelectorAll('input[name$="[bezeichnung]"]');
            var beschreibungen = container.querySelectorAll('input[name$="[mangel_beschreibung]"]');
            for (var i = 0; i < Math.max(bezeichnungen.length, beschreibungen.length); i++) {
                var bezInp = bezeichnungen[i];
                var beschrInp = beschreibungen[i];
                var idx = null;
                if (bezInp && bezInp.name) {
                    var m = bezInp.name.match(/^maengel\[(\d+)\]/);
                    if (m) idx = m[1];
                } else if (beschrInp && beschrInp.name) {
                    var m2 = beschrInp.name.match(/^maengel\[(\d+)\]/);
                    if (m2) idx = m2[1];
                }
                var bez = bezInp ? bezInp.value : '';
                var beschr = beschrInp ? beschrInp.value : '';
                if (bez || beschr) items.push({ bezeichnung: bez, beschreibung: beschr, index: idx });
            }
            return items;
        }

        function removeMangel(index) {
            var container = document.getElementById('maengelHiddenContainer');
            if (!container) return;
            var idxStr = String(index);
            Array.from(container.querySelectorAll('input[name^="maengel["]')).forEach(function(inp) {
                var m = inp.name.match(/^maengel\[(\d+)\]/);
                if (m && m[1] === idxStr) inp.remove();
            });
            renderBereitsErfasst();
        }

        function renderBereitsErfasst() {
            var items = getExistingMaengel();
            bereitsListe.innerHTML = '';
            if (items.length === 0) {
                bereitsWrap.style.display = 'none';
                return;
            }
            bereitsWrap.style.display = 'block';
            items.forEach(function(m) {
                var li = document.createElement('li');
                li.className = 'list-group-item py-2 d-flex justify-content-between align-items-center';
                var span = document.createElement('span');
                span.textContent = (m.bezeichnung ? m.bezeichnung + ': ' : '') + (m.beschreibung || '');
                if (span.textContent.length > 80) span.textContent = span.textContent.substring(0, 77) + '...';
                li.appendChild(span);
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'btn btn-sm btn-outline-danger';
                btn.title = 'Mangel entfernen';
                btn.innerHTML = '<i class="fas fa-trash"></i>';
                btn.addEventListener('click', function() { removeMangel(m.index); });
                li.appendChild(btn);
                bereitsListe.appendChild(li);
            });
        }

        function resetMangelModal(keepAufgenommen) {
            populateMaterialSelect();
            matSelect.value = '';
            bezeichnungInput.value = '';
            mangelBeschr.value = '';
            ursacheInput.value = '';
            verbleibInput.value = verbleibDefault;
            if (!keepAufgenommen) {
                aufgenommenDisplay.value = berichterstellerDisplay || '';
                aufgenommenHidden.value = berichterstellerId || '';
            }
            renderBereitsErfasst();
        }
        if (modal) {
            modal.addEventListener('show.bs.modal', function() { resetMangelModal(false); });
        }

        function doAddMangel(closeAfter) {
            var bezeichnung = bezeichnungInput.value.trim();
            var mangelBeschrVal = mangelBeschr.value.trim();
            var ursache = ursacheInput.value.trim();
            var verbleib = verbleibInput.value.trim();
            var aufgenommen = aufgenommenHidden.value.trim() || aufgenommenDisplay.value.trim();
            var vehicleId = '';
            var opt = matSelect.options[matSelect.selectedIndex];
            if (opt && opt.dataset && opt.dataset.vehicleId) vehicleId = opt.dataset.vehicleId;
            if (!mangelBeschrVal || !aufgenommen) {
                alert('Bitte füllen Sie Mangel Beschreibung und Aufgenommen durch aus.');
                return;
            }
            var idx = maengelIndex++;
            var container = document.getElementById('maengelHiddenContainer');
            if (!container) return;
            var frag = document.createDocumentFragment();
            ['standort','mangel_an','bezeichnung','mangel_beschreibung','ursache','verbleib','aufgenommen_durch','vehicle_id'].forEach(function(k) {
                var inp = document.createElement('input');
                inp.type = 'hidden';
                inp.name = 'maengel[' + idx + '][' + k + ']';
                inp.value = k === 'standort' ? standortDefault : k === 'mangel_an' ? mangelAnDefault : k === 'bezeichnung' ? bezeichnung : k === 'mangel_beschreibung' ? mangelBeschrVal : k === 'ursache' ? ursache : k === 'verbleib' ? verbleib : k === 'aufgenommen_durch' ? aufgenommen : vehicleId;
                frag.appendChild(inp);
            });
            container.appendChild(frag);
            if (closeAfter) {
                var bsModal = bootstrap.Modal.getInstance(modal);
                if (bsModal) bsModal.hide();
            } else {
                resetMangelModal(true);
            }
        }

        hinzufuegenBtn.addEventListener('click', function() { doAddMangel(true); });
        if (weitererBtn) weitererBtn.addEventListener('click', function() { doAddMangel(false); });
    })();
    window.addEventListener('beforeunload', function() {
        var form = document.getElementById('fahrzeugeForm');
        if (form) {
            var fd = new FormData();
            fd.append('form_type', 'fahrzeuge');
            form.querySelectorAll('.anw-row.selected').forEach(function(row) {
                var vidInput = row.querySelector('.vehicle-id-input');
                var maschSelect = row.querySelector('select[name^="maschinist"]');
                var einhSelect = row.querySelector('select[name^="einheitsfuehrer"]');
                if (vidInput) {
                    fd.append('vehicle_id[]', vidInput.value);
                    if (maschSelect) fd.append('maschinist[' + vidInput.value + ']', maschSelect.value);
                    if (einhSelect) fd.append('einheitsfuehrer[' + vidInput.value + ']', einhSelect.value);
                }
            });
            var maengelContainer = document.getElementById('maengelHiddenContainer');
            if (maengelContainer) {
                maengelContainer.querySelectorAll('input').forEach(function(inp) {
                    fd.append(inp.name, inp.value);
                });
            }
            navigator.sendBeacon('api/save-anwesenheit-draft.php', fd);
        } else {
            navigator.sendBeacon('api/save-anwesenheit-draft.php', '');
        }
    });
    </script>
    <script>
        document.querySelectorAll('.anw-row').forEach(function(row) {
            var nameCell = row.querySelector('.name-cell');
            var hiddenInput = row.querySelector('.vehicle-id-input');
            if (!nameCell || !hiddenInput) return;
            nameCell.addEventListener('click', function(e) {
                e.preventDefault();
                var cells = row.querySelectorAll('td');
                if (hiddenInput.disabled) {
                    hiddenInput.disabled = false;
                    row.classList.add('selected');
                    cells.forEach(function(td) { td.style.backgroundColor = '#b6d4fe'; });
                } else {
                    hiddenInput.disabled = true;
                    row.classList.remove('selected');
                    cells.forEach(function(td) { td.style.backgroundColor = ''; });
                }
            });
        });

        document.querySelectorAll('.besatzung-btn').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                var vid = this.getAttribute('data-vehicle-id');
                var vname = this.getAttribute('data-vehicle-name');
                var crew = [];
                var available = [];
                try {
                    crew = JSON.parse(this.getAttribute('data-crew') || '[]');
                    available = JSON.parse(this.getAttribute('data-available') || '[]');
                } catch (err) {}
                var modal = document.getElementById('besatzungModal');
                var titleEl = document.getElementById('besatzungModalTitle');
                var listEl = document.getElementById('besatzungCrewList');
                var selectEl = document.getElementById('besatzungMemberSelect');
                var vehicleInput = document.getElementById('besatzungVehicleIdInput');
                titleEl.textContent = 'Besatzung: ' + (vname || '');
                vehicleInput.value = vid || '';
                listEl.innerHTML = '';
                crew.forEach(function(p) {
                    var li = document.createElement('li');
                    li.className = 'list-group-item d-flex justify-content-between align-items-center';
                    li.innerHTML = '<span>' + (p.name || '') + '</span>';
                    var form = document.createElement('form');
                    form.method = 'post';
                    form.action = 'anwesenheitsliste-fahrzeuge.php?datum=' + encodeURIComponent(modal.dataset.datum || '') + '&auswahl=' + encodeURIComponent(modal.dataset.auswahl || '');
                    form.style.display = 'inline';
                    form.innerHTML = '<input type="hidden" name="remove_crew_member" value="1">' +
                        '<input type="hidden" name="besatzung_vehicle_id" value="' + (vid || '') + '">' +
                        '<input type="hidden" name="besatzung_member_id" value="' + (p.id || '') + '">' +
                        '<button type="submit" class="btn btn-sm btn-outline-danger">Entfernen</button>';
                    li.appendChild(form);
                    listEl.appendChild(li);
                });
                if (crew.length === 0) {
                    var li = document.createElement('li');
                    li.className = 'list-group-item text-muted';
                    li.textContent = 'Noch keine Besatzung zugewiesen.';
                    listEl.appendChild(li);
                }
                selectEl.innerHTML = '<option value="">— Person wählen —</option>';
                available.forEach(function(p) {
                    var opt = document.createElement('option');
                    opt.value = p.id || '';
                    opt.textContent = p.name || '';
                    selectEl.appendChild(opt);
                });
                new bootstrap.Modal(modal).show();
            });
        });

        // Validierung: 1 Person = 1 Fahrzeug – bei Auswahl prüfen ob Person bereits woanders
        function getUsedMemberIds(excludeSelect) {
            var used = {};
            document.querySelectorAll('.anw-row select[name^="maschinist"], .anw-row select[name^="einheitsfuehrer"]').forEach(function(sel) {
                if (sel === excludeSelect) return;
                var vid = sel.closest('tr').querySelector('.vehicle-id-input');
                var vidVal = vid ? vid.value : '';
                var mid = sel.value ? parseInt(sel.value, 10) : 0;
                if (mid > 0) {
                    if (!used[mid]) used[mid] = [];
                    used[mid].push({ vid: vidVal, role: sel.name.indexOf('maschinist') >= 0 ? 'Maschinist' : 'Einheitsführer' });
                }
            });
            return used;
        }
        document.querySelectorAll('.anw-row select[name^="maschinist"], .anw-row select[name^="einheitsfuehrer"]').forEach(function(sel) {
            sel.dataset.prevVal = sel.value || '';
            sel.addEventListener('change', function() {
                var mid = this.value ? parseInt(this.value, 10) : 0;
                if (mid <= 0) { this.dataset.prevVal = this.value; return; }
                var used = getUsedMemberIds(this);
                var entries = used[mid] || [];
                if (entries.length > 0) {
                    var prevVal = this.dataset.prevVal || '';
                    this.value = prevVal;
                    alert('Eine Person kann nur einem Fahrzeug zugeordnet werden. Diese Person ist bereits als ' + entries[0].role + ' bei einem anderen Fahrzeug eingetragen.');
                    return;
                }
                this.dataset.prevVal = this.value;
            });
        });
    </script>
</body>
</html>
