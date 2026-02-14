<?php
/**
 * Anwesenheitsliste – Hauptlogik (wird von anwesenheitsliste-eingaben.php per require eingebunden).
 */
ob_start();
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/dienstplan-typen.php';

if (!$db) {
    @header('Content-Type: text/html; charset=utf-8');
    die('Datenbankverbindung fehlgeschlagen. Bitte Konfiguration in config/database.php prüfen.');
}
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$datum = isset($_GET['datum']) ? trim($_GET['datum']) : '';
$auswahl = isset($_GET['auswahl']) ? trim($_GET['auswahl']) : '';

if ($datum === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum)) {
    header('Location: anwesenheitsliste.php?error=datum');
    exit;
}

$dienstplan_id = null;
$typ = 'dienst';
$titel_anzeige = '';
$is_einsatz = ($auswahl === 'einsatz');
$bezeichnung_save = null;

if ($is_einsatz) {
    $typ = 'einsatz';
    $titel_anzeige = 'Sonstige Anwesenheit (Einsatz, etc.)';
} else {
    $dienstplan_id = (int)$auswahl;
    if ($dienstplan_id <= 0) {
        header('Location: anwesenheitsliste.php?error=auswahl');
        exit;
    }
    try {
        $stmt = $db->prepare("SELECT id, datum, bezeichnung, typ FROM dienstplan WHERE id = ?");
        $stmt->execute([$dienstplan_id]);
        $dienst = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$dienst) {
            header('Location: anwesenheitsliste.php?error=auswahl');
            exit;
        }
        $typ_label = get_dienstplan_typ_label($dienst['typ'] ?? 'uebungsdienst');
        $titel_anzeige = $dienst['bezeichnung'] . ' (' . $typ_label . ' · ' . date('d.m.Y', strtotime($dienst['datum'])) . ')';
    } catch (Exception $e) {
        header('Location: anwesenheitsliste.php?error=auswahl');
        exit;
    }
}

// Session-Draft für diese Anwesenheitsliste (ein Draft pro User)
$draft_key = 'anwesenheit_draft';
if (!isset($_SESSION[$draft_key]) || $_SESSION[$draft_key]['datum'] !== $datum || $_SESSION[$draft_key]['auswahl'] !== $auswahl) {
    $_SESSION[$draft_key] = [
        'datum' => $datum,
        'auswahl' => $auswahl,
        'dienstplan_id' => $is_einsatz ? null : $dienstplan_id,
        'typ' => $typ,
        'bezeichnung_sonstige' => null,
        'bemerkung' => '',
        'members' => [],
        'member_vehicle' => [],
        'vehicles' => [],
        'vehicle_maschinist' => [],
        'vehicle_einheitsfuehrer' => [],
        'uhrzeit_von' => '',
        'uhrzeit_bis' => '',
        'alarmierung_durch' => '',
        'einsatzstelle' => '',
        'objekt' => '',
        'eigentuemer' => '',
        'geschaedigter' => '',
        'klassifizierung' => '',
        'kostenpflichtiger_einsatz' => '',
        'personenschaeden' => '',
        'brandwache' => '',
        'einsatzleiter_member_id' => null,
        'einsatzleiter_freitext' => '',
    ];
    $_SESSION[$draft_key]['uhrzeit_bis'] = date('H:i');
}
$draft = &$_SESSION[$draft_key];

// Fehlende Draft-Keys ergänzen (z. B. nach Update oder alter Session)
$draft_defaults = [
    'uhrzeit_von' => '', 'uhrzeit_bis' => $draft['uhrzeit_bis'] ?? date('H:i'),
    'alarmierung_durch' => '', 'einsatzstelle' => '', 'objekt' => '', 'eigentuemer' => '', 'geschaedigter' => '',
    'klassifizierung' => '', 'kostenpflichtiger_einsatz' => '', 'personenschaeden' => '', 'brandwache' => '',
    'einsatzleiter_member_id' => null, 'einsatzleiter_freitext' => '',
];
foreach ($draft_defaults as $k => $v) {
    if (!array_key_exists($k, $draft)) {
        $draft[$k] = $v;
    }
}
if (!is_array($draft['members'])) $draft['members'] = [];
if (!is_array($draft['member_vehicle'])) $draft['member_vehicle'] = [];
if (!is_array($draft['vehicles'])) $draft['vehicles'] = [];
if (!is_array($draft['vehicle_maschinist'])) $draft['vehicle_maschinist'] = [];
if (!is_array($draft['vehicle_einheitsfuehrer'])) $draft['vehicle_einheitsfuehrer'] = [];

// Sicherstellen, dass anwesenheitslisten alle Spalten hat (falls Nutzer direkt diese Seite aufruft)
$extra_columns = [
    'uhrzeit_von TIME NULL', 'uhrzeit_bis TIME NULL', 'alarmierung_durch VARCHAR(100) NULL',
    'einsatzstelle VARCHAR(255) NULL', 'objekt TEXT NULL', 'eigentuemer VARCHAR(255) NULL', 'geschaedigter VARCHAR(255) NULL',
    'klassifizierung VARCHAR(100) NULL', 'kostenpflichtiger_einsatz VARCHAR(10) NULL', 'personenschaeden VARCHAR(50) NULL',
    'brandwache VARCHAR(10) NULL', 'einsatzleiter_member_id INT NULL', 'einsatzleiter_freitext VARCHAR(255) NULL',
];
foreach ($extra_columns as $colDef) {
    try {
        $db->exec("ALTER TABLE anwesenheitslisten ADD COLUMN " . $colDef);
    } catch (Throwable $e) {
        // Spalte existiert bereits oder Tabelle existiert noch nicht
    }
}

// Mitglieder für Einsatzleiter-Dropdown (zuerst die bei Personal ausgewählten)
$members_list = [];
try {
    $stmt = $db->query("SELECT id, first_name, last_name FROM members ORDER BY last_name, first_name");
    $members_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $members_list = [];
}
$members_selected_ids = array_flip($draft['members']);
$members_for_einsatzleiter = [];
foreach ($members_list as $m) {
    if (isset($members_selected_ids[$m['id']])) {
        $members_for_einsatzleiter[] = $m;
    }
}
foreach ($members_list as $m) {
    if (!isset($members_selected_ids[$m['id']])) {
        $members_for_einsatzleiter[] = $m;
    }
}

$message = '';
$error = '';

// Anwesenheitsliste-Einstellungen laden (Felder, Optionen, Sichtbarkeit)
$anwesenheitsliste_settings = [];
try {
    $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'anwesenheitsliste_%'");
    $stmt->execute();
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $anwesenheitsliste_settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Exception $e) {
    $anwesenheitsliste_settings = [];
}
$default_opts = [
    'alarmierung' => ['Telefon', 'DME Löschzug', 'DME Kleinhilfe', 'Sirene'],
    'klassifizierung' => ['Grossbrand', 'Mittelbrand', 'Kleinbrand', 'Gelöschtes Feuer', 'Gefahrenmeldeanlage', 'Menschen in Notlage', 'Tiere in Notlage', 'Verkehrsunfall', 'Techn. Hilfeleistung', 'Wasserrettung', 'CBRN-Einsatz', 'Unterstützung RD', 'Sonstiger Einsatz', 'Fehlalarm', 'Böswill. Alarm'],
    'personenschaeden' => ['Ja', 'Nein', 'Person gerettet', 'Person verstorben'],
    'kostenpflichtiger' => ['Ja', 'Nein'],
    'brandwache' => ['Ja', 'Nein'],
];
$alarmierung_optionen = _anwesenheitsliste_optionen($anwesenheitsliste_settings, 'alarmierung_optionen', $default_opts['alarmierung']);
$klassifizierung_optionen = _anwesenheitsliste_optionen($anwesenheitsliste_settings, 'klassifizierung_optionen', $default_opts['klassifizierung']);
$personenschaeden_optionen = _anwesenheitsliste_optionen($anwesenheitsliste_settings, 'personenschaeden_optionen', $default_opts['personenschaeden']);
$kostenpflichtiger_optionen = _anwesenheitsliste_optionen($anwesenheitsliste_settings, 'kostenpflichtiger_optionen', $default_opts['kostenpflichtiger']);
$brandwache_optionen = _anwesenheitsliste_optionen($anwesenheitsliste_settings, 'brandwache_optionen', $default_opts['brandwache']);
$anwesenheitsliste_labels = _anwesenheitsliste_labels($anwesenheitsliste_settings);
$anwesenheitsliste_sichtbar = _anwesenheitsliste_sichtbar($anwesenheitsliste_settings);
function _anwesenheitsliste_optionen($s, $key, $def) {
    $raw = $s['anwesenheitsliste_' . $key] ?? '';
    if ($raw === '') return $def;
    $arr = json_decode($raw, true);
    return is_array($arr) && !empty($arr) ? $arr : $def;
}
function _anwesenheitsliste_labels($s) {
    $raw = $s['anwesenheitsliste_feld_labels'] ?? '';
    if ($raw === '') return [];
    $arr = json_decode($raw, true);
    return is_array($arr) ? $arr : [];
}
function _anwesenheitsliste_sichtbar($s) {
    $raw = $s['anwesenheitsliste_felder_sichtbar'] ?? '';
    if ($raw === '') return [];
    $arr = json_decode($raw, true);
    return is_array($arr) ? $arr : [];
}
function _anwesenheitsliste_label($fk, $def, $labels) {
    return isset($labels[$fk]) && $labels[$fk] !== '' ? $labels[$fk] : $def;
}
function _anwesenheitsliste_visible($fk, $sichtbar) {
    return empty($sichtbar) || ($sichtbar[$fk] ?? '1') === '1';
}

// Speichern (nur auf dieser Seite): Liste anlegen + Personal/Fahrzeuge aus Session
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_final'])) {
    $typ_sonstige = trim($_POST['typ_sonstige'] ?? '');
    $typ_sonstige_freitext = trim($_POST['typ_sonstige_freitext'] ?? '');
    if (_anwesenheitsliste_visible('bemerkung', $anwesenheitsliste_sichtbar)) $draft['bemerkung'] = trim($_POST['bemerkung'] ?? '');
    if (_anwesenheitsliste_visible('uhrzeit_von', $anwesenheitsliste_sichtbar)) $draft['uhrzeit_von'] = trim($_POST['uhrzeit_von'] ?? '');
    if (_anwesenheitsliste_visible('uhrzeit_bis', $anwesenheitsliste_sichtbar)) $draft['uhrzeit_bis'] = trim($_POST['uhrzeit_bis'] ?? '');
    if (_anwesenheitsliste_visible('alarmierung_durch', $anwesenheitsliste_sichtbar)) $draft['alarmierung_durch'] = trim($_POST['alarmierung_durch'] ?? '');
    if (_anwesenheitsliste_visible('einsatzstelle', $anwesenheitsliste_sichtbar)) $draft['einsatzstelle'] = trim($_POST['einsatzstelle'] ?? '');
    if (_anwesenheitsliste_visible('objekt', $anwesenheitsliste_sichtbar)) $draft['objekt'] = trim($_POST['objekt'] ?? '');
    if (_anwesenheitsliste_visible('eigentuemer', $anwesenheitsliste_sichtbar)) $draft['eigentuemer'] = trim($_POST['eigentuemer'] ?? '');
    if (_anwesenheitsliste_visible('geschaedigter', $anwesenheitsliste_sichtbar)) $draft['geschaedigter'] = trim($_POST['geschaedigter'] ?? '');
    if (_anwesenheitsliste_visible('klassifizierung', $anwesenheitsliste_sichtbar)) $draft['klassifizierung'] = trim($_POST['klassifizierung'] ?? '');
    if (_anwesenheitsliste_visible('kostenpflichtiger_einsatz', $anwesenheitsliste_sichtbar)) $draft['kostenpflichtiger_einsatz'] = trim($_POST['kostenpflichtiger_einsatz'] ?? '');
    if (_anwesenheitsliste_visible('personenschaeden', $anwesenheitsliste_sichtbar)) $draft['personenschaeden'] = trim($_POST['personenschaeden'] ?? '');
    if (_anwesenheitsliste_visible('brandwache', $anwesenheitsliste_sichtbar)) $draft['brandwache'] = trim($_POST['brandwache'] ?? '');
    if (_anwesenheitsliste_visible('einsatzleiter', $anwesenheitsliste_sichtbar)) {
        $einsatzleiter_val = trim($_POST['einsatzleiter'] ?? '');
        if ($einsatzleiter_val === '__freitext__') {
            $draft['einsatzleiter_member_id'] = null;
            $draft['einsatzleiter_freitext'] = trim($_POST['einsatzleiter_freitext'] ?? '');
        } elseif ($einsatzleiter_val !== '' && ctype_digit($einsatzleiter_val)) {
            $mid = (int)$einsatzleiter_val;
            $draft['einsatzleiter_member_id'] = $mid;
            $draft['einsatzleiter_freitext'] = '';
            if (!in_array($mid, $draft['members'])) {
                $draft['members'][] = $mid;
            }
        } else {
            $draft['einsatzleiter_member_id'] = null;
            $draft['einsatzleiter_freitext'] = '';
        }
    }
    if ($draft['typ'] === 'einsatz') {
        if ($typ_sonstige === '__custom__') {
            $draft['bezeichnung_sonstige'] = $typ_sonstige_freitext !== '' ? $typ_sonstige_freitext : 'Sonstiges';
        } else {
            $draft['bezeichnung_sonstige'] = get_dienstplan_typen_auswahl()[$typ_sonstige] ?? 'Einsatz';
        }
    }
    $dp_id = $draft['dienstplan_id'];
    $typ_save = $draft['typ'];
    $bezeichnung_save = $draft['typ'] === 'einsatz' ? $draft['bezeichnung_sonstige'] : null;
    $uhrzeit_von_save = $draft['uhrzeit_von'] !== '' ? $draft['uhrzeit_von'] : null;
    $uhrzeit_bis_save = $draft['uhrzeit_bis'] !== '' ? $draft['uhrzeit_bis'] : null;
    try {
        try {
            $db->exec("ALTER TABLE anwesenheitslisten ADD COLUMN bemerkung TEXT NULL");
        } catch (Exception $e) {
            // ignore
        }
        $stmt = $db->prepare("
            INSERT INTO anwesenheitslisten (datum, dienstplan_id, typ, bezeichnung, user_id, bemerkung,
                uhrzeit_von, uhrzeit_bis, alarmierung_durch, einsatzstelle, objekt, eigentuemer, geschaedigter,
                klassifizierung, kostenpflichtiger_einsatz, personenschaeden, brandwache, einsatzleiter_member_id, einsatzleiter_freitext)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $draft['datum'], $dp_id, $typ_save, $bezeichnung_save, $_SESSION['user_id'], $draft['bemerkung'] !== '' ? $draft['bemerkung'] : null,
            $uhrzeit_von_save, $uhrzeit_bis_save,
            $draft['alarmierung_durch'] !== '' ? $draft['alarmierung_durch'] : null,
            $draft['einsatzstelle'] !== '' ? $draft['einsatzstelle'] : null,
            $draft['objekt'] !== '' ? $draft['objekt'] : null,
            $draft['eigentuemer'] !== '' ? $draft['eigentuemer'] : null,
            $draft['geschaedigter'] !== '' ? $draft['geschaedigter'] : null,
            $draft['klassifizierung'] !== '' ? $draft['klassifizierung'] : null,
            $draft['kostenpflichtiger_einsatz'] !== '' ? $draft['kostenpflichtiger_einsatz'] : null,
            $draft['personenschaeden'] !== '' ? $draft['personenschaeden'] : null,
            $draft['brandwache'] !== '' ? $draft['brandwache'] : null,
            $draft['einsatzleiter_member_id'] ?? null,
            $draft['einsatzleiter_freitext'] !== '' ? $draft['einsatzleiter_freitext'] : null
        ]);
        $list_id = $db->lastInsertId();
        foreach ($draft['members'] as $mid) {
            $vid = isset($draft['member_vehicle'][$mid]) ? $draft['member_vehicle'][$mid] : null;
            $stmt = $db->prepare("INSERT INTO anwesenheitsliste_mitglieder (anwesenheitsliste_id, member_id, vehicle_id) VALUES (?, ?, ?)");
            $stmt->execute([$list_id, $mid, $vid]);
        }
        $all_vehicle_ids = array_unique(array_merge($draft['vehicles'], array_values(array_filter($draft['member_vehicle']))));
        foreach ($all_vehicle_ids as $vid) {
            if ((int)$vid <= 0) continue;
            $masch = isset($draft['vehicle_maschinist'][$vid]) ? $draft['vehicle_maschinist'][$vid] : null;
            $einh = isset($draft['vehicle_einheitsfuehrer'][$vid]) ? $draft['vehicle_einheitsfuehrer'][$vid] : null;
            try {
                $stmt = $db->prepare("INSERT INTO anwesenheitsliste_fahrzeuge (anwesenheitsliste_id, vehicle_id, maschinist_member_id, einheitsfuehrer_member_id) VALUES (?, ?, ?, ?)");
                $stmt->execute([$list_id, $vid, $masch, $einh]);
            } catch (Exception $e) {
                // Tabelle evtl. nicht vorhanden
            }
        }
        unset($_SESSION[$draft_key]);
        header('Location: anwesenheitsliste.php?message=erfolg');
        exit;
    } catch (Exception $e) {
        $error = 'Speichern fehlgeschlagen: ' . $e->getMessage();
    }
}

$back_url = 'anwesenheitsliste-eingaben.php?datum=' . urlencode($datum) . '&auswahl=' . urlencode($auswahl);
$personal_url = 'anwesenheitsliste-personal.php?datum=' . urlencode($datum) . '&auswahl=' . urlencode($auswahl);
$fahrzeuge_url = 'anwesenheitsliste-fahrzeuge.php?datum=' . urlencode($datum) . '&auswahl=' . urlencode($auswahl);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Anwesenheitsliste – Personal & Fahrzeuge - Feuerwehr App</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <style>
        .anwesenheits-option-btn { min-height: 140px; display: flex; flex-direction: column; align-items: center; justify-content: center; text-decoration: none; transition: transform 0.2s ease, box-shadow 0.2s ease; }
        .anwesenheits-option-btn:hover { transform: translateY(-3px); box-shadow: 0 6px 16px rgba(0,0,0,0.15); }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="index.php"><i class="fas fa-fire"></i> Feuerwehr App</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav"><span class="navbar-toggler-icon"></span></button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item"><a class="nav-link" href="index.php"><i class="fas fa-home"></i> Startseite</a></li>
                    <li class="nav-item"><a class="nav-link" href="formulare.php"><i class="fas fa-file-alt"></i> Formulare</a></li>
                    <li class="nav-item"><a class="nav-link" href="admin/dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
                            <i class="fas fa-user"></i> <?php echo htmlspecialchars(trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')) ?: 'Benutzer'); ?>
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="admin/profile.php"><i class="fas fa-user-edit"></i> Profil</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="logout.php"><i class="fas fa-sign-out-alt"></i> Abmelden</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
    <main class="container mt-4">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="card shadow">
                    <div class="card-header"><h3 class="mb-0"><i class="fas fa-clipboard-list"></i> Anwesenheitsliste – Personal & Fahrzeuge</h3></div>
                    <div class="card-body p-4">
                        <?php if ($error): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
                        <div class="alert alert-light border mb-4">
                            <strong>Gewählt:</strong><br>
                            <span class="text-muted"><?php echo date('d.m.Y', strtotime($datum)); ?></span> — <?php echo htmlspecialchars($titel_anzeige); ?>
                        </div>
                        <form method="post" id="mainForm">
                            <input type="hidden" name="save_final" value="1">
                            <p class="form-label mb-2">Personal und Fahrzeuge erfassen:</p>
                            <div class="row g-3 mb-4">
                                <div class="col-md-6">
                                    <a href="<?php echo htmlspecialchars($personal_url); ?>" class="btn btn-primary w-100 anwesenheits-option-btn">
                                        <i class="fas fa-users fa-2x mb-2"></i><span>Personal</span>
                                        <small class="d-block mt-1 opacity-90">Anwesende auswählen, Fahrzeug zuordnen</small>
                                    </a>
                                </div>
                                <div class="col-md-6">
                                    <a href="<?php echo htmlspecialchars($fahrzeuge_url); ?>" class="btn btn-outline-primary w-100 anwesenheits-option-btn">
                                        <i class="fas fa-truck fa-2x mb-2"></i><span>Fahrzeuge</span>
                                        <small class="d-block mt-1 opacity-90">Eingesetzte Fahrzeuge, Maschinist & Einheitsführer</small>
                                    </a>
                                </div>
                            </div>
                            <?php if (_anwesenheitsliste_visible('uhrzeit_von', $anwesenheitsliste_sichtbar) || _anwesenheitsliste_visible('uhrzeit_bis', $anwesenheitsliste_sichtbar)): ?>
                            <div class="row g-3 mb-4">
                                <?php if (_anwesenheitsliste_visible('uhrzeit_von', $anwesenheitsliste_sichtbar)): ?>
                                <div class="col-md-6">
                                    <label for="uhrzeit_von" class="form-label"><?php echo htmlspecialchars(_anwesenheitsliste_label('uhrzeit_von', 'Uhrzeit von', $anwesenheitsliste_labels)); ?></label>
                                    <input type="time" class="form-control" id="uhrzeit_von" name="uhrzeit_von" value="<?php echo htmlspecialchars($draft['uhrzeit_von'] ?? ''); ?>">
                                </div>
                                <?php endif; ?>
                                <?php if (_anwesenheitsliste_visible('uhrzeit_bis', $anwesenheitsliste_sichtbar)): ?>
                                <div class="col-md-6">
                                    <label for="uhrzeit_bis" class="form-label"><?php echo htmlspecialchars(_anwesenheitsliste_label('uhrzeit_bis', 'Uhrzeit bis', $anwesenheitsliste_labels)); ?></label>
                                    <input type="time" class="form-control" id="uhrzeit_bis" name="uhrzeit_bis" value="<?php echo htmlspecialchars(!empty($draft['uhrzeit_bis']) ? $draft['uhrzeit_bis'] : date('H:i')); ?>">
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                            <?php if (_anwesenheitsliste_visible('einsatzleiter', $anwesenheitsliste_sichtbar)): ?>
                            <div class="mb-3">
                                <label for="einsatzleiter" class="form-label"><?php echo htmlspecialchars(_anwesenheitsliste_label('einsatzleiter', 'Einsatzleiter', $anwesenheitsliste_labels)); ?></label>
                                <select class="form-select" id="einsatzleiter" name="einsatzleiter">
                                    <option value="">— keine Auswahl —</option>
                                    <?php foreach ($members_for_einsatzleiter as $m):
                                        $sel = (isset($draft['einsatzleiter_member_id']) && (int)$draft['einsatzleiter_member_id'] === (int)$m['id']) ? ' selected' : '';
                                    ?>
                                        <option value="<?php echo (int)$m['id']; ?>"<?php echo $sel; ?>><?php echo htmlspecialchars($m['last_name'] . ', ' . $m['first_name']); ?></option>
                                    <?php endforeach; ?>
                                    <?php $sel_f = !empty($draft['einsatzleiter_freitext']) ? ' selected' : ''; ?>
                                    <option value="__freitext__"<?php echo $sel_f; ?>>— Freitext (z. B. andere Feuerwehr) —</option>
                                </select>
                                <div class="mt-2" id="einsatzleiter_freitext_wrap" style="display: <?php echo !empty($draft['einsatzleiter_freitext']) ? 'block' : 'none'; ?>;">
                                    <input type="text" class="form-control" id="einsatzleiter_freitext" name="einsatzleiter_freitext" placeholder="Name Einsatzleiter (z. B. andere Feuerwehr)" value="<?php echo htmlspecialchars($draft['einsatzleiter_freitext'] ?? ''); ?>">
                                </div>
                            </div>
                            <?php endif; ?>
                            <?php if (_anwesenheitsliste_visible('alarmierung_durch', $anwesenheitsliste_sichtbar)): ?>
                            <div class="mb-3">
                                <label for="alarmierung_durch" class="form-label"><?php echo htmlspecialchars(_anwesenheitsliste_label('alarmierung_durch', 'Alarmierung durch', $anwesenheitsliste_labels)); ?></label>
                                <select class="form-select" id="alarmierung_durch" name="alarmierung_durch">
                                    <option value="">— keine Auswahl —</option>
                                    <?php foreach ($alarmierung_optionen as $opt): ?>
                                        <option value="<?php echo htmlspecialchars($opt); ?>" <?php echo ($draft['alarmierung_durch'] ?? '') === $opt ? 'selected' : ''; ?>><?php echo htmlspecialchars($opt); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>
                            <?php if (_anwesenheitsliste_visible('einsatzstelle', $anwesenheitsliste_sichtbar)): ?>
                            <div class="mb-3 position-relative">
                                <label for="einsatzstelle" class="form-label"><?php echo htmlspecialchars(_anwesenheitsliste_label('einsatzstelle', 'Einsatzstelle', $anwesenheitsliste_labels)); ?></label>
                                <input type="text" class="form-control" id="einsatzstelle" name="einsatzstelle" placeholder="Adresse eingeben (Autovervollständigung)" value="<?php echo htmlspecialchars($draft['einsatzstelle'] ?? ''); ?>" autocomplete="off">
                                <div id="einsatzstelle_suggestions" class="list-group position-absolute w-100 mt-1 shadow" style="z-index: 1050; max-height: 200px; overflow-y: auto; display: none;"></div>
                            </div>
                            <?php endif; ?>
                            <?php if (_anwesenheitsliste_visible('objekt', $anwesenheitsliste_sichtbar)): ?>
                            <div class="mb-3"><label for="objekt" class="form-label"><?php echo htmlspecialchars(_anwesenheitsliste_label('objekt', 'Objekt', $anwesenheitsliste_labels)); ?></label>
                                <input type="text" class="form-control" id="objekt" name="objekt" placeholder="Freitext" value="<?php echo htmlspecialchars($draft['objekt'] ?? ''); ?>"></div>
                            <?php endif; ?>
                            <?php if (_anwesenheitsliste_visible('eigentuemer', $anwesenheitsliste_sichtbar)): ?>
                            <div class="mb-3"><label for="eigentuemer" class="form-label"><?php echo htmlspecialchars(_anwesenheitsliste_label('eigentuemer', 'Eigentümer', $anwesenheitsliste_labels)); ?></label>
                                <input type="text" class="form-control" id="eigentuemer" name="eigentuemer" placeholder="Freitext" value="<?php echo htmlspecialchars($draft['eigentuemer'] ?? ''); ?>"></div>
                            <?php endif; ?>
                            <?php if (_anwesenheitsliste_visible('geschaedigter', $anwesenheitsliste_sichtbar)): ?>
                            <div class="mb-3"><label for="geschaedigter" class="form-label"><?php echo htmlspecialchars(_anwesenheitsliste_label('geschaedigter', 'Geschädigter', $anwesenheitsliste_labels)); ?></label>
                                <input type="text" class="form-control" id="geschaedigter" name="geschaedigter" placeholder="Freitext" value="<?php echo htmlspecialchars($draft['geschaedigter'] ?? ''); ?>"></div>
                            <?php endif; ?>
                            <?php if (_anwesenheitsliste_visible('klassifizierung', $anwesenheitsliste_sichtbar)): ?>
                            <div class="mb-3">
                                <label for="klassifizierung" class="form-label"><?php echo htmlspecialchars(_anwesenheitsliste_label('klassifizierung', 'Klassifizierung / Stichwörter', $anwesenheitsliste_labels)); ?></label>
                                <select class="form-select" id="klassifizierung" name="klassifizierung">
                                    <option value="">— keine Auswahl —</option>
                                    <?php foreach ($klassifizierung_optionen as $opt): ?>
                                        <option value="<?php echo htmlspecialchars($opt); ?>" <?php echo ($draft['klassifizierung'] ?? '') === $opt ? 'selected' : ''; ?>><?php echo htmlspecialchars($opt); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>
                            <?php if (_anwesenheitsliste_visible('kostenpflichtiger_einsatz', $anwesenheitsliste_sichtbar)): ?>
                            <div class="mb-3">
                                <label class="form-label"><?php echo htmlspecialchars(_anwesenheitsliste_label('kostenpflichtiger_einsatz', 'Kostenpflichtiger Einsatz', $anwesenheitsliste_labels)); ?></label>
                                <div class="d-flex gap-3">
                                    <?php foreach ($kostenpflichtiger_optionen as $opt):
                                        $val = htmlspecialchars($opt);
                                        $draftVal = $draft['kostenpflichtiger_einsatz'] ?? '';
                                        $sel = ($draftVal === $opt || strtolower($draftVal) === strtolower($opt)) ? ' checked' : '';
                                    ?>
                                    <div class="form-check"><input class="form-check-input" type="radio" name="kostenpflichtiger_einsatz" id="kosten_<?php echo preg_replace('/[^a-z0-9]/', '_', strtolower($opt)); ?>" value="<?php echo $val; ?>"<?php echo $sel; ?>><label class="form-check-label" for="kosten_<?php echo preg_replace('/[^a-z0-9]/', '_', strtolower($opt)); ?>"><?php echo htmlspecialchars($opt); ?></label></div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                            <?php if (_anwesenheitsliste_visible('personenschaeden', $anwesenheitsliste_sichtbar)): ?>
                                <label for="personenschaeden" class="form-label"><?php echo htmlspecialchars(_anwesenheitsliste_label('personenschaeden', 'Personenschäden', $anwesenheitsliste_labels)); ?></label>
                                <select class="form-select" id="personenschaeden" name="personenschaeden">
                                    <option value="">— keine Auswahl —</option>
                                    <?php foreach ($personenschaeden_optionen as $opt): ?>
                                        <option value="<?php echo htmlspecialchars($opt); ?>" <?php echo ($draft['personenschaeden'] ?? '') === $opt ? 'selected' : ''; ?>><?php echo htmlspecialchars($opt); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>
                            <?php if (_anwesenheitsliste_visible('brandwache', $anwesenheitsliste_sichtbar)): ?>
                            <div class="mb-4">
                                <label class="form-label"><?php echo htmlspecialchars(_anwesenheitsliste_label('brandwache', 'Brandwache', $anwesenheitsliste_labels)); ?></label>
                                <div class="d-flex gap-3">
                                    <?php foreach ($brandwache_optionen as $opt):
                                        $val = htmlspecialchars($opt);
                                        $draftVal = $draft['brandwache'] ?? '';
                                        $sel = ($draftVal === $opt || strtolower($draftVal) === strtolower($opt)) ? ' checked' : '';
                                    ?>
                                    <div class="form-check"><input class="form-check-input" type="radio" name="brandwache" id="brandwache_<?php echo preg_replace('/[^a-z0-9]/', '_', strtolower($opt)); ?>" value="<?php echo $val; ?>"<?php echo $sel; ?>><label class="form-check-label" for="brandwache_<?php echo preg_replace('/[^a-z0-9]/', '_', strtolower($opt)); ?>"><?php echo htmlspecialchars($opt); ?></label></div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                            <?php if ($is_einsatz): ?>
                            <div class="mb-4">
                                <label for="typ_sonstige" class="form-label">Typ</label>
                                <select class="form-select" id="typ_sonstige" name="typ_sonstige">
                                    <?php foreach (get_dienstplan_typen_auswahl() as $key => $label): ?>
                                        <option value="<?php echo htmlspecialchars($key); ?>" <?php echo $key === 'einsatz' ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                                    <?php endforeach; ?>
                                    <option value="__custom__">— Anderer Typ (Freitext) —</option>
                                </select>
                                <div class="mt-2" id="typ_sonstige_freitext_wrap" style="display: none;">
                                    <input type="text" class="form-control" id="typ_sonstige_freitext" name="typ_sonstige_freitext" placeholder="Typ eingeben">
                                </div>
                            </div>
                            <?php endif; ?>
                            <?php if (_anwesenheitsliste_visible('bemerkung', $anwesenheitsliste_sichtbar)): ?>
                            <div class="mb-4">
                                <label for="bemerkung" class="form-label"><?php echo htmlspecialchars(_anwesenheitsliste_label('bemerkung', 'Einsatzkurzbericht', $anwesenheitsliste_labels)); ?></label>
                                <textarea class="form-control" id="bemerkung" name="bemerkung" rows="3" placeholder="Kurzer Bericht zum Einsatz"><?php echo htmlspecialchars($draft['bemerkung'] ?? ''); ?></textarea>
                            </div>
                            <?php endif; ?>
                            <div class="d-flex flex-wrap gap-2">
                                <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> Anwesenheitsliste speichern</button>
                                <a href="anwesenheitsliste.php" class="btn btn-secondary">Zurück zur Auswahl</a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </main>
    <footer class="bg-light mt-5 py-4"><div class="container text-center"><p class="text-muted mb-0">&copy; 2025 Boedes Feuerwehr App</p></div></footer>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        (function(){var input=document.getElementById('einsatzstelle');var suggestionsEl=document.getElementById('einsatzstelle_suggestions');if(!input||!suggestionsEl)return;var debounceTimer;input.addEventListener('input',function(){clearTimeout(debounceTimer);var q=input.value.trim();if(q.length<3){suggestionsEl.style.display='none';suggestionsEl.innerHTML='';return;}debounceTimer=setTimeout(function(){fetch('https://nominatim.openstreetmap.org/search?format=json&q='+encodeURIComponent(q)+'&countrycodes=de,at,ch&limit=5&addressdetails=1',{headers:{'Accept':'application/json'}}).then(function(r){return r.json();}).then(function(data){suggestionsEl.innerHTML='';if(!data||data.length===0){suggestionsEl.style.display='none';return;}data.forEach(function(item){var addr=item.address||{};var strasse=addr.road||'';var hausnummer=addr.house_number||'';var plz=addr.postcode||'';var ort=addr.city||addr.town||addr.village||addr.municipality||'';var zeile1=[strasse,hausnummer].filter(Boolean).join(' ');var zeile2=[plz,ort].filter(Boolean).join(' ');var display=[zeile1,zeile2].filter(Boolean).join(', ');if(!display)display=item.display_name||item.name||'';var a=document.createElement('button');a.type='button';a.className='list-group-item list-group-item-action list-group-item-light text-start';a.textContent=display;a.addEventListener('click',function(){input.value=display;suggestionsEl.style.display='none';suggestionsEl.innerHTML='';});suggestionsEl.appendChild(a);});suggestionsEl.style.display='block';}).catch(function(){suggestionsEl.style.display='none';});},400);});input.addEventListener('blur',function(){setTimeout(function(){suggestionsEl.style.display='none';},200);});document.addEventListener('click',function(e){if(!input.contains(e.target)&&!suggestionsEl.contains(e.target))suggestionsEl.style.display='none';});})();
    </script>
    <script>document.getElementById('einsatzleiter').addEventListener('change',function(){document.getElementById('einsatzleiter_freitext_wrap').style.display=this.value==='__freitext__'?'block':'none';});</script>
    <?php if ($is_einsatz): ?><script>document.getElementById('typ_sonstige').addEventListener('change',function(){document.getElementById('typ_sonstige_freitext_wrap').style.display=this.value==='__custom__'?'block':'none';});</script><?php endif; ?>
</body>
</html>
