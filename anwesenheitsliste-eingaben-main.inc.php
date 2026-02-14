<?php
/**
 * Anwesenheitsliste – Hauptlogik (wird von anwesenheitsliste-eingaben.php per require eingebunden).
 */
ob_start();
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/divera.php';
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
$neu = isset($_GET['neu']) && $_GET['neu'] === '1';
if ($neu) {
    unset($_SESSION[$draft_key]);
}
$draft_loaded = false;
if (!isset($_SESSION[$draft_key]) || $_SESSION[$draft_key]['datum'] !== $datum || $_SESSION[$draft_key]['auswahl'] !== $auswahl) {
    // Versuche Entwurf aus DB zu laden (für alle Benutzer – Entwürfe sind gemeinsam nutzbar)
    if (!$neu && isset($_SESSION['user_id'])) {
        try {
            $stmt = $db->prepare("SELECT datum, auswahl, draft_data FROM anwesenheitsliste_drafts WHERE datum = ? AND auswahl = ?");
            $stmt->execute([$datum, $auswahl]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && !empty($row['draft_data'])) {
                $loaded = json_decode($row['draft_data'], true);
                if (is_array($loaded)) {
                    $_SESSION[$draft_key] = $loaded;
                    $draft_loaded = true;
                }
            }
        } catch (Exception $e) {
            // Tabelle evtl. nicht vorhanden
        }
    }
    if (!$draft_loaded) {
    $_SESSION[$draft_key] = [
        'datum' => $datum,
        'auswahl' => $auswahl,
        'dienstplan_id' => $is_einsatz ? null : $dienstplan_id,
        'typ' => $typ,
        'bezeichnung_sonstige' => null,
        'einsatzstichwort' => '',
        'thema' => '',
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
        'custom_data' => [],
    ];
    $_SESSION[$draft_key]['uhrzeit_bis'] = date('H:i');
    }
}
$draft = &$_SESSION[$draft_key];

// Divera-Einsatz: Daten aus Divera übernehmen (wenn divera_id übergeben)
$divera_id = isset($_GET['divera_id']) ? (int)$_GET['divera_id'] : 0;
if ($divera_id > 0 && $is_einsatz) {
    $divera_key = trim((string) ($divera_config['access_key'] ?? ''));
    if ($divera_key === '' && isset($_SESSION['user_id'])) {
        $stmt = $db->prepare("SELECT divera_access_key FROM users WHERE id = ?");
        $stmt->execute([(int)$_SESSION['user_id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $divera_key = trim((string) ($row['divera_access_key'] ?? ''));
    }
    if ($divera_key !== '') {
        $api_base = rtrim(trim((string) ($divera_config['api_base_url'] ?? '')), '/') ?: 'https://app.divera247.com';
        $url = $api_base . '/api/v2/alarms/' . $divera_id . '?accesskey=' . urlencode($divera_key);
        $ctx = stream_context_create(['http' => ['timeout' => 10]]);
        $raw = @file_get_contents($url, false, $ctx);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($data) && !empty($data['data'])) {
            $a = $data['data'];
            $date_ts = (int)($a['date'] ?? $a['ts_create'] ?? 0);
            if ($date_ts > 10000000000) $date_ts = (int)($date_ts / 1000);
            $draft['datum'] = date('Y-m-d', $date_ts);
            $draft['uhrzeit_von'] = date('H:i', $date_ts);
            $draft['uhrzeit_bis'] = date('H:i');
            $draft['einsatzstelle'] = trim((string)($a['address'] ?? ''));
            $draft['einsatzstichwort'] = trim((string)($a['title'] ?? ''));
            $draft['bezeichnung_sonstige'] = trim((string)($a['title'] ?? ''));
            if (!empty($a['text'])) $draft['bemerkung'] = trim((string)$a['text']);
            $datum = $draft['datum'];
        }
    }
}

// Fehlende Draft-Keys ergänzen (z. B. nach Update oder alter Session)
$draft_defaults = [
    'uhrzeit_von' => '', 'uhrzeit_bis' => $draft['uhrzeit_bis'] ?? date('H:i'),
    'alarmierung_durch' => '', 'einsatzstelle' => '', 'objekt' => '', 'eigentuemer' => '', 'geschaedigter' => '',
    'klassifizierung' => '', 'kostenpflichtiger_einsatz' => '', 'personenschaeden' => '', 'brandwache' => '',
    'einsatzleiter_member_id' => null, 'einsatzleiter_freitext' => '',
    'einsatzstichwort' => '', 'thema' => '',
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
if (!is_array($draft['custom_data'])) $draft['custom_data'] = [];

// Sicherstellen, dass anwesenheitslisten alle Spalten hat (falls Nutzer direkt diese Seite aufruft)
$extra_columns = [
    'uhrzeit_von TIME NULL', 'uhrzeit_bis TIME NULL', 'alarmierung_durch VARCHAR(100) NULL',
    'einsatzstelle VARCHAR(255) NULL', 'objekt TEXT NULL', 'eigentuemer VARCHAR(255) NULL', 'geschaedigter VARCHAR(255) NULL',
    'klassifizierung VARCHAR(100) NULL', 'kostenpflichtiger_einsatz VARCHAR(100) NULL', 'personenschaeden VARCHAR(50) NULL',
    'brandwache VARCHAR(100) NULL', 'einsatzleiter_member_id INT NULL', 'einsatzleiter_freitext VARCHAR(255) NULL',
    'custom_data JSON NULL',
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

// Anwesenheitsliste-Felder aus Einstellungen laden (anwesenheitsliste_felder)
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
$anwesenheitsliste_felder = _anwesenheitsliste_felder_laden($anwesenheitsliste_settings);
function _anwesenheitsliste_felder_laden($s) {
    $raw = $s['anwesenheitsliste_felder'] ?? '';
    if ($raw !== '') {
        $arr = json_decode($raw, true);
        if (is_array($arr) && !empty($arr)) {
            usort($arr, function ($a, $b) { return ($a['position'] ?? 999) - ($b['position'] ?? 999); });
            return $arr;
        }
    }
    $std = [
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
    $opts_map = ['alarmierung_durch' => 'alarmierung_optionen', 'klassifizierung' => 'klassifizierung_optionen', 'personenschaeden' => 'personenschaeden_optionen', 'kostenpflichtiger_einsatz' => 'kostenpflichtiger_optionen', 'brandwache' => 'brandwache_optionen'];
    $labels = json_decode($s['anwesenheitsliste_feld_labels'] ?? '{}', true) ?: [];
    $sichtbar = json_decode($s['anwesenheitsliste_felder_sichtbar'] ?? '{}', true) ?: [];
    foreach ($std as &$f) {
        if (isset($labels[$f['id']])) $f['label'] = $labels[$f['id']];
        if (isset($sichtbar[$f['id']])) $f['visible'] = $sichtbar[$f['id']] === '1';
        $ok = $opts_map[$f['id']] ?? null;
        if ($ok && !empty($s['anwesenheitsliste_' . $ok])) {
            $o = json_decode($s['anwesenheitsliste_' . $ok], true);
            if (is_array($o)) $f['options'] = $o;
        }
    }
    return $std;
}
function _anwesenheitsliste_draft_value($id, $draft) {
    $builtin = ['uhrzeit_von','uhrzeit_bis','alarmierung_durch','einsatzstelle','objekt','eigentuemer','geschaedigter','klassifizierung','kostenpflichtiger_einsatz','personenschaeden','brandwache','bemerkung','einsatzstichwort','thema'];
    if (in_array($id, $builtin)) return $draft[$id] ?? '';
    return $draft['custom_data'][$id] ?? '';
}

// Speichern (nur auf dieser Seite): Liste anlegen + Personal/Fahrzeuge aus Session
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_final'])) {
    $typ_sonstige = trim($_POST['typ_sonstige'] ?? '');
    $typ_sonstige_freitext = trim($_POST['typ_sonstige_freitext'] ?? '');
    foreach ($anwesenheitsliste_felder as $f) {
        if (empty($f['visible'])) continue;
        $id = $f['id'] ?? '';
        if ($id === '') continue;
        if ($id === 'einsatzleiter') {
            $ev = trim($_POST['einsatzleiter'] ?? '');
            if ($ev === '__freitext__') {
                $draft['einsatzleiter_member_id'] = null;
                $draft['einsatzleiter_freitext'] = trim($_POST['einsatzleiter_freitext'] ?? '');
            } elseif ($ev !== '' && ctype_digit($ev)) {
                $mid = (int)$ev;
                $draft['einsatzleiter_member_id'] = $mid;
                $draft['einsatzleiter_freitext'] = '';
                if (!in_array($mid, $draft['members'])) $draft['members'][] = $mid;
            } else {
                $draft['einsatzleiter_member_id'] = null;
                $draft['einsatzleiter_freitext'] = '';
            }
            continue;
        }
        $val = trim($_POST[$id] ?? '');
        $builtin = ['uhrzeit_von','uhrzeit_bis','alarmierung_durch','einsatzstelle','objekt','eigentuemer','geschaedigter','klassifizierung','kostenpflichtiger_einsatz','personenschaeden','brandwache','bemerkung','einsatzstichwort','thema'];
        if (in_array($id, $builtin)) {
            $draft[$id] = $val;
        } else {
            if (!isset($draft['custom_data'])) $draft['custom_data'] = [];
            $draft['custom_data'][$id] = $val;
        }
    }
    if ($draft['typ'] === 'einsatz') {
        if ($typ_sonstige === '__custom__') {
            $draft['bezeichnung_sonstige'] = $typ_sonstige_freitext !== '' ? $typ_sonstige_freitext : 'Sonstiges';
        } else {
            $draft['bezeichnung_sonstige'] = get_dienstplan_typen_auswahl()[$typ_sonstige] ?? 'Einsatz';
        }
        $draft['einsatzstichwort'] = trim($_POST['einsatzstichwort'] ?? '');
        $thema_val = trim($_POST['thema'] ?? '');
        $thema_neu = trim($_POST['thema_neu'] ?? '');
        $draft['thema'] = ($thema_val === '__neu__' ? $thema_neu : $thema_val);
    }
    $dp_id = $draft['dienstplan_id'];
    $typ_save = $draft['typ'];
    if ($draft['typ'] === 'einsatz') {
        $typ_save = ($typ_sonstige ?? 'einsatz') === 'einsatz' ? 'einsatz' : 'manuell';
        $draft['typ'] = $typ_save;
    }
    $bezeichnung_save = $typ_save === 'einsatz' ? $draft['bezeichnung_sonstige'] : ($typ_save === 'manuell' ? ($draft['thema'] ?? $draft['bezeichnung_sonstige']) : null);
    $uhrzeit_von_save = $draft['uhrzeit_von'] !== '' ? $draft['uhrzeit_von'] : null;
    $uhrzeit_bis_save = $draft['uhrzeit_bis'] !== '' ? $draft['uhrzeit_bis'] : null;
    try {
        try {
            $db->exec("ALTER TABLE anwesenheitslisten ADD COLUMN einsatzstichwort VARCHAR(100) NULL");
        } catch (Exception $e) { /* ignore */ }
        try {
            $db->exec("ALTER TABLE anwesenheitslisten ADD COLUMN bemerkung TEXT NULL");
        } catch (Exception $e) {
            // ignore
        }
        try {
            $db->exec("ALTER TABLE anwesenheitslisten ADD COLUMN custom_data JSON NULL");
        } catch (Exception $e) {
            // ignore
        }
        $custom_data_json = !empty($draft['custom_data']) ? json_encode($draft['custom_data']) : null;
        $einsatzstichwort_save = ($typ_save === 'einsatz' && !empty($draft['einsatzstichwort'])) ? $draft['einsatzstichwort'] : null;
        $stmt = $db->prepare("
            INSERT INTO anwesenheitslisten (datum, dienstplan_id, typ, bezeichnung, user_id, bemerkung, einsatzstichwort,
                uhrzeit_von, uhrzeit_bis, alarmierung_durch, einsatzstelle, objekt, eigentuemer, geschaedigter,
                klassifizierung, kostenpflichtiger_einsatz, personenschaeden, brandwache, einsatzleiter_member_id, einsatzleiter_freitext, custom_data)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $draft['datum'], $dp_id, $typ_save, $bezeichnung_save, $_SESSION['user_id'], $draft['bemerkung'] !== '' ? $draft['bemerkung'] : null, $einsatzstichwort_save,
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
            $draft['einsatzleiter_freitext'] !== '' ? $draft['einsatzleiter_freitext'] : null,
            $custom_data_json
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
        try {
            $db->prepare("DELETE FROM anwesenheitsliste_drafts WHERE datum = ? AND auswahl = ?")->execute([$draft['datum'], $draft['auswahl']]);
        } catch (Exception $e) { /* ignore */ }
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
                    <?php if (!is_system_user()): ?>
                    <li class="nav-item"><a class="nav-link" href="admin/dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                    <?php endif; ?>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
                            <i class="fas fa-user"></i> <?php echo htmlspecialchars(trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')) ?: 'Benutzer'); ?>
                        </a>
                        <ul class="dropdown-menu">
                            <?php if (!is_system_user()): ?>
                            <li><a class="dropdown-item" href="admin/profile.php"><i class="fas fa-user-edit"></i> Profil</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <?php endif; ?>
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
                            <form method="get" class="d-inline-flex align-items-center gap-2 mt-2">
                                <input type="hidden" name="auswahl" value="<?php echo htmlspecialchars($auswahl); ?>">
                                <label for="datum_aendern" class="form-label mb-0 small">Datum:</label>
                                <input type="date" id="datum_aendern" name="datum" class="form-control form-control-sm" value="<?php echo htmlspecialchars($datum); ?>" style="width: auto;">
                                <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="fas fa-sync-alt"></i> Übernehmen</button>
                            </form>
                            <span class="d-block mt-1 text-muted"><?php echo htmlspecialchars($titel_anzeige); ?></span>
                        </div>
                        <form method="post" id="mainForm">
                            <input type="hidden" name="save_final" value="1">
                            <?php if ($is_einsatz): 
                                $dienstplan_themen = [];
                                try {
                                    $stmt = $db->query("SELECT DISTINCT bezeichnung FROM dienstplan ORDER BY bezeichnung");
                                    $dienstplan_themen = $stmt->fetchAll(PDO::FETCH_COLUMN);
                                } catch (Exception $e) { /* ignore */ }
                            ?>
                            <div class="mb-4">
                                <label for="typ_sonstige" class="form-label">Typ</label>
                                <?php 
                                    $typen_map = get_dienstplan_typen_auswahl();
                                    $bez_cur = $draft['bezeichnung_sonstige'] ?? 'Einsatz';
                                    $typ_cur = array_search($bez_cur, $typen_map);
                                    if ($typ_cur === false) $typ_cur = 'einsatz';
                                ?>
                                <select class="form-select" id="typ_sonstige" name="typ_sonstige">
                                    <?php foreach ($typen_map as $key => $label): ?>
                                        <option value="<?php echo htmlspecialchars($key); ?>" <?php echo $key === $typ_cur ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                                    <?php endforeach; ?>
                                    <option value="__custom__">— Anderer Typ (Freitext) —</option>
                                </select>
                                <div class="mt-2" id="typ_sonstige_freitext_wrap" style="display: none;">
                                    <input type="text" class="form-control" id="typ_sonstige_freitext" name="typ_sonstige_freitext" placeholder="Typ eingeben">
                                </div>
                                <?php $bez = $draft['bezeichnung_sonstige'] ?? ''; $show_einsatzstichwort = $bez !== 'Übungsdienst'; $show_thema = $bez === 'Übungsdienst'; ?>
                                <div class="mt-3" id="einsatzstichwort_wrap" style="display: <?php echo $show_einsatzstichwort ? 'block' : 'none'; ?>;">
                                    <label for="einsatzstichwort" class="form-label">Einsatzstichwort</label>
                                    <input type="text" class="form-control" id="einsatzstichwort" name="einsatzstichwort" placeholder="z.B. FEUER3, THL" value="<?php echo htmlspecialchars($draft['einsatzstichwort'] ?? ''); ?>">
                                </div>
                                <div class="mt-3" id="thema_wrap" style="display: <?php echo $show_thema ? 'block' : 'none'; ?>;">
                                    <label for="thema" class="form-label">Thema</label>
                                    <select class="form-select" id="thema" name="thema">
                                        <option value="">— Bitte wählen oder neues Thema eingeben —</option>
                                        <?php foreach ($dienstplan_themen as $t): ?>
                                            <option value="<?php echo htmlspecialchars($t); ?>" <?php echo ($draft['thema'] ?? '') === $t ? 'selected' : ''; ?>><?php echo htmlspecialchars($t); ?></option>
                                        <?php endforeach; ?>
                                        <option value="__neu__">— Neues Thema eingeben —</option>
                                    </select>
                                    <div class="mt-2" id="thema_neu_wrap" style="display: none;">
                                        <input type="text" class="form-control" id="thema_neu" name="thema_neu" placeholder="Neues Thema" value="">
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
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
                            <?php
                            $time_fields = array_filter($anwesenheitsliste_felder, fn($f) => ($f['type'] ?? '') === 'time' && !empty($f['visible']));
                            if (count($time_fields) > 0):
                                $time_ids = array_column($time_fields, 'id');
                            ?>
                            <div class="row g-3 mb-4">
                                <?php foreach ($time_fields as $tf):
                                    $tid = $tf['id'];
                                    $tval = $tid === 'uhrzeit_bis' && empty($draft[$tid]) ? date('H:i') : ($draft[$tid] ?? '');
                                ?>
                                <div class="col-md-6">
                                    <label for="<?php echo htmlspecialchars($tid); ?>" class="form-label"><?php echo htmlspecialchars($tf['label'] ?? $tid); ?></label>
                                    <input type="time" class="form-control" id="<?php echo htmlspecialchars($tid); ?>" name="<?php echo htmlspecialchars($tid); ?>" value="<?php echo htmlspecialchars($tval); ?>">
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                            <?php foreach ($anwesenheitsliste_felder as $f):
                                if (empty($f['visible']) || ($f['type'] ?? '') === 'time') continue;
                                $id = $f['id'] ?? '';
                                $label = $f['label'] ?? $id;
                                $type = $f['type'] ?? 'text';
                                $opts = $f['options'] ?? [];
                                $val = _anwesenheitsliste_draft_value($id, $draft);
                                if ($id === 'uhrzeit_von' || $id === 'uhrzeit_bis') continue;
                            ?>
                            <div class="mb-3<?php echo $type === 'textarea' ? ' mb-4' : ''; ?>">
                                <?php if ($type === 'einsatzleiter'): ?>
                                <label for="einsatzleiter" class="form-label"><?php echo htmlspecialchars($label); ?></label>
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
                                    <input type="text" class="form-control" id="einsatzleiter_freitext" name="einsatzleiter_freitext" placeholder="Name Einsatzleiter" value="<?php echo htmlspecialchars($draft['einsatzleiter_freitext'] ?? ''); ?>">
                                </div>
                                <?php elseif ($type === 'einsatzstelle'): ?>
                                <label for="einsatzstelle" class="form-label"><?php echo htmlspecialchars($label); ?></label>
                                <div class="position-relative">
                                    <input type="text" class="form-control" id="einsatzstelle" name="einsatzstelle" placeholder="Adresse eingeben (Autovervollständigung)" value="<?php echo htmlspecialchars($val); ?>" autocomplete="off">
                                    <div id="einsatzstelle_suggestions" class="list-group position-absolute w-100 mt-1 shadow" style="z-index: 1050; max-height: 200px; overflow-y: auto; display: none;"></div>
                                </div>
                                <?php elseif ($type === 'select'): ?>
                                <label for="<?php echo htmlspecialchars($id); ?>" class="form-label"><?php echo htmlspecialchars($label); ?></label>
                                <select class="form-select" id="<?php echo htmlspecialchars($id); ?>" name="<?php echo htmlspecialchars($id); ?>">
                                    <option value="">— keine Auswahl —</option>
                                    <?php foreach ($opts as $opt): ?>
                                    <option value="<?php echo htmlspecialchars($opt); ?>" <?php echo $val === $opt ? 'selected' : ''; ?>><?php echo htmlspecialchars($opt); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?php elseif ($type === 'radio'): ?>
                                <label class="form-label"><?php echo htmlspecialchars($label); ?></label>
                                <div class="d-flex gap-3">
                                    <?php foreach ($opts as $opt):
                                        $sel = ($val === $opt || strtolower($val) === strtolower($opt)) ? ' checked' : '';
                                        $oid = preg_replace('/[^a-z0-9]/', '_', strtolower($id . '_' . $opt));
                                    ?>
                                    <div class="form-check"><input class="form-check-input" type="radio" name="<?php echo htmlspecialchars($id); ?>" id="<?php echo $oid; ?>" value="<?php echo htmlspecialchars($opt); ?>"<?php echo $sel; ?>><label class="form-check-label" for="<?php echo $oid; ?>"><?php echo htmlspecialchars($opt); ?></label></div>
                                    <?php endforeach; ?>
                                </div>
                                <?php elseif ($type === 'textarea'): ?>
                                <label for="<?php echo htmlspecialchars($id); ?>" class="form-label"><?php echo htmlspecialchars($label); ?></label>
                                <textarea class="form-control" id="<?php echo htmlspecialchars($id); ?>" name="<?php echo htmlspecialchars($id); ?>" rows="3" placeholder="Freitext"><?php echo htmlspecialchars($val); ?></textarea>
                                <?php else: ?>
                                <label for="<?php echo htmlspecialchars($id); ?>" class="form-label"><?php echo htmlspecialchars($label); ?></label>
                                <input type="text" class="form-control" id="<?php echo htmlspecialchars($id); ?>" name="<?php echo htmlspecialchars($id); ?>" placeholder="Freitext" value="<?php echo htmlspecialchars($val); ?>">
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
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
    window.addEventListener('beforeunload', function() {
        var form = document.getElementById('mainForm');
        if (form) {
            var fd = new FormData(form);
            navigator.sendBeacon('api/save-anwesenheit-draft.php', fd);
        } else {
            navigator.sendBeacon('api/save-anwesenheit-draft.php', '');
        }
    });
    </script>
    <script>
        (function(){var input=document.getElementById('einsatzstelle');var suggestionsEl=document.getElementById('einsatzstelle_suggestions');if(!input||!suggestionsEl)return;var debounceTimer;input.addEventListener('input',function(){clearTimeout(debounceTimer);var q=input.value.trim();if(q.length<3){suggestionsEl.style.display='none';suggestionsEl.innerHTML='';return;}debounceTimer=setTimeout(function(){fetch('https://nominatim.openstreetmap.org/search?format=json&q='+encodeURIComponent(q)+'&countrycodes=de,at,ch&limit=5&addressdetails=1',{headers:{'Accept':'application/json'}}).then(function(r){return r.json();}).then(function(data){suggestionsEl.innerHTML='';if(!data||data.length===0){suggestionsEl.style.display='none';return;}data.forEach(function(item){var addr=item.address||{};var strasse=addr.road||'';var hausnummer=addr.house_number||'';var plz=addr.postcode||'';var ort=addr.city||addr.town||addr.village||addr.municipality||'';var zeile1=[strasse,hausnummer].filter(Boolean).join(' ');var zeile2=[plz,ort].filter(Boolean).join(' ');var display=[zeile1,zeile2].filter(Boolean).join(', ');if(!display)display=item.display_name||item.name||'';var a=document.createElement('button');a.type='button';a.className='list-group-item list-group-item-action list-group-item-light text-start';a.textContent=display;a.addEventListener('click',function(){input.value=display;suggestionsEl.style.display='none';suggestionsEl.innerHTML='';});suggestionsEl.appendChild(a);});suggestionsEl.style.display='block';}).catch(function(){suggestionsEl.style.display='none';});},400);});input.addEventListener('blur',function(){setTimeout(function(){suggestionsEl.style.display='none';},200);});document.addEventListener('click',function(e){if(!input.contains(e.target)&&!suggestionsEl.contains(e.target))suggestionsEl.style.display='none';});})();
    </script>
    <script>var el=document.getElementById('einsatzleiter');if(el)el.addEventListener('change',function(){var w=document.getElementById('einsatzleiter_freitext_wrap');if(w)w.style.display=this.value==='__freitext__'?'block':'none';});</script>
    <?php if ($is_einsatz): ?>
    <script>
    document.getElementById('typ_sonstige').addEventListener('change',function(){
        var v=this.value;
        document.getElementById('typ_sonstige_freitext_wrap').style.display=v==='__custom__'?'block':'none';
        document.getElementById('einsatzstichwort_wrap').style.display=v==='einsatz'?'block':'none';
        document.getElementById('thema_wrap').style.display=v==='uebungsdienst'?'block':'none';
    });
    document.getElementById('thema').addEventListener('change',function(){
        document.getElementById('thema_neu_wrap').style.display=this.value==='__neu__'?'block':'none';
    });
    </script>
    <?php endif; ?>
</body>
</html>
