<?php
/**
 * Anwesenheitsliste – Personal: anwesende Mitglieder auswählen und Fahrzeug zuordnen.
 */
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
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
    header('Location: anwesenheitsliste.php?error=datum' . ($einheit_id > 0 ? '&einheit_id=' . (int)$einheit_id : ''));
    exit;
}

if (isset($_GET['umfrage']) && $_GET['umfrage'] === '1' && (!function_exists('is_superadmin') || !is_superadmin())) {
    $q = $_GET;
    unset($q['umfrage']);
    header('Location: anwesenheitsliste-personal.php?' . http_build_query($q));
    exit;
}

$draft_key = 'anwesenheit_draft';
if (!isset($_SESSION[$draft_key]) || $_SESSION[$draft_key]['datum'] !== $datum || $_SESSION[$draft_key]['auswahl'] !== $auswahl) {
    header('Location: anwesenheitsliste-eingaben.php?datum=' . urlencode($datum) . '&auswahl=' . urlencode($auswahl) . $url_suffix);
    exit;
}
$draft = &$_SESSION[$draft_key];
$is_uebungsdienst_draft = (($draft['typ'] ?? '') === 'einsatz' && trim($draft['bezeichnung_sonstige'] ?? '') === 'Übungsdienst') || (($draft['typ'] ?? '') === 'manuell' && trim($draft['bezeichnung_sonstige'] ?? '') === 'Übungsdienst');
// typ_sonstige und uebungsleiter aus URL übernehmen (vom Hauptformular beim Klick auf Personal)
if (($draft['typ'] ?? '') === 'einsatz' || $is_uebungsdienst_draft) {
    $ts = trim((string)($_GET['typ_sonstige'] ?? ''));
    if ($ts !== '') {
        $typen = get_dienstplan_typen_auswahl();
        $draft['bezeichnung_sonstige'] = $typen[$ts] ?? ($draft['bezeichnung_sonstige'] ?? 'Einsatz');
    }
    if (!empty($_GET['uebungsleiter']) && is_array($_GET['uebungsleiter'])) {
        $draft['uebungsleiter_member_ids'] = array_values(array_map('intval', array_filter($_GET['uebungsleiter'], function($x){return $x!==''&&ctype_digit((string)$x);})));
    }
}

// Mitglieder laden (einheitsspezifisch)
$members = [];
$member_freq = []; // member_id => Anzahl Einsätze (für Umfrage-Sortierung)
$umfrage_mode = isset($_GET['umfrage']) && $_GET['umfrage'] === '1';
$sort_by = isset($_GET['sort']) && $_GET['sort'] === 'name' ? 'name' : 'freq';
try {
    if ($umfrage_mode && $einheit_id > 0) {
        // Häufigkeit: wie oft war Mitglied in Anwesenheitslisten dieser Einheit
        $stmt = $db->prepare("
            SELECT m.id, m.first_name, m.last_name, COALESCE(f.cnt, 0) AS freq
            FROM members m
            LEFT JOIN (
                SELECT am.member_id, COUNT(*) AS cnt
                FROM anwesenheitsliste_mitglieder am
                INNER JOIN anwesenheitslisten a ON a.id = am.anwesenheitsliste_id
                WHERE (a.einheit_id = ? OR a.einheit_id IS NULL)
                GROUP BY am.member_id
            ) f ON f.member_id = m.id
            WHERE (m.einheit_id = ? OR m.einheit_id IS NULL)
            ORDER BY f.cnt DESC, m.last_name, m.first_name
        ");
        $stmt->execute([$einheit_id, $einheit_id]);
        $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($members as $m) {
            $member_freq[(int)$m['id']] = (int)($m['freq'] ?? 0);
        }
        if ($sort_by === 'name') {
            usort($members, function($a, $b) {
                $c = strcasecmp($a['last_name'], $b['last_name']);
                return $c !== 0 ? $c : strcasecmp($a['first_name'], $b['first_name']);
            });
        }
    } elseif ($umfrage_mode) {
        $stmt = $db->prepare("
            SELECT m.id, m.first_name, m.last_name, COALESCE(f.cnt, 0) AS freq
            FROM members m
            LEFT JOIN (
                SELECT am.member_id, COUNT(*) AS cnt
                FROM anwesenheitsliste_mitglieder am
                INNER JOIN anwesenheitslisten a ON a.id = am.anwesenheitsliste_id
                GROUP BY am.member_id
            ) f ON f.member_id = m.id
            ORDER BY f.cnt DESC, m.last_name, m.first_name
        ");
        $stmt->execute();
        $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($members as $m) {
            $member_freq[(int)$m['id']] = (int)($m['freq'] ?? 0);
        }
        if ($sort_by === 'name') {
            usort($members, function($a, $b) {
                $c = strcasecmp($a['last_name'], $b['last_name']);
                return $c !== 0 ? $c : strcasecmp($a['first_name'], $b['first_name']);
            });
        }
    } elseif ($einheit_id > 0) {
        $stmt = $db->prepare("SELECT id, first_name, last_name FROM members WHERE einheit_id = ? OR einheit_id IS NULL ORDER BY last_name, first_name");
        $stmt->execute([$einheit_id]);
        $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt = $db->query("SELECT id, first_name, last_name FROM members ORDER BY last_name, first_name");
        $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    // Tabelle members evtl. in anderem Schema
}
// Fahrzeuge laden (einheitsspezifisch)
$vehicles = [];
try {
    if ($einheit_id > 0) {
        $stmt = $db->prepare("SELECT id, name FROM vehicles WHERE einheit_id = ? OR einheit_id IS NULL ORDER BY name ASC");
        $stmt->execute([$einheit_id]);
    } else {
        $stmt = $db->query("SELECT id, name FROM vehicles ORDER BY name ASC");
    }
    $vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $vehicles = [];
}

// POST: Auswahl speichern und zurück (inkl. Rolle Maschinist/Einheitsführer pro Fahrzeug, PA-Checkbox)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $draft['members'] = [];
    $draft['member_vehicle'] = [];
    $draft['member_pa'] = [];
    if (!empty($_POST['member_id']) && is_array($_POST['member_id'])) {
        foreach ($_POST['member_id'] as $mid) {
            $mid = (int)$mid;
            if ($mid > 0) {
                $draft['members'][] = $mid;
                $vid = isset($_POST['vehicle'][$mid]) ? (int)$_POST['vehicle'][$mid] : 0;
                if ($vid > 0) {
                    $draft['member_vehicle'][$mid] = $vid;
                }
                if (!empty($_POST['member_pa'][$mid])) {
                    $draft['member_pa'][] = $mid;
                }
            }
        }
    }
    // Rollen nur für Fahrzeuge aktualisieren, die in member_vehicle vorkommen (ein Maschinist / ein Einheitsführer pro Fahrzeug)
    $vehicles_in_personal = array_unique(array_values($draft['member_vehicle']));
    foreach ($vehicles_in_personal as $vid) {
        if ($vid <= 0) continue;
        $draft['vehicle_maschinist'][$vid] = null;
        $draft['vehicle_einheitsfuehrer'][$vid] = null;
    }
    if (!empty($_POST['member_id']) && is_array($_POST['member_id'])) {
        foreach ($_POST['member_id'] as $mid) {
            $mid = (int)$mid;
            $vid = isset($_POST['vehicle'][$mid]) ? (int)$_POST['vehicle'][$mid] : 0;
            $role = isset($_POST['role'][$mid]) ? trim($_POST['role'][$mid]) : '';
            if ($vid > 0 && $role === 'maschinist') {
                $draft['vehicle_maschinist'][$vid] = $mid;
            }
            if ($vid > 0 && $role === 'einheitsfuehrer') {
                $draft['vehicle_einheitsfuehrer'][$vid] = $mid;
            }
        }
    }
    // Entfernen von null-Einträgen
    $draft['vehicle_maschinist'] = array_filter($draft['vehicle_maschinist'] ?? []);
    $draft['vehicle_einheitsfuehrer'] = array_filter($draft['vehicle_einheitsfuehrer'] ?? []);
    if (empty($draft['member_pa'])) $draft['member_pa'] = [];
    // typ_sonstige und uebungsleiter aus POST übernehmen (vom Hauptformular, damit sie beim Zurückkehren erhalten bleiben)
    if (isset($_POST['typ_sonstige']) && (($draft['typ'] ?? '') === 'einsatz' || trim($draft['bezeichnung_sonstige'] ?? '') === 'Übungsdienst')) {
        $ts = trim((string)$_POST['typ_sonstige']);
        $typen = get_dienstplan_typen_auswahl();
        $draft['bezeichnung_sonstige'] = $typen[$ts] ?? ($draft['bezeichnung_sonstige'] ?? 'Einsatz');
        if ($ts === 'uebungsdienst') {
            $ids = !empty($_POST['uebungsleiter']) && is_array($_POST['uebungsleiter'])
                ? array_values(array_map('intval', array_filter($_POST['uebungsleiter'], function($x){return $x!==''&&ctype_digit((string)$x);})))
                : [];
            $draft['uebungsleiter_member_ids'] = $ids;
        }
    } elseif (!empty($_POST['uebungsleiter']) && is_array($_POST['uebungsleiter'])) {
        $draft['uebungsleiter_member_ids'] = array_values(array_map('intval', array_filter($_POST['uebungsleiter'], function($x){return $x!==''&&ctype_digit((string)$x);})));
    }
    $umfrage = isset($_GET['umfrage']) && $_GET['umfrage'] === '1';
    if ($umfrage) {
        $redirect = 'anwesenheitsliste-fahrzeuge.php?datum=' . urlencode($datum) . '&auswahl=' . urlencode($auswahl) . '&umfrage=1' . $url_suffix;
    } else {
        $redirect = 'anwesenheitsliste-eingaben.php?datum=' . urlencode($datum) . '&auswahl=' . urlencode($auswahl) . $url_suffix;
    }
    $ts = trim((string)($_POST['typ_sonstige'] ?? ''));
    if ($ts !== '' && (($draft['typ'] ?? '') === 'einsatz' || trim($draft['bezeichnung_sonstige'] ?? '') === 'Übungsdienst')) {
        $redirect .= '&typ_sonstige=' . urlencode($ts);
        $ueb_ids = $draft['uebungsleiter_member_ids'] ?? [];
        foreach ($ueb_ids as $uid) {
            if ((int)$uid > 0) $redirect .= '&uebungsleiter[]=' . (int)$uid;
        }
    }
    header('Location: ' . $redirect);
    exit;
}

$typen_map = get_dienstplan_typen_auswahl();
$bez_cur = trim($draft['bezeichnung_sonstige'] ?? 'Einsatz');
$typ_key = array_search($bez_cur, $typen_map);
if ($typ_key === false) $typ_key = 'einsatz';
$ueb_ids = $draft['uebungsleiter_member_ids'] ?? [];
if (!is_array($ueb_ids)) $ueb_ids = [];
if ($umfrage_mode) {
    $back_url = 'anwesenheitsliste-umfrage-schritt2.php?datum=' . urlencode($datum) . '&auswahl=' . urlencode($auswahl) . ($einheit_id > 0 ? '&einheit_id=' . (int)$einheit_id : '');
} else {
    $back_url = 'anwesenheitsliste-eingaben.php?datum=' . urlencode($datum) . '&auswahl=' . urlencode($auswahl) . $url_suffix;
    if ($typ_key === 'uebungsdienst' || trim($draft['bezeichnung_sonstige'] ?? '') === 'Übungsdienst') {
        $back_url .= '&typ_sonstige=uebungsdienst';
        foreach ($ueb_ids as $uid) {
            if ((int)$uid > 0) $back_url .= '&uebungsleiter[]=' . (int)$uid;
        }
    }
}
$selected_ids = array_flip($draft['members']);
$member_vehicle = $draft['member_vehicle'];
$member_pa = array_flip($draft['member_pa'] ?? []);
$vehicle_maschinist = $draft['vehicle_maschinist'] ?? [];
$vehicle_einheitsfuehrer = $draft['vehicle_einheitsfuehrer'] ?? [];
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Anwesenheitsliste – Personal - Feuerwehr App</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <style>
        tr.anw-row { cursor: pointer; }
        .anw-row .no-click { cursor: default; }
        #personalCardsContainer .card.anw-row:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
        #personalCardsContainer .card.anw-row .card-details .form-select { cursor: pointer; }
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
                        <h3 class="mb-0"><i class="fas fa-users"></i> Personal – Anwesende auswählen</h3>
                        <p class="text-muted mb-0 mt-1"><?php echo date('d.m.Y', strtotime($datum)); ?></p>
                    </div>
                    <div class="card-body p-4">
                        <form method="post" id="personalForm">
                            <input type="hidden" name="typ_sonstige" value="<?php echo htmlspecialchars($typ_key); ?>">
                            <?php foreach ($ueb_ids as $uid): if ((int)$uid > 0): ?>
                            <input type="hidden" name="uebungsleiter[]" value="<?php echo (int)$uid; ?>">
                            <?php endif; endforeach; ?>
                            <?php if ($umfrage_mode): ?>
                            <p class="text-muted small mb-3">Klicken Sie auf eine Kachel, um die Person als anwesend auszuwählen (nochmal klicken zum Abwählen). Optional: Fahrzeug und Rolle zuordnen.</p>
                            <div class="d-flex flex-wrap gap-2 mb-3">
                                <div class="input-group flex-grow-1" style="min-width: 200px;">
                                    <span class="input-group-text"><i class="fas fa-search"></i></span>
                                    <input type="text" class="form-control" id="personalSearch" placeholder="Person suchen..." autocomplete="off">
                                </div>
                                <?php
                                $sort_base = '?datum=' . urlencode($datum) . '&auswahl=' . urlencode($auswahl) . '&umfrage=1' . $url_suffix;
                                if ($typ_key !== '') {
                                    $sort_base .= '&typ_sonstige=' . urlencode($typ_key);
                                    foreach ($ueb_ids as $uid) {
                                        if ((int)$uid > 0) $sort_base .= '&uebungsleiter[]=' . (int)$uid;
                                    }
                                }
                                ?>
                                <div class="btn-group" role="group">
                                    <a href="<?php echo htmlspecialchars($sort_base . '&sort=freq'); ?>" class="btn btn-sm <?php echo $sort_by === 'freq' ? 'btn-primary' : 'btn-outline-secondary'; ?>">KI</a>
                                    <a href="<?php echo htmlspecialchars($sort_base . '&sort=name'); ?>" class="btn btn-sm <?php echo $sort_by === 'name' ? 'btn-primary' : 'btn-outline-secondary'; ?>">Nach Name</a>
                                </div>
                            </div>
                            <?php if (empty($members)): ?>
                                <p class="text-muted">Keine Mitglieder in der Datenbank. Bitte zuerst in der Mitgliederverwaltung anlegen.</p>
                            <?php else: ?>
                                <div class="row g-3" id="personalCardsContainer">
                                    <?php foreach ($members as $m):
                                        $vid_cur = isset($member_vehicle[$m['id']]) ? (int)$member_vehicle[$m['id']] : 0;
                                        $role_cur = '';
                                        if ($vid_cur && isset($vehicle_maschinist[$vid_cur]) && (int)$vehicle_maschinist[$vid_cur] === (int)$m['id']) {
                                            $role_cur = 'maschinist';
                                        } elseif ($vid_cur && isset($vehicle_einheitsfuehrer[$vid_cur]) && (int)$vehicle_einheitsfuehrer[$vid_cur] === (int)$m['id']) {
                                            $role_cur = 'einheitsfuehrer';
                                        }
                                        $pa_checked = isset($member_pa[$m['id']]);
                                        $row_selected = isset($selected_ids[$m['id']]);
                                        $full_name = $m['last_name'] . ', ' . $m['first_name'];
                                        $search_text = strtolower($full_name . ' ' . ($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? ''));
                                    ?>
                                    <div class="col-6 col-md-4 col-lg-3 personal-card-wrapper" data-search="<?php echo htmlspecialchars($search_text); ?>">
                                        <div class="card anw-row h-100 <?php echo $row_selected ? 'selected border-primary' : ''; ?>" data-member-id="<?php echo (int)$m['id']; ?>" style="cursor: pointer; transition: all 0.2s ease; <?php echo $row_selected ? 'background-color: #b6d4fe; box-shadow: 0 0 0 2px var(--bs-primary);' : ''; ?>">
                                            <div class="card-body p-4">
                                                <input type="hidden" name="member_id[]" value="<?php echo (int)$m['id']; ?>" class="member-id-input" <?php echo $row_selected ? '' : 'disabled'; ?>>
                                                <div class="name-cell text-center mb-2">
                                                    <span class="d-block <?php echo $row_selected ? 'fw-bold' : ''; ?>"><?php echo htmlspecialchars($full_name); ?></span>
                                                </div>
                                                <div class="card-details no-click" style="display: <?php echo $row_selected ? 'block' : 'none'; ?>;">
                                                    <div class="mb-2">
                                                        <label class="form-label small mb-0">Fahrzeug</label>
                                                        <select class="form-select form-select-sm" name="vehicle[<?php echo (int)$m['id']; ?>]">
                                                            <option value="">— kein Fahrzeug —</option>
                                                            <?php foreach ($vehicles as $v): ?>
                                                            <option value="<?php echo (int)$v['id']; ?>" <?php echo $vid_cur === (int)$v['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($v['name']); ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <div class="mb-2">
                                                        <label class="form-label small mb-0">Rolle</label>
                                                        <select class="form-select form-select-sm" name="role[<?php echo (int)$m['id']; ?>]">
                                                            <option value="">— keine —</option>
                                                            <option value="maschinist" <?php echo $role_cur === 'maschinist' ? 'selected' : ''; ?>>Maschinist</option>
                                                            <option value="einheitsfuehrer" <?php echo $role_cur === 'einheitsfuehrer' ? 'selected' : ''; ?>>Einheitsführer</option>
                                                        </select>
                                                    </div>
                                                    <div class="form-check">
                                                        <input type="checkbox" class="form-check-input member-pa-check" name="member_pa[<?php echo (int)$m['id']; ?>]" value="1" <?php echo $pa_checked ? 'checked' : ''; ?> <?php echo $row_selected ? '' : 'disabled'; ?> id="pa_<?php echo (int)$m['id']; ?>">
                                                        <label class="form-check-label small" for="pa_<?php echo (int)$m['id']; ?>">PA getragen</label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <?php else: ?>
                            <p class="text-muted small">Klicken Sie auf einen Namen, um die Person als anwesend auszuwählen (nochmal klicken zum Abwählen). Optional: Fahrzeug zuordnen und Rolle (Maschinist/Einheitsführer) – pro Fahrzeug nur je eine Person.</p>
                            <?php if (empty($members)): ?>
                                <p class="text-muted">Keine Mitglieder in der Datenbank. Bitte zuerst in der Mitgliederverwaltung anlegen.</p>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover anwesenheit-tabelle">
                                        <thead>
                                            <tr>
                                                <th>Name</th>
                                                <th>Fahrzeug (optional)</th>
                                                <th>Rolle auf Fahrzeug</th>
                                                <th class="text-center" style="width: 60px;" title="PA = Atemschutz getragen">PA</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($members as $m):
                                                $vid_cur = isset($member_vehicle[$m['id']]) ? (int)$member_vehicle[$m['id']] : 0;
                                                $role_cur = '';
                                                if ($vid_cur && isset($vehicle_maschinist[$vid_cur]) && (int)$vehicle_maschinist[$vid_cur] === (int)$m['id']) {
                                                    $role_cur = 'maschinist';
                                                } elseif ($vid_cur && isset($vehicle_einheitsfuehrer[$vid_cur]) && (int)$vehicle_einheitsfuehrer[$vid_cur] === (int)$m['id']) {
                                                    $role_cur = 'einheitsfuehrer';
                                                }
                                                $pa_checked = isset($member_pa[$m['id']]);
                                            ?>
                                            <?php $row_selected = isset($selected_ids[$m['id']]); $row_bg = $row_selected ? ' style="background-color: #b6d4fe;"' : ''; ?>
                                            <tr class="anw-row <?php echo $row_selected ? 'selected' : ''; ?>" data-member-id="<?php echo (int)$m['id']; ?>">
                                                <td class="name-cell"<?php echo $row_bg; ?>>
                                                    <input type="hidden" name="member_id[]" value="<?php echo (int)$m['id']; ?>" class="member-id-input" <?php echo $row_selected ? '' : 'disabled'; ?>>
                                                    <span class="d-block py-1 <?php echo $row_selected ? 'fw-bold' : ''; ?>"><?php echo htmlspecialchars($m['last_name'] . ', ' . $m['first_name']); ?></span>
                                                </td>
                                                <td class="no-click"<?php echo $row_bg; ?>>
                                                    <select class="form-select form-select-sm" name="vehicle[<?php echo (int)$m['id']; ?>]">
                                                        <option value="">— kein Fahrzeug —</option>
                                                        <?php foreach ($vehicles as $v): ?>
                                                        <option value="<?php echo (int)$v['id']; ?>" <?php echo $vid_cur === (int)$v['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($v['name']); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </td>
                                                <td class="no-click"<?php echo $row_bg; ?>>
                                                    <select class="form-select form-select-sm" name="role[<?php echo (int)$m['id']; ?>]">
                                                        <option value="">— keine —</option>
                                                        <option value="maschinist" <?php echo $role_cur === 'maschinist' ? 'selected' : ''; ?>>Maschinist</option>
                                                        <option value="einheitsfuehrer" <?php echo $role_cur === 'einheitsfuehrer' ? 'selected' : ''; ?>>Einheitsführer</option>
                                                    </select>
                                                </td>
                                                <td class="no-click text-center"<?php echo $row_bg; ?>>
                                                    <input type="checkbox" class="form-check-input member-pa-check" name="member_pa[<?php echo (int)$m['id']; ?>]" value="1" <?php echo $pa_checked ? 'checked' : ''; ?> <?php echo $row_selected ? '' : 'disabled'; ?> title="PA getragen – erstellt Atemschutzeintrag zur Genehmigung">
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                            <?php endif; ?>
                            <div class="d-flex flex-wrap gap-2 mt-3">
                                <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Übernehmen und zurück</button>
                                <a href="<?php echo htmlspecialchars($back_url); ?>" class="btn btn-secondary" id="btnBackOhneSpeichern"><i class="fas fa-arrow-left"></i> Zurück (ohne Speichern)</a>
                            </div>
                        </form>
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
        var discardFlag = 'anwesenheit_discard_personal';
        var btnBack = document.getElementById('btnBackOhneSpeichern');
        if (btnBack) btnBack.addEventListener('click', function(e) {
            e.preventDefault();
            sessionStorage.setItem(discardFlag, '1');
            window.location.href = this.getAttribute('href');
        });
        window.addEventListener('beforeunload', function() {
            if (sessionStorage.getItem(discardFlag) === '1') {
                sessionStorage.removeItem(discardFlag);
                return;
            }
            var form = document.getElementById('personalForm');
            if (form) {
                var fd = new FormData();
                fd.append('form_type', 'personal');
                var typInp = form.querySelector('input[name="typ_sonstige"]');
                if (typInp) fd.append('typ_sonstige', typInp.value);
                form.querySelectorAll('input[name="uebungsleiter[]"]').forEach(function(inp){ if(inp.value) fd.append('uebungsleiter[]', inp.value); });
                form.querySelectorAll('.anw-row.selected').forEach(function(row) {
                    var midInput = row.querySelector('.member-id-input');
                    var vehicleSelect = row.querySelector('select[name^="vehicle"]');
                    var roleSelect = row.querySelector('select[name^="role"]');
                    var paCheck = row.querySelector('.member-pa-check');
                    if (midInput) {
                        fd.append('member_id[]', midInput.value);
                        if (vehicleSelect) fd.append('vehicle[' + midInput.value + ']', vehicleSelect.value);
                        if (roleSelect) fd.append('role[' + midInput.value + ']', roleSelect.value);
                        if (paCheck && paCheck.checked) fd.append('member_pa[' + midInput.value + ']', '1');
                    }
                });
                navigator.sendBeacon('api/save-anwesenheit-draft.php', fd);
            } else {
                navigator.sendBeacon('api/save-anwesenheit-draft.php', '');
            }
        });
    })();
    </script>
    <script>
        (function() {
            var isCardMode = document.getElementById('personalCardsContainer') !== null;
            var searchInput = document.getElementById('personalSearch');
            if (searchInput) {
                searchInput.addEventListener('input', function() {
                    var q = this.value.trim().toLowerCase();
                    document.querySelectorAll('.personal-card-wrapper').forEach(function(wrap) {
                        var show = q === '' || (wrap.getAttribute('data-search') || '').indexOf(q) >= 0;
                        wrap.style.display = show ? '' : 'none';
                    });
                });
            }
            document.querySelectorAll('.anw-row').forEach(function(row) {
                var nameCell = row.querySelector('.name-cell');
                var hiddenInput = row.querySelector('.member-id-input');
                var vehicleSelect = row.querySelector('select[name^="vehicle"]');
                var roleSelect = row.querySelector('select[name^="role"]');
                var paCheck = row.querySelector('.member-pa-check');
                var detailsEl = row.querySelector('.card-details');
                if (!hiddenInput) return;
                function markRowSelected(selected) {
                    if (selected) {
                        hiddenInput.disabled = false;
                        row.classList.add('selected');
                        row.style.backgroundColor = '#b6d4fe';
                        row.style.boxShadow = '0 0 0 2px var(--bs-primary)';
                        row.classList.add('border-primary');
                        if (nameCell) {
                            var span = nameCell.querySelector('.d-block');
                            if (span) span.classList.add('fw-bold');
                        }
                        if (paCheck) paCheck.disabled = false;
                        if (detailsEl) detailsEl.style.display = 'block';
                    } else {
                        hiddenInput.disabled = true;
                        row.classList.remove('selected');
                        row.style.backgroundColor = '';
                        row.style.boxShadow = '';
                        row.classList.remove('border-primary');
                        if (nameCell) {
                            var span = nameCell.querySelector('.d-block');
                            if (span) span.classList.remove('fw-bold');
                        }
                        if (paCheck) { paCheck.disabled = true; paCheck.checked = false; }
                        if (detailsEl) detailsEl.style.display = 'none';
                    }
                    if (!isCardMode && row.querySelectorAll('td').length) {
                        row.querySelectorAll('td').forEach(function(td) {
                            td.style.backgroundColor = selected ? '#b6d4fe' : '';
                        });
                    }
                }
                var clickTarget = isCardMode ? row : (nameCell || row);
                if (clickTarget) {
                    clickTarget.addEventListener('click', function(e) {
                        if (detailsEl && detailsEl.contains(e.target)) return;
                        if (e.target.closest('.no-click')) return;
                        e.preventDefault();
                        markRowSelected(hiddenInput.disabled);
                    });
                }
                if (detailsEl) detailsEl.addEventListener('click', function(e) { e.stopPropagation(); });
                if (vehicleSelect) vehicleSelect.addEventListener('change', function() {
                    if (vehicleSelect.value && vehicleSelect.value !== '') markRowSelected(true);
                });
                if (roleSelect) roleSelect.addEventListener('change', function() {
                    if (roleSelect.value && roleSelect.value !== '') markRowSelected(true);
                });
            });
        })();
    </script>
</body>
</html>
