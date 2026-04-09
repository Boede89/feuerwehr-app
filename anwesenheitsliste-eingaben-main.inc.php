<?php
/**
 * Anwesenheitsliste – Hauptlogik (wird von anwesenheitsliste-eingaben.php per require eingebunden).
 */
ob_start();
session_start();
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
// Einheit VOR Divera setzen (für einheitsspezifischen Divera-Key und Einstellungen)
$einheit_id = isset($_GET['einheit_id']) ? (int)$_GET['einheit_id'] : (isset($_POST['einheit_id']) ? (int)$_POST['einheit_id'] : 0);
if ($einheit_id <= 0) $einheit_id = isset($_SESSION['current_einheit_id']) ? (int)$_SESSION['current_einheit_id'] : 0;
if ($einheit_id <= 0 && isset($_SESSION['user_id'])) {
    $stmt = $db->prepare("SELECT einheit_id FROM users WHERE id = ?");
    $stmt->execute([(int)$_SESSION['user_id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $einheit_id = $row ? (int)($row['einheit_id'] ?? 0) : 0;
}
if ($einheit_id > 0) $_SESSION['current_einheit_id'] = $einheit_id;
$einheit_param = $einheit_id > 0 ? '?einheit_id=' . (int)$einheit_id : '';
require_once __DIR__ . '/config/divera.php';
require_once __DIR__ . '/includes/dienstplan-typen.php';
require_once __DIR__ . '/includes/anwesenheitsliste-helper.php';
require_once __DIR__ . '/includes/bericht-anhaenge-helper.php';

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
$edit_id = isset($_GET['edit_id']) ? (int)$_GET['edit_id'] : (isset($_POST['edit_id']) ? (int)$_POST['edit_id'] : 0);
$return_formularcenter = (isset($_GET['return']) && $_GET['return'] === 'formularcenter') || (isset($_POST['return']) && $_POST['return'] === 'formularcenter');

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
        $stmt = $db->prepare("SELECT id, datum, bezeichnung, typ, uhrzeit_dienstbeginn, uhrzeit_dienstende FROM dienstplan WHERE id = ?");
        $stmt->execute([$dienstplan_id]);
        $dienst = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($dienst) {
            $stmt2 = $db->prepare("SELECT member_id FROM dienstplan_ausbilder WHERE dienstplan_id = ?");
            $stmt2->execute([$dienstplan_id]);
            $dienst['ausbilder_member_ids'] = $stmt2->fetchAll(PDO::FETCH_COLUMN);
        }
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
if ($neu && $edit_id <= 0) {
    if (!empty($_SESSION[$draft_key]) && is_array($_SESSION[$draft_key])) {
        require_once __DIR__ . '/includes/bericht-anhaenge-helper.php';
        bericht_anhaenge_draft_cleanup_files($_SESSION[$draft_key]);
    }
    unset($_SESSION[$draft_key]);
}
$draft_loaded = false;
$has_typ_sonstige_url = $is_einsatz && trim((string)($_GET['typ_sonstige'] ?? '')) !== '';

// Bearbeitungsmodus: bestehende Anwesenheitsliste laden
if ($edit_id > 0 && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    try {
        $stmt = $db->prepare("SELECT a.*, d.bezeichnung AS dienst_bezeichnung, d.typ AS dienst_typ FROM anwesenheitslisten a LEFT JOIN dienstplan d ON d.id = a.dienstplan_id WHERE a.id = ?");
        $stmt->execute([$edit_id]);
        $liste_edit = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($liste_edit) {
            $cd = !empty($liste_edit['custom_data']) ? json_decode($liste_edit['custom_data'], true) : [];
            if (!is_array($cd)) $cd = [];
            $ueb_ids = $cd['uebungsleiter_member_ids'] ?? [];
            if (!is_array($ueb_ids)) $ueb_ids = [];
            $members_edit = [];
            $member_vehicle_edit = [];
            $stmt_m = $db->prepare("SELECT member_id, vehicle_id FROM anwesenheitsliste_mitglieder WHERE anwesenheitsliste_id = ?");
            $stmt_m->execute([$edit_id]);
            while ($r = $stmt_m->fetch(PDO::FETCH_ASSOC)) {
                $members_edit[] = (int)$r['member_id'];
                $member_vehicle_edit[(int)$r['member_id']] = $r['vehicle_id'] ? (int)$r['vehicle_id'] : null;
            }
            $vehicles_edit = [];
            $vehicle_maschinist_edit = [];
            $vehicle_einheitsfuehrer_edit = [];
            $vehicle_equipment_edit = [];
            $vehicle_equipment_sonstiges_edit = [];
            try {
                $stmt_v = $db->prepare("SELECT vehicle_id, maschinist_member_id, einheitsfuehrer_member_id FROM anwesenheitsliste_fahrzeuge WHERE anwesenheitsliste_id = ?");
                $stmt_v->execute([$edit_id]);
                while ($rv = $stmt_v->fetch(PDO::FETCH_ASSOC)) {
                    $vid = (int)$rv['vehicle_id'];
                    $vehicles_edit[] = $vid;
                    $vehicle_maschinist_edit[$vid] = $rv['maschinist_member_id'] ? (int)$rv['maschinist_member_id'] : null;
                    $vehicle_einheitsfuehrer_edit[$vid] = $rv['einheitsfuehrer_member_id'] ? (int)$rv['einheitsfuehrer_member_id'] : null;
                }
                $vehicle_equipment_edit = $cd['vehicle_equipment'] ?? [];
                $vehicle_equipment_sonstiges_edit = $cd['vehicle_equipment_sonstiges'] ?? [];
            } catch (Exception $e) {}
            $bezeichnung_sonstige = 'Einsatz';
            $thema_edit = $liste_edit['bezeichnung'] ?? ($cd['thema'] ?? '');
            $beschreibung_edit = $cd['beschreibung'] ?? '';
            if (($liste_edit['typ'] ?? '') === 'manuell') {
                $ts_cd = trim($cd['typ_sonstige'] ?? '');
                $bez = trim($liste_edit['bezeichnung'] ?? '');
                if ($ts_cd === 'sonstiges' || $bez === 'Sonstiges') {
                    $bezeichnung_sonstige = 'Sonstiges';
                    $beschreibung_edit = $beschreibung_edit !== '' ? $beschreibung_edit : $bez;
                    $thema_edit = '';
                } else {
                    $bezeichnung_sonstige = 'Übungsdienst';
                    $thema_edit = $bez !== '' ? $bez : ($cd['thema'] ?? '');
                }
            }
            $uhrzeit_von_edit = $liste_edit['uhrzeit_von'] ?? '';
            if ($uhrzeit_von_edit !== '' && strlen($uhrzeit_von_edit) >= 5) $uhrzeit_von_edit = substr($uhrzeit_von_edit, 0, 5);
            $uhrzeit_bis_edit = $liste_edit['uhrzeit_bis'] ?? date('H:i');
            if ($uhrzeit_bis_edit !== '' && strlen($uhrzeit_bis_edit) >= 5) $uhrzeit_bis_edit = substr($uhrzeit_bis_edit, 0, 5);
            $_SESSION[$draft_key] = [
                'datum' => $liste_edit['datum'],
                'auswahl' => $auswahl,
                'dienstplan_id' => $liste_edit['dienstplan_id'],
                'typ' => $liste_edit['typ'],
                'bezeichnung_sonstige' => $bezeichnung_sonstige,
                'einsatzstichwort' => $liste_edit['einsatzstichwort'] ?? '',
                'thema' => $thema_edit,
                'bemerkung' => $liste_edit['bemerkung'] ?? '',
                'members' => $members_edit,
                'member_vehicle' => $member_vehicle_edit,
                'member_pa' => array_values(array_map('intval', is_array($cd['member_pa'] ?? null) ? $cd['member_pa'] : [])),
                'vehicles' => array_unique($vehicles_edit),
                'vehicle_maschinist' => $vehicle_maschinist_edit,
                'vehicle_einheitsfuehrer' => $vehicle_einheitsfuehrer_edit,
                'vehicle_equipment' => $vehicle_equipment_edit,
                'vehicle_equipment_sonstiges' => $vehicle_equipment_sonstiges_edit,
                'uhrzeit_von' => $uhrzeit_von_edit,
                'uhrzeit_bis' => $uhrzeit_bis_edit,
                'uebungsleiter_member_ids' => $ueb_ids,
                'alarmierung_durch' => $liste_edit['alarmierung_durch'] ?? '',
                'einsatzstelle' => $liste_edit['einsatzstelle'] ?? '',
                'objekt' => $liste_edit['objekt'] ?? '',
                'eigentuemer' => $liste_edit['eigentuemer'] ?? '',
                'geschaedigter' => $liste_edit['geschaedigter'] ?? '',
                'klassifizierung' => $liste_edit['klassifizierung'] ?? '',
                'kostenpflichtiger_einsatz' => $liste_edit['kostenpflichtiger_einsatz'] ?? '',
                'personenschaeden' => $liste_edit['personenschaeden'] ?? '',
                'brandwache' => $liste_edit['brandwache'] ?? '',
                'einsatzleiter_member_id' => $liste_edit['einsatzleiter_member_id'] ?? null,
                'einsatzleiter_freitext' => $liste_edit['einsatzleiter_freitext'] ?? '',
                'berichtersteller' => $cd['berichtersteller'] ?? null,
                'custom_data' => $cd,
                'maengel' => [],
                'anhaenge_temp' => [],
                'beschreibung' => $beschreibung_edit,
                'edit_id' => $edit_id,
            ];
            $draft_loaded = true;
        }
    } catch (Exception $e) {}
}

if (!isset($_SESSION[$draft_key]) || $_SESSION[$draft_key]['datum'] !== $datum || $_SESSION[$draft_key]['auswahl'] !== $auswahl) {
    // Bei Rückkehr von Unterseite (typ_sonstige in URL): NICHT aus DB laden – DB könnte veraltete Daten haben
    if (!$has_typ_sonstige_url && !$neu && isset($_SESSION['user_id'])) {
        try {
            $eid = $einheit_id > 0 ? $einheit_id : 1;
            $stmt = $db->prepare("SELECT datum, auswahl, draft_data FROM anwesenheitsliste_drafts WHERE datum = ? AND auswahl = ? AND einheit_id = ?");
            $stmt->execute([$datum, $auswahl, $eid]);
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
    $thema_init = '';
    $uhrzeit_von_init = '';
    $uhrzeit_bis_init = '';
    $uebungsleiter_init = [];
    $beschreibung_init = '';
    if (!$is_einsatz && isset($dienst)) {
        $dienst_typ_init = $dienst['typ'] ?? 'uebungsdienst';
        if ($dienst_typ_init === 'sonstiges') {
            $beschreibung_init = trim($dienst['bezeichnung'] ?? '');
        } else {
            if (!empty(trim($dienst['bezeichnung'] ?? ''))) $thema_init = trim($dienst['bezeichnung']);
        }
        $ub = trim((string)($dienst['uhrzeit_dienstbeginn'] ?? ''));
        if ($ub !== '' && (preg_match('/^\d{1,2}:\d{2}$/', $ub) || preg_match('/^\d{1,2}:\d{2}:\d{2}$/', $ub))) {
            $uhrzeit_von_init = strlen($ub) >= 5 ? substr($ub, 0, 5) : $ub;
        }
        $ue = trim((string)($dienst['uhrzeit_dienstende'] ?? ''));
        if ($ue !== '' && (preg_match('/^\d{1,2}:\d{2}$/', $ue) || preg_match('/^\d{1,2}:\d{2}:\d{2}$/', $ue))) {
            $uhrzeit_bis_init = strlen($ue) >= 5 ? substr($ue, 0, 5) : $ue;
        }
        if (!empty($dienst['ausbilder_member_ids']) && is_array($dienst['ausbilder_member_ids'])) {
            $uebungsleiter_init = array_map('intval', $dienst['ausbilder_member_ids']);
        }
    }
    $_SESSION[$draft_key] = [
        'datum' => $datum,
        'auswahl' => $auswahl,
        'dienstplan_id' => $is_einsatz ? null : $dienstplan_id,
        'typ' => $typ,
        'bezeichnung_sonstige' => null,
        'einsatzstichwort' => '',
        'thema' => $thema_init,
        'bemerkung' => '',
        'members' => [],
        'member_vehicle' => [],
        'member_pa' => [],
        'vehicles' => [],
        'vehicle_maschinist' => [],
        'vehicle_einheitsfuehrer' => [],
        'vehicle_equipment' => [],
        'vehicle_equipment_sonstiges' => [],
        'uhrzeit_von' => $uhrzeit_von_init,
        'uhrzeit_bis' => $uhrzeit_bis_init !== '' ? $uhrzeit_bis_init : date('H:i'),
        'uebungsleiter_member_ids' => $uebungsleiter_init,
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
        'berichtersteller' => null,
        'custom_data' => [],
        'maengel' => [],
        'anhaenge_temp' => [],
        'beschreibung' => $beschreibung_init,
    ];
    }
}
$draft = &$_SESSION[$draft_key];
if (!isset($draft['maengel']) || !is_array($draft['maengel'])) {
    $draft['maengel'] = [];
}
if (!isset($draft['anhaenge_temp']) || !is_array($draft['anhaenge_temp'])) {
    $draft['anhaenge_temp'] = [];
}
// typ_sonstige und uebungsleiter aus URL übernehmen (z.B. beim Zurückkehren von Personal/Fahrzeuge/etc.)
if ($is_einsatz) {
    $ts = trim((string)($_GET['typ_sonstige'] ?? ''));
    if ($ts !== '') {
        $typen = get_dienstplan_typen_auswahl();
        $draft['bezeichnung_sonstige'] = $typen[$ts] ?? ($draft['bezeichnung_sonstige'] ?? 'Einsatz');
    }
    if (isset($_GET['uebungsleiter']) && is_array($_GET['uebungsleiter'])) {
        $draft['uebungsleiter_member_ids'] = array_values(array_map('intval', array_filter($_GET['uebungsleiter'], function($x){return $x!==''&&ctype_digit((string)$x);})));
    }
}

// Divera-Einsatz: Daten aus Divera übernehmen (wenn divera_id übergeben)
$divera_id = isset($_GET['divera_id']) ? (int)$_GET['divera_id'] : 0;
if ($divera_id > 0 && $is_einsatz) {
    $draft['divera_id'] = $divera_id;
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
        $alarm_detail_for_divera = null;
        if (is_array($data) && !empty($data['data'])) {
            $alarm_detail_for_divera = $data['data'];
            $a = $alarm_detail_for_divera;
            $date_ts = (int)($a['date'] ?? $a['ts_create'] ?? 0);
            if ($date_ts > 10000000000) $date_ts = (int)($date_ts / 1000);
            $draft['datum'] = date('Y-m-d', $date_ts);
            $draft['uhrzeit_von'] = date('H:i', $date_ts);
            $draft['uhrzeit_bis'] = date('H:i');
            $draft['einsatzstelle'] = trim((string)($a['address'] ?? ''));
            $draft['einsatzstichwort'] = trim((string)($a['title'] ?? ''));
            $draft['bezeichnung_sonstige'] = trim((string)($a['title'] ?? ''));
            // Einsatzkurzbericht (bemerkung) wird nicht aus Divera übernommen – nur manuelle Eingabe
            $datum = $draft['datum'];
        }
        // Personal: Mitglieder mit passender Divera-Rückmeldung (Reach API) vormerken
        $auto_personal = true;
        $status_filter = 0;
        if ($einheit_id > 0) {
            try {
                $stmt_es = $db->prepare("SELECT setting_key, setting_value FROM einheit_settings WHERE einheit_id = ? AND setting_key IN ('anwesenheitsliste_divera_personal_from_rueckmeldung', 'anwesenheitsliste_divera_rueckmeldung_status_id')");
                $stmt_es->execute([$einheit_id]);
                $es_row = [];
                while ($r = $stmt_es->fetch(PDO::FETCH_ASSOC)) {
                    $es_row[$r['setting_key']] = $r['setting_value'];
                }
                $auto_personal = (($es_row['anwesenheitsliste_divera_personal_from_rueckmeldung'] ?? '1') === '1');
                $status_filter = (int) trim((string) ($es_row['anwesenheitsliste_divera_rueckmeldung_status_id'] ?? ''));
            } catch (Exception $e) {
                $auto_personal = true;
                $status_filter = 0;
            }
        }
        if ($auto_personal) {
            $reach_err = '';
            $reach_data = fetch_divera_alarm_reach($divera_key, $divera_id, $api_base, $reach_err);
            $ucr_ids = [];
            if ($reach_data !== null) {
                $ucr_ids = divera_reach_confirmed_ucr_ids($reach_data, $status_filter);
            }
            // Fallback: ucr_answered (oft verschachtelt: UCR → Status-ID → ts/note), wenn Reach wenig liefert
            if ($ucr_ids === [] && is_array($alarm_detail_for_divera)) {
                $ucr_ids = divera_alarm_ucr_answered_ids($alarm_detail_for_divera, $status_filter);
            }
            if ($ucr_ids !== []) {
                try {
                    $db->exec('ALTER TABLE members ADD COLUMN divera_ucr_id INT NULL DEFAULT NULL');
                } catch (Throwable $e) {
                    // Spalte existiert evtl. schon
                }
                $from_divera = members_ids_by_divera_ucr_ids($db, $ucr_ids, $einheit_id);
                if ($from_divera !== []) {
                    $have = isset($draft['members']) && is_array($draft['members']) ? $draft['members'] : [];
                    $draft['members'] = array_values(array_unique(array_merge(array_map('intval', $have), $from_divera)));
                }
            }
        }
    }
}

// Fehlende Draft-Keys ergänzen (z. B. nach Update oder alter Session)
$draft_defaults = [
    'uhrzeit_von' => '', 'uhrzeit_bis' => $draft['uhrzeit_bis'] ?? date('H:i'),
    'alarmierung_durch' => '', 'einsatzstelle' => '', 'objekt' => '', 'eigentuemer' => '', 'geschaedigter' => '',
    'klassifizierung' => '', 'kostenpflichtiger_einsatz' => '', 'personenschaeden' => '', 'brandwache' => '',
    'einsatzleiter_member_id' => null, 'einsatzleiter_freitext' => '', 'berichtersteller' => null,
    'einsatzstichwort' => '', 'thema' => '', 'divera_id' => null,
];
foreach ($draft_defaults as $k => $v) {
    if (!array_key_exists($k, $draft)) {
        $draft[$k] = $v;
    }
}
if (!is_array($draft['members'])) $draft['members'] = [];
if (!is_array($draft['member_vehicle'])) $draft['member_vehicle'] = [];
if (!isset($draft['member_pa']) || !is_array($draft['member_pa'])) $draft['member_pa'] = [];
if (!is_array($draft['vehicles'])) $draft['vehicles'] = [];
if (!is_array($draft['vehicle_maschinist'])) $draft['vehicle_maschinist'] = [];
if (!is_array($draft['vehicle_einheitsfuehrer'])) $draft['vehicle_einheitsfuehrer'] = [];
if (!is_array($draft['vehicle_equipment'])) $draft['vehicle_equipment'] = [];
if (!is_array($draft['vehicle_equipment_sonstiges'])) $draft['vehicle_equipment_sonstiges'] = [];
if (!is_array($draft['custom_data'])) $draft['custom_data'] = [];

// Sicherstellen, dass anwesenheitslisten alle Spalten hat (falls Nutzer direkt diese Seite aufruft)
$extra_columns = [
    'uhrzeit_von TIME NULL', 'uhrzeit_bis TIME NULL', 'alarmierung_durch VARCHAR(100) NULL',
    'einsatzstelle VARCHAR(255) NULL', 'objekt TEXT NULL', 'eigentuemer VARCHAR(255) NULL', 'geschaedigter VARCHAR(255) NULL',
    'klassifizierung VARCHAR(100) NULL', 'kostenpflichtiger_einsatz VARCHAR(100) NULL', 'personenschaeden VARCHAR(50) NULL',
    'brandwache VARCHAR(100) NULL', 'einsatzleiter_member_id INT NULL', 'einsatzleiter_freitext VARCHAR(255) NULL',
    'custom_data JSON NULL', 'divera_id INT NULL', 'einheit_id INT NULL',
];
foreach ($extra_columns as $colDef) {
    try {
        $db->exec("ALTER TABLE anwesenheitslisten ADD COLUMN " . $colDef);
    } catch (Throwable $e) {
        // Spalte existiert bereits oder Tabelle existiert noch nicht
    }
}

// Mitglieder für Einsatzleiter/Übungsleiter (Personal zuerst, dann nach Qualifikation: Zugführer > Gruppenführer > Truppführer > Mannschaft)
$members_for_einsatzleiter = anwesenheitsliste_members_for_leiter($db, $draft['members'] ?? [], $einheit_id);

// Mitglieder für Berichtersteller (nur Einheit der aktuellen Anwesenheitsliste)
$members_all = [];
try {
    if ($einheit_id > 0) {
        $stmt = $db->prepare("SELECT id, first_name, last_name FROM members WHERE einheit_id = ? OR einheit_id IS NULL ORDER BY last_name, first_name");
        $stmt->execute([$einheit_id]);
    } else {
        $stmt = $db->query("SELECT id, first_name, last_name FROM members ORDER BY last_name, first_name");
    }
    $members_all = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}
$berichtersteller_val = $draft['berichtersteller'] ?? '';
$berichtersteller_display = '';
if ($berichtersteller_val !== '' && $berichtersteller_val !== null) {
    if (preg_match('/^\d+$/', (string)$berichtersteller_val)) {
        foreach ($members_all as $m) {
            if ((int)$m['id'] === (int)$berichtersteller_val) {
                $berichtersteller_display = trim($m['last_name'] . ', ' . $m['first_name']);
                break;
            }
        }
    }
    if ($berichtersteller_display === '') $berichtersteller_display = (string)$berichtersteller_val;
}

$message = '';
$error = '';

// Anwesenheitsliste-Felder und Gerätehaus-Adresse aus einheitsspezifischen Einstellungen laden
$anwesenheitsliste_settings = [];
$geraetehaus_adresse = '';
try {
    require_once __DIR__ . '/includes/einheit-settings-helper.php';
    $unit_settings = load_settings_for_einheit($db, $einheit_id > 0 ? $einheit_id : null);
    foreach ($unit_settings as $k => $v) {
        if (strpos($k, 'anwesenheitsliste_') === 0) {
            $anwesenheitsliste_settings[$k] = $v;
        }
    }
    $geraetehaus_adresse = trim((string)($unit_settings['geraetehaus_adresse'] ?? ''));
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
    $edit_id_save = isset($_POST['edit_id']) ? (int)$_POST['edit_id'] : (int)($draft['edit_id'] ?? 0);
    if (isset($_POST['datum']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($_POST['datum']))) {
        $draft['datum'] = trim($_POST['datum']);
        $datum = $draft['datum'];
    }
    $ber = trim($_POST['berichtersteller'] ?? '');
    if ($ber === '') $ber = trim($_POST['berichtersteller_display'] ?? '');
    $draft['berichtersteller'] = $ber !== '' ? $ber : null;
    $typ_sonstige = trim($_POST['typ_sonstige'] ?? '');
    $typ_sonstige_freitext = trim($_POST['typ_sonstige_freitext'] ?? '');
    foreach ($anwesenheitsliste_felder as $f) {
        if (empty($f['visible'])) continue;
        $id = $f['id'] ?? '';
        if ($id === '') continue;
        if ($id === 'einsatzleiter') {
            $is_ueb = ($draft['typ'] === 'einsatz' && in_array(trim($_POST['typ_sonstige'] ?? ''), ['uebungsdienst', 'sonstiges'], true)) || ($draft['typ'] === 'dienst' && isset($dienst) && ($dienst['typ'] ?? '') === 'uebungsdienst');
            if ($is_ueb && !empty($_POST['uebungsleiter']) && is_array($_POST['uebungsleiter'])) {
                $draft['uebungsleiter_member_ids'] = array_map('intval', array_filter($_POST['uebungsleiter'], 'ctype_digit'));
                $draft['einsatzleiter_member_id'] = null;
                $draft['einsatzleiter_freitext'] = '';
            } else {
                $draft['uebungsleiter_member_ids'] = [];
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
    $draft['beschreibung'] = trim($_POST['beschreibung'] ?? '');
    $dp_id = $draft['dienstplan_id'];
    $typ_save = $draft['typ'];
    if ($draft['typ'] === 'einsatz') {
        $typ_save = ($typ_sonstige ?? 'einsatz') === 'einsatz' ? 'einsatz' : 'manuell';
        $draft['typ'] = $typ_save;
    }
    $is_jhv_sonstiges = ($typ_save === 'manuell' && in_array(trim($draft['bezeichnung_sonstige'] ?? ''), ['Sonstiges'], true))
        || ($typ_save === 'dienst' && isset($dienst) && in_array($dienst['typ'] ?? '', ['sonstiges'], true));
    $bezeichnung_save = $typ_save === 'einsatz' ? $draft['bezeichnung_sonstige'] : ($typ_save === 'manuell' ? ($draft['thema'] ?? $draft['bezeichnung_sonstige']) : (($typ_save === 'dienst' && trim((string)($draft['thema'] ?? '')) !== '') ? trim($draft['thema']) : null));
    if ($is_jhv_sonstiges) {
        $beschreibung = trim((string)($draft['beschreibung'] ?? ''));
        $bezeichnung_save = $beschreibung !== '' ? $beschreibung : ($typ_save === 'dienst' && isset($dienst) ? ($dienst['bezeichnung'] ?? null) : (isset($dienst) ? get_dienstplan_typ_label($dienst['typ'] ?? '') : ''));
    }
    $uhrzeit_von_save = $draft['uhrzeit_von'] !== '' ? $draft['uhrzeit_von'] : null;
    $uhrzeit_bis_save = $draft['uhrzeit_bis'] !== '' ? $draft['uhrzeit_bis'] : null;

    // Pflichtfelder prüfen – Bericht darf nicht abgeschlossen werden, wenn fehlend
    $is_uebungsdienst = ($typ_save === 'manuell' && trim($draft['bezeichnung_sonstige'] ?? '') === 'Übungsdienst')
        || ($typ_save === 'dienst' && isset($dienst) && ($dienst['typ'] ?? '') === 'uebungsdienst');
    $pflichtfehler = [];
    if ($is_jhv_sonstiges) {
        // JHV/Sonstiges: nur Uhrzeiten, Berichtersteller – Thema und Übungsleiter nicht erforderlich
        if (empty(trim((string)($draft['uhrzeit_von'] ?? '')))) $pflichtfehler[] = 'Uhrzeit von';
        if (empty(trim((string)($draft['uhrzeit_bis'] ?? '')))) $pflichtfehler[] = 'Uhrzeit bis';
    } elseif ($is_uebungsdienst) {
        // Übungsdienst: Datum, Uhrzeiten, Thema, Übungsleiter
        if (empty(trim((string)($draft['uhrzeit_von'] ?? '')))) $pflichtfehler[] = 'Uhrzeit von';
        if (empty(trim((string)($draft['uhrzeit_bis'] ?? '')))) $pflichtfehler[] = 'Uhrzeit bis';
        if (empty(trim((string)($draft['thema'] ?? '')))) $pflichtfehler[] = 'Thema';
        $ueb_ids = $draft['uebungsleiter_member_ids'] ?? [];
        if (empty($ueb_ids) || !is_array($ueb_ids) || count(array_filter($ueb_ids)) === 0) $pflichtfehler[] = 'Übungsleiter';
    } elseif ($typ_save === 'einsatz') {
        // Einsatz: Datum (immer gesetzt), Uhrzeiten, Einsatzstichwort, Einsatzleiter, Einsatzstelle
        if (empty(trim((string)($draft['uhrzeit_von'] ?? '')))) $pflichtfehler[] = 'Uhrzeit von';
        if (empty(trim((string)($draft['uhrzeit_bis'] ?? '')))) $pflichtfehler[] = 'Uhrzeit bis';
        if (empty(trim((string)($draft['einsatzstichwort'] ?? '')))) $pflichtfehler[] = 'Einsatzstichwort';
        if (empty(trim((string)($draft['einsatzstelle'] ?? '')))) $pflichtfehler[] = 'Einsatzstelle';
        $el_freitext = trim((string)($draft['einsatzleiter_freitext'] ?? ''));
        $has_el = !empty($draft['einsatzleiter_member_id']) || !empty($el_freitext);
        if (!$has_el) $pflichtfehler[] = 'Einsatzleiter';
    } else {
        // Normaler Dienst (z.B. Wachdienst): nur Uhrzeiten
        if (empty(trim((string)($draft['uhrzeit_von'] ?? '')))) $pflichtfehler[] = 'Uhrzeit von';
        if (empty(trim((string)($draft['uhrzeit_bis'] ?? '')))) $pflichtfehler[] = 'Uhrzeit bis';
    }
    $berichtersteller_val = trim((string)($draft['berichtersteller'] ?? ''));
    if ($berichtersteller_val === '') $pflichtfehler[] = 'Berichtersteller';
    if (!empty($pflichtfehler)) {
        $error = 'Bitte füllen Sie alle Pflichtfelder aus: ' . implode(', ', $pflichtfehler) . '. Sie können den Bericht später fortsetzen.';
    }

    if (empty($error)) {
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
        try {
        $gwm_id = null;
        $custom_data_for_save = $draft['custom_data'] ?? [];
        if (!empty($draft['uebungsleiter_member_ids'])) {
            $custom_data_for_save['uebungsleiter_member_ids'] = $draft['uebungsleiter_member_ids'];
        }
        if ($is_jhv_sonstiges) {
            $typ_sonstige_val = ($typ_save === 'dienst' && isset($dienst))
                ? ($dienst['typ'] ?? 'sonstiges')
                : 'sonstiges';
            $custom_data_for_save['typ_sonstige'] = $typ_sonstige_val;
            if (trim((string)($draft['beschreibung'] ?? '')) !== '') {
                $custom_data_for_save['beschreibung'] = trim($draft['beschreibung']);
            }
        }
        if (!empty($draft['vehicle_equipment'])) {
            $custom_data_for_save['vehicle_equipment'] = $draft['vehicle_equipment'];
        }
        if (!empty($draft['vehicle_equipment_sonstiges'])) {
            $custom_data_for_save['vehicle_equipment_sonstiges'] = $draft['vehicle_equipment_sonstiges'];
        }
        if (!empty($draft['member_pa']) && is_array($draft['member_pa'])) {
            $custom_data_for_save['member_pa'] = array_values(array_map('intval', array_filter($draft['member_pa'])));
        }
        if (trim((string)($draft['berichtersteller'] ?? '')) !== '') {
            $ber_val = trim($draft['berichtersteller']);
            $custom_data_for_save['berichtersteller'] = $ber_val;
            $berichtersteller_text = $ber_val;
            if (ctype_digit($ber_val)) {
                try {
                    $stmt_m = $db->prepare("SELECT first_name, last_name FROM members WHERE id = ?");
                    $stmt_m->execute([(int)$ber_val]);
                    $m = $stmt_m->fetch(PDO::FETCH_ASSOC);
                    if ($m) $berichtersteller_text = trim($m['last_name'] . ', ' . $m['first_name']);
                } catch (Exception $e) {}
            }
            $custom_data_for_save['berichtersteller_text'] = $berichtersteller_text;
        }
        $custom_data_json = !empty($custom_data_for_save) ? json_encode($custom_data_for_save) : null;
        $einsatzstichwort_save = ($typ_save === 'einsatz' && !empty($draft['einsatzstichwort'])) ? $draft['einsatzstichwort'] : null;
        $divera_id_save = !empty($draft['divera_id']) ? (int)$draft['divera_id'] : null;
        if ($edit_id_save > 0) {
            $stmt = $db->prepare("
                UPDATE anwesenheitslisten SET datum=?, dienstplan_id=?, typ=?, bezeichnung=?, bemerkung=?, einsatzstichwort=?,
                    uhrzeit_von=?, uhrzeit_bis=?, alarmierung_durch=?, einsatzstelle=?, objekt=?, eigentuemer=?, geschaedigter=?,
                    klassifizierung=?, kostenpflichtiger_einsatz=?, personenschaeden=?, brandwache=?, einsatzleiter_member_id=?, einsatzleiter_freitext=?, custom_data=?, einheit_id=?
                WHERE id=?
            ");
            $stmt->execute([
                $draft['datum'], $dp_id, $typ_save, $bezeichnung_save, $draft['bemerkung'] !== '' ? $draft['bemerkung'] : null, $einsatzstichwort_save,
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
                $custom_data_json,
                $einheit_id > 0 ? $einheit_id : null,
                $edit_id_save
            ]);
            $list_id = $edit_id_save;
            $db->prepare("DELETE FROM anwesenheitsliste_mitglieder WHERE anwesenheitsliste_id = ?")->execute([$list_id]);
            $db->prepare("DELETE FROM anwesenheitsliste_fahrzeuge WHERE anwesenheitsliste_id = ?")->execute([$list_id]);
        } else {
            $stmt = $db->prepare("
                INSERT INTO anwesenheitslisten (datum, dienstplan_id, typ, bezeichnung, user_id, bemerkung, einsatzstichwort,
                    uhrzeit_von, uhrzeit_bis, alarmierung_durch, einsatzstelle, objekt, eigentuemer, geschaedigter,
                    klassifizierung, kostenpflichtiger_einsatz, personenschaeden, brandwache, einsatzleiter_member_id, einsatzleiter_freitext, custom_data, divera_id, einheit_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
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
                $custom_data_json,
                $divera_id_save,
                $einheit_id > 0 ? $einheit_id : null
            ]);
            $list_id = $db->lastInsertId();
        }
        // vehicle_id: Priorität Maschinist > Einheitsführer > Personal-Zuordnung (verhindert falsche Zuordnung in Auswertung)
        foreach ($draft['members'] as $mid) {
            $vid = null;
            foreach ($draft['vehicle_maschinist'] ?? [] as $v => $m) {
                if ((int)$m === (int)$mid) { $vid = (int)$v; break; }
            }
            if ($vid === null) {
                foreach ($draft['vehicle_einheitsfuehrer'] ?? [] as $v => $m) {
                    if ((int)$m === (int)$mid) { $vid = (int)$v; break; }
                }
            }
            if ($vid === null) {
                $vid = isset($draft['member_vehicle'][$mid]) ? (int)$draft['member_vehicle'][$mid] : null;
            }
            $vid = ($vid > 0) ? $vid : null;
            $stmt = $db->prepare("INSERT INTO anwesenheitsliste_mitglieder (anwesenheitsliste_id, member_id, vehicle_id) VALUES (?, ?, ?)");
            $stmt->execute([$list_id, $mid, $vid]);
        }
        $all_vehicle_ids = array_unique(array_merge(
            $draft['vehicles'] ?? [],
            array_values(array_filter($draft['member_vehicle'] ?? [])),
            array_keys($draft['vehicle_equipment'] ?? []),
            array_keys($draft['vehicle_equipment_sonstiges'] ?? []),
            array_filter(array_map(function($m) {
                $vid = isset($m['vehicle_id']) && preg_match('/^\d+$/', (string)$m['vehicle_id']) ? (int)$m['vehicle_id'] : 0;
                return $vid > 0 ? $vid : null;
            }, $draft['maengel'] ?? []))
        ));
        $all_vehicle_ids = array_filter(array_map('intval', $all_vehicle_ids), fn($x) => $x > 0);
        foreach ($all_vehicle_ids as $vid) {
            $masch = isset($draft['vehicle_maschinist'][$vid]) ? (int)$draft['vehicle_maschinist'][$vid] : null;
            $einh = isset($draft['vehicle_einheitsfuehrer'][$vid]) ? (int)$draft['vehicle_einheitsfuehrer'][$vid] : null;
            if ($masch !== null && $masch <= 0) $masch = null;
            if ($einh !== null && $einh <= 0) $einh = null;
            try {
                $stmt = $db->prepare("INSERT INTO anwesenheitsliste_fahrzeuge (anwesenheitsliste_id, vehicle_id, maschinist_member_id, einheitsfuehrer_member_id) VALUES (?, ?, ?, ?)");
                $stmt->execute([$list_id, $vid, $masch, $einh]);
            } catch (Exception $e) {
                // Tabelle evtl. nicht vorhanden
            }
        }

        // Automatisch Gerätewartmitteilung erstellen (nur bei neuer Liste, nicht beim Bearbeiten)
        if ($edit_id_save <= 0 && !empty($all_vehicle_ids)) {
            try {
                $db->exec("CREATE TABLE IF NOT EXISTS geraetewartmitteilungen (id INT AUTO_INCREMENT PRIMARY KEY, typ VARCHAR(20) NOT NULL, einsatz_uebungsart VARCHAR(50) NOT NULL, datum DATE NOT NULL, einsatzbereitschaft VARCHAR(30) NOT NULL, mangel_beschreibung TEXT NULL, einsatzleiter_member_id INT NULL, einsatzleiter_freitext VARCHAR(255) NULL, user_id INT NOT NULL, einheit_id INT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE, KEY idx_datum (datum), KEY idx_created_at (created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                $db->exec("CREATE TABLE IF NOT EXISTS geraetewartmitteilung_fahrzeuge (id INT AUTO_INCREMENT PRIMARY KEY, geraetewartmitteilung_id INT NOT NULL, vehicle_id INT NOT NULL, maschinist_member_id INT NULL, einheitsfuehrer_member_id INT NULL, equipment_used JSON NULL, defective_equipment JSON NULL, defective_freitext TEXT NULL, defective_mangel TEXT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (geraetewartmitteilung_id) REFERENCES geraetewartmitteilungen(id) ON DELETE CASCADE, FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE, UNIQUE KEY unique_gwm_vehicle (geraetewartmitteilung_id, vehicle_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                $gwm_typ = ($typ_save === 'einsatz') ? 'einsatz' : 'uebung';
                if ($typ_save === 'einsatz') {
                    $gwm_art = trim($draft['klassifizierung'] ?? '') !== '' ? trim($draft['klassifizierung']) : (trim($draft['einsatzstichwort'] ?? '') !== '' ? trim($draft['einsatzstichwort']) : (trim($draft['bezeichnung_sonstige'] ?? '') !== '' ? trim($draft['bezeichnung_sonstige']) : 'Sonstiges'));
                } else {
                    $gwm_art = trim($draft['thema'] ?? '') !== '' ? trim($draft['thema']) : (trim($bezeichnung_save ?? '') !== '' ? trim($bezeichnung_save) : 'Sonstiges');
                }
                $gwm_art = substr($gwm_art, 0, 50);
                $gwm_el_mid = $draft['einsatzleiter_member_id'] ?? null;
                $gwm_el_txt = !empty(trim((string)($draft['einsatzleiter_freitext'] ?? ''))) ? trim($draft['einsatzleiter_freitext']) : null;
                $stmt_gwm = $db->prepare("INSERT INTO geraetewartmitteilungen (typ, einsatz_uebungsart, datum, einsatzbereitschaft, mangel_beschreibung, einsatzleiter_member_id, einsatzleiter_freitext, user_id, einheit_id) VALUES (?, ?, ?, 'hergestellt', NULL, ?, ?, ?, ?)");
                $stmt_gwm->execute([$gwm_typ, $gwm_art, $draft['datum'], $gwm_el_mid, $gwm_el_txt, $_SESSION['user_id'], $einheit_id > 0 ? $einheit_id : null]);
                $gwm_id = (int)$db->lastInsertId();
                $maengel_list = $draft['maengel'] ?? [];
                $stmt_gwm_f = $db->prepare("INSERT INTO geraetewartmitteilung_fahrzeuge (geraetewartmitteilung_id, vehicle_id, maschinist_member_id, einheitsfuehrer_member_id, equipment_used, defective_equipment, defective_freitext, defective_mangel) VALUES (?, ?, ?, ?, ?, '[]', ?, ?)");
                foreach ($all_vehicle_ids as $vid) {
                    $masch = isset($draft['vehicle_maschinist'][$vid]) && preg_match('/^\d+$/', (string)$draft['vehicle_maschinist'][$vid]) ? (int)$draft['vehicle_maschinist'][$vid] : null;
                    $einh = isset($draft['vehicle_einheitsfuehrer'][$vid]) && preg_match('/^\d+$/', (string)$draft['vehicle_einheitsfuehrer'][$vid]) ? (int)$draft['vehicle_einheitsfuehrer'][$vid] : null;
                    $eq_used = isset($draft['vehicle_equipment'][$vid]) && is_array($draft['vehicle_equipment'][$vid]) ? array_values(array_filter(array_map('intval', $draft['vehicle_equipment'][$vid]), fn($x) => $x > 0)) : [];
                    $defective_freitext = null;
                    $defective_mangel = null;
                    foreach ($maengel_list as $m) {
                        $m_vehicle_id = isset($m['vehicle_id']) && preg_match('/^\d+$/', (string)$m['vehicle_id']) ? (int)$m['vehicle_id'] : null;
                        if ($m_vehicle_id === (int)$vid) {
                            $bez = trim($m['bezeichnung'] ?? '');
                            $mb = trim($m['mangel_beschreibung'] ?? '');
                            if ($bez !== '' || $mb !== '') {
                                $defective_freitext = $defective_freitext === null ? $bez : $defective_freitext . ', ' . $bez;
                                $defective_mangel = $defective_mangel === null ? $mb : $defective_mangel . '; ' . $mb;
                            }
                        }
                    }
                    $stmt_gwm_f->execute([$gwm_id, $vid, $masch, $einh, json_encode($eq_used), $defective_freitext, $defective_mangel]);
                }
            } catch (Exception $e) {
                error_log('Anwesenheitsliste Gerätewartmitteilung: ' . $e->getMessage());
            }
        }

        // Mängel aus Draft: Mängelberichte automatisch erstellen
        $maengelbericht_ids = [];
        $maengel_list = $draft['maengel'] ?? [];
        if (!empty($maengel_list) && is_array($maengel_list)) {
            try {
                $db->exec("CREATE TABLE IF NOT EXISTS maengelberichte (id INT AUTO_INCREMENT PRIMARY KEY, standort VARCHAR(100) NOT NULL, mangel_an VARCHAR(50) NOT NULL, bezeichnung VARCHAR(255) NULL, mangel_beschreibung TEXT NULL, ursache TEXT NULL, verbleib TEXT NULL, aufgenommen_durch_text VARCHAR(255) NULL, aufgenommen_durch_member_id INT NULL, aufgenommen_am DATE NOT NULL, vehicle_id INT NULL, user_id INT NOT NULL, einheit_id INT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, email_sent_at DATETIME NULL, KEY idx_aufgenommen_am (aufgenommen_am), KEY idx_created_at (created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                try { $db->exec("ALTER TABLE maengelberichte ADD COLUMN email_sent_at DATETIME NULL"); } catch (Exception $e) { /* Spalte existiert ggf. bereits */ }
                try { $db->exec("ALTER TABLE maengelberichte ADD COLUMN vehicle_id INT NULL"); } catch (Exception $e) { /* Spalte existiert ggf. bereits */ }
                try { $db->exec("ALTER TABLE maengelberichte ADD COLUMN einheit_id INT NULL"); } catch (Exception $e) { /* Spalte existiert ggf. bereits */ }
                $stmt_mb = $db->prepare("INSERT INTO maengelberichte (standort, mangel_an, bezeichnung, mangel_beschreibung, ursache, verbleib, aufgenommen_durch_text, aufgenommen_durch_member_id, aufgenommen_am, vehicle_id, user_id, einheit_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                foreach ($maengel_list as $m) {
                    $standort = $m['standort'] ?? 'GH Amern';
                    $mangel_an = $m['mangel_an'] ?? 'Gebäude';
                    $bezeichnung = $m['bezeichnung'] ?? null;
                    $mangel_beschreibung = $m['mangel_beschreibung'] ?? null;
                    $ursache = $m['ursache'] ?? null;
                    $verbleib = $m['verbleib'] ?? null;
                    $auf_durch = trim($m['aufgenommen_durch'] ?? '');
                    $auf_member_id = null;
                    $auf_text = null;
                    if (preg_match('/^\d+$/', $auf_durch)) {
                        $auf_member_id = (int)$auf_durch;
                    } else {
                        $auf_text = $auf_durch !== '' ? $auf_durch : null;
                    }
                    $vehicle_id = isset($m['vehicle_id']) && preg_match('/^\d+$/', (string)$m['vehicle_id']) ? (int)$m['vehicle_id'] : null;
                    $auf_am = date('Y-m-d');
                    $stmt_mb->execute([$standort, $mangel_an, $bezeichnung, $mangel_beschreibung, $ursache, $verbleib, $auf_text, $auf_member_id, $auf_am, $vehicle_id, $_SESSION['user_id'], $einheit_id > 0 ? $einheit_id : null]);
                    $new_mb_id = (int)$db->lastInsertId();
                    $maengelbericht_ids[] = $new_mb_id;
                    $temps = $m['anhaenge_temp'] ?? [];
                    if (!empty($temps) && is_array($temps)) {
                        bericht_anhaenge_commit_draft_files($db, 'maengelbericht', $new_mb_id, $temps);
                    }
                }
            } catch (Exception $e) {
                error_log('Anwesenheitsliste Mängelberichte: ' . $e->getMessage());
            }
        }
        $al_temps = $draft['anhaenge_temp'] ?? [];
        if (!empty($al_temps) && is_array($al_temps)) {
            bericht_anhaenge_commit_draft_files($db, 'anwesenheitsliste', (int)$list_id, $al_temps);
        }
        bericht_anhaenge_save_for_anwesenheitsliste($db, (int)$list_id, $_FILES);
        // PA-Checkbox: Automatische Atemschutzeinträge für PA-Träger erstellen (Status pending, Genehmigung erforderlich)
        $member_pa = $draft['member_pa'] ?? [];
        if (!empty($member_pa) && is_array($member_pa)) {
            $entry_type = 'uebung';
            if ($typ_save === 'einsatz') {
                $entry_type = 'einsatz';
            } elseif ($typ_save === 'manuell' && trim($draft['bezeichnung_sonstige'] ?? '') === 'Übungsdienst') {
                $entry_type = 'uebung';
            } elseif ($typ_save === 'dienst' && isset($dienst) && ($dienst['typ'] ?? '') === 'uebungsdienst') {
                $entry_type = 'uebung';
            } else {
                $entry_type = 'uebung';
            }
            $entry_date = $draft['datum'];
            $traeger_ids = [];
            foreach (array_map('intval', array_filter($member_pa)) as $mid) {
                if ($mid <= 0) continue;
                try {
                    $st = $db->prepare("SELECT id FROM atemschutz_traeger WHERE member_id = ? AND status = 'Aktiv'");
                    $st->execute([$mid]);
                    $tid = $st->fetchColumn();
                    if ($tid) $traeger_ids[] = (int)$tid;
                } catch (Exception $e) { /* Tabelle evtl. nicht vorhanden */ }
            }
            if (!empty($traeger_ids)) {
                try {
                    $user_id_save = (int)($_SESSION['user_id'] ?? 0);
                    $user_name = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')) ?: 'Unbekannt';
                    $stmt_ae = $db->prepare("INSERT INTO atemschutz_entries (entry_type, entry_date, requester_id, status) VALUES (?, ?, ?, 'pending')");
                    $stmt_ae->execute([$entry_type, $entry_date, $user_id_save ?: null]);
                    $entry_id = $db->lastInsertId();
                    $stmt_et = $db->prepare("INSERT INTO atemschutz_entry_traeger (entry_id, traeger_id) VALUES (?, ?)");
                    foreach ($traeger_ids as $tid) {
                        $stmt_et->execute([$entry_id, $tid]);
                    }
                    // E-Mail an Atemschutz-Admins
                    $stmt_adm = $db->prepare("SELECT email FROM users WHERE atemschutz_notifications = 1 AND (COALESCE(is_system_user, 0) = 0)");
                    $stmt_adm->execute();
                    $admins = $stmt_adm->fetchAll(PDO::FETCH_COLUMN);
                    if (!empty($admins) && function_exists('send_email')) {
                        $ph = implode(',', array_fill(0, count($traeger_ids), '?'));
                        $stmt_n = $db->prepare("SELECT first_name, last_name FROM atemschutz_traeger WHERE id IN ($ph)");
                        $stmt_n->execute($traeger_ids);
                        $names = array_map(fn($r) => trim($r['first_name'] . ' ' . $r['last_name']), $stmt_n->fetchAll(PDO::FETCH_ASSOC));
                        $type_name = $entry_type === 'einsatz' ? 'Einsatz' : 'Übung';
                        $formatted_date = date('d.m.Y', strtotime($entry_date));
                        try {
                            $stmtApp = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'app_url'");
                            $stmtApp->execute();
                            $appUrl = $stmtApp->fetchColumn() ?: ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST']);
                        } catch (Exception $e) {
                            $appUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
                        }
                        $msg = '<p>Ein neuer Atemschutzeintrag-Antrag aus der Anwesenheitsliste wartet auf Ihre Genehmigung.</p>';
                        $msg .= '<p><strong>Typ:</strong> ' . htmlspecialchars($type_name) . ', <strong>Datum:</strong> ' . htmlspecialchars($formatted_date) . ', <strong>Antragsteller:</strong> ' . htmlspecialchars($user_name) . '</p>';
                        $msg .= '<p><strong>Geräteträger:</strong> ' . htmlspecialchars(implode(', ', $names)) . '</p>';
                        $msg .= '<p><a href="' . htmlspecialchars($appUrl . '/admin/dashboard.php') . '">Antrag bearbeiten</a></p>';
                        $subject = 'Neuer Atemschutzeintrag-Antrag (Anwesenheitsliste) - ' . $type_name;
                        foreach ($admins as $admin_email) {
                            if (trim($admin_email) !== '') send_email(trim($admin_email), $subject, $msg, '', true);
                        }
                    }
                    if ($user_id_save && function_exists('log_activity')) {
                        log_activity($user_id_save, 'atemschutz_entry_created', "Atemschutzeintrag-Antrag #$entry_id aus Anwesenheitsliste ($entry_type)");
                    }
                } catch (Exception $e) {
                    error_log('Anwesenheitsliste PA-Atemschutz: ' . $e->getMessage());
                }
            }
        }

        // Automatischer E-Mail-Versand (wenn aktiviert)
        $email_auto = false;
        $email_recipients = [];
        $email_manual = '';
        try {
            $stmt_s = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('anwesenheitsliste_email_auto', 'anwesenheitsliste_email_recipients', 'anwesenheitsliste_email_manual')");
            $stmt_s->execute();
            foreach ($stmt_s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if ($r['setting_key'] === 'anwesenheitsliste_email_auto') $email_auto = ($r['setting_value'] ?? '0') === '1';
                elseif ($r['setting_key'] === 'anwesenheitsliste_email_recipients') $email_recipients = json_decode($r['setting_value'] ?? '[]', true) ?: [];
                elseif ($r['setting_key'] === 'anwesenheitsliste_email_manual') $email_manual = trim($r['setting_value'] ?? '');
            }
        } catch (Exception $e) {}
        $all_emails = [];
        if ($email_auto && (is_array($email_recipients) && !empty($email_recipients) || $email_manual !== '')) {
            if (!empty($email_recipients)) {
                $ph = implode(',', array_fill(0, count($email_recipients), '?'));
                $stmt_u = $db->prepare("SELECT email FROM users WHERE id IN ($ph) AND email IS NOT NULL AND email != ''");
                $stmt_u->execute(array_map('intval', $email_recipients));
                foreach ($stmt_u->fetchAll(PDO::FETCH_COLUMN) as $em) { $all_emails[] = trim($em); }
            }
            if ($email_manual !== '') {
                foreach (preg_split('/[\r\n,;]+/', $email_manual, -1, PREG_SPLIT_NO_EMPTY) as $em) {
                    $em = trim($em);
                    if (filter_var($em, FILTER_VALIDATE_EMAIL)) $all_emails[] = $em;
                }
            }
            $all_emails = array_unique(array_filter($all_emails));
            if (!empty($all_emails) && function_exists('send_email_with_pdf_attachment')) {
                $pdf_content = null;
                $_GET['id'] = $list_id;
                $_GET['_return'] = '1';
                $GLOBALS['_al_pdf_content'] = null;
                try {
                    ob_start();
                    require __DIR__ . '/api/anwesenheitsliste-pdf.php';
                    ob_end_clean();
                    $pdf_content = $GLOBALS['_al_pdf_content'] ?? null;
                } catch (Exception $e) { ob_end_clean(); }
                if ($pdf_content !== null && strlen($pdf_content) > 100) {
                    $titel = $bezeichnung_save ?? $draft['bezeichnung_sonstige'] ?? $draft['thema'] ?? 'Anwesenheit';
                    $filename = 'Anwesenheitsliste_' . $draft['datum'] . '_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $titel) . '.pdf';
                    $subject = 'Neue Anwesenheitsliste: ' . $titel . ' (' . date('d.m.Y', strtotime($draft['datum'])) . ')';
                    $user_name = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')) ?: 'Unbekannt';
                    $html = '<p>Eine neue Anwesenheitsliste wurde eingereicht.</p><p><strong>Datum:</strong> ' . htmlspecialchars(date('d.m.Y', strtotime($draft['datum']))) . '<br><strong>Bezeichnung:</strong> ' . htmlspecialchars($titel) . '<br><strong>Eingereicht von:</strong> ' . htmlspecialchars($user_name) . '</p><p>Die Anwesenheitsliste ist dieser E-Mail als PDF angehängt.</p>';
                    foreach ($all_emails as $em) {
                        if (trim($em) !== '') send_email_with_pdf_attachment(trim($em), $subject, $html, $pdf_content, $filename);
                    }
                }
            }
        }

        // Automatischer E-Mail-Versand für Mängelberichte (wenn aktiviert und Mängel gespeichert)
        if (!empty($maengelbericht_ids)) {
            $mb_email_auto = false;
            $mb_email_recipients = [];
            $mb_email_manual = '';
            try {
                $stmt_s = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('maengelbericht_email_auto', 'maengelbericht_email_recipients', 'maengelbericht_email_manual')");
                $stmt_s->execute();
                foreach ($stmt_s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    if ($r['setting_key'] === 'maengelbericht_email_auto') $mb_email_auto = ($r['setting_value'] ?? '0') === '1';
                    elseif ($r['setting_key'] === 'maengelbericht_email_recipients') $mb_email_recipients = json_decode($r['setting_value'] ?? '[]', true) ?: [];
                    elseif ($r['setting_key'] === 'maengelbericht_email_manual') $mb_email_manual = trim($r['setting_value'] ?? '');
                }
            } catch (Exception $e) {}
            $mb_all_emails = [];
            if ($mb_email_auto && (is_array($mb_email_recipients) && !empty($mb_email_recipients) || $mb_email_manual !== '')) {
                if (!empty($mb_email_recipients)) {
                    $ph = implode(',', array_fill(0, count($mb_email_recipients), '?'));
                    $stmt_u = $db->prepare("SELECT email FROM users WHERE id IN ($ph) AND email IS NOT NULL AND email != ''");
                    $stmt_u->execute(array_map('intval', $mb_email_recipients));
                    foreach ($stmt_u->fetchAll(PDO::FETCH_COLUMN) as $em) { $mb_all_emails[] = trim($em); }
                }
                if ($mb_email_manual !== '') {
                    foreach (preg_split('/[\r\n,;]+/', $mb_email_manual, -1, PREG_SPLIT_NO_EMPTY) as $em) {
                        $em = trim($em);
                        if (filter_var($em, FILTER_VALIDATE_EMAIL)) $mb_all_emails[] = $em;
                    }
                }
                $mb_all_emails = array_unique(array_filter($mb_all_emails));
                if (!empty($mb_all_emails) && function_exists('send_email_with_pdf_attachment')) {
                    $mb_pdf_content = null;
                    $_GET['ids'] = implode(',', $maengelbericht_ids);
                    $_GET['_return'] = '1';
                    $GLOBALS['_mb_pdf_content'] = null;
                    try {
                        ob_start();
                        require __DIR__ . '/api/maengelbericht-pdf-alle.php';
                        ob_end_clean();
                        $mb_pdf_content = $GLOBALS['_mb_pdf_content'] ?? null;
                    } catch (Exception $e) { ob_end_clean(); }
                    if ($mb_pdf_content !== null && strlen($mb_pdf_content) > 100) {
                        $titel = $bezeichnung_save ?? $draft['bezeichnung_sonstige'] ?? $draft['thema'] ?? 'Anwesenheit';
                        $filename = 'Maengelberichte_' . $draft['datum'] . '_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $titel) . '.pdf';
                        $subject = 'Neue Mängelberichte aus Anwesenheitsliste: ' . $titel . ' (' . date('d.m.Y', strtotime($draft['datum'])) . ')';
                        $user_name = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')) ?: 'Unbekannt';
                        $html = '<p>Es wurden ' . count($maengelbericht_ids) . ' Mängelbericht(e) aus der Anwesenheitsliste eingereicht.</p><p><strong>Datum:</strong> ' . htmlspecialchars(date('d.m.Y', strtotime($draft['datum']))) . '<br><strong>Bezeichnung:</strong> ' . htmlspecialchars($titel) . '<br><strong>Eingereicht von:</strong> ' . htmlspecialchars($user_name) . '</p><p>Die Mängelberichte sind dieser E-Mail als PDF angehängt.</p>';
                        foreach ($mb_all_emails as $em) {
                            if (trim($em) !== '') send_email_with_pdf_attachment(trim($em), $subject, $html, $mb_pdf_content, $filename);
                        }
                        try {
                            $ph = implode(',', array_fill(0, count($maengelbericht_ids), '?'));
                            $stmt_up = $db->prepare("UPDATE maengelberichte SET email_sent_at = NOW() WHERE id IN ($ph)");
                            $stmt_up->execute(array_map('intval', $maengelbericht_ids));
                        } catch (Exception $e) {}
                    }
                }
            }
        }

        unset($_SESSION[$draft_key]);
        try {
            $eidDraftDel = $einheit_id > 0 ? $einheit_id : 1;
            $db->prepare("DELETE FROM anwesenheitsliste_drafts WHERE datum = ? AND auswahl = ? AND einheit_id = ?")->execute([$draft['datum'], $draft['auswahl'], $eidDraftDel]);
        } catch (Exception $e) { /* ignore */ }
        $return_fc = !empty($_POST['return']) && $_POST['return'] === 'formularcenter';
        $redirect = $return_fc ? 'admin/formularcenter.php?tab=submissions&message=' . urlencode('Anwesenheitsliste wurde gespeichert.') : 'anwesenheitsliste.php?message=erfolg&datum=' . urlencode($draft['datum'] ?? date('Y-m-d')) . ($einheit_id > 0 ? '&einheit_id=' . (int)$einheit_id : '');
        if (!$return_fc) {
            $print_after = !empty($_POST['print_after_save']) && $_POST['print_after_save'] === '1';
            $print_mb = !empty($_POST['print_maengelbericht_after_save']) && $_POST['print_maengelbericht_after_save'] === '1';
            $print_gwm = !empty($_POST['print_geraetewartmitteilung_after_save']) && $_POST['print_geraetewartmitteilung_after_save'] === '1';
            if ($print_after && $list_id) $redirect .= '&print=' . (int)$list_id;
            if ($print_mb && !empty($maengelbericht_ids)) $redirect .= '&print_maengelbericht=' . urlencode(implode(',', array_map('intval', $maengelbericht_ids)));
            if ($print_gwm && $gwm_id) $redirect .= '&print_geraetewartmitteilung=' . (int)$gwm_id;
        }
        header('Location: ' . $redirect);
        exit;
    } catch (Exception $e) {
        $error = 'Speichern fehlgeschlagen: ' . $e->getMessage();
    }
    }
}

$datum_display = $draft['datum'] ?? $datum;
$url_edit_suffix = ($edit_id > 0 ? '&edit_id=' . (int)$edit_id : '') . ($return_formularcenter ? '&return=formularcenter' : '') . ($einheit_id > 0 ? '&einheit_id=' . (int)$einheit_id : '');
$back_url = 'anwesenheitsliste-eingaben.php?datum=' . urlencode($datum_display) . '&auswahl=' . urlencode($auswahl) . $url_edit_suffix;
$personal_url = 'anwesenheitsliste-personal.php?datum=' . urlencode($datum_display) . '&auswahl=' . urlencode($auswahl) . $url_edit_suffix;
$fahrzeuge_url = 'anwesenheitsliste-fahrzeuge.php?datum=' . urlencode($datum_display) . '&auswahl=' . urlencode($auswahl) . $url_edit_suffix;
$geraete_url = 'anwesenheitsliste-geraete.php?datum=' . urlencode($datum_display) . '&auswahl=' . urlencode($auswahl) . $url_edit_suffix;
$maengel_url = 'anwesenheitsliste-maengel.php?datum=' . urlencode($datum_display) . '&auswahl=' . urlencode($auswahl) . $url_edit_suffix;
if ($is_einsatz) {
    $ts = trim($draft['bezeichnung_sonstige'] ?? 'Einsatz');
    $typ_key = array_search($ts, get_dienstplan_typen_auswahl());
    if ($typ_key === false) $typ_key = '__custom__';
    if ($typ_key !== 'einsatz') {
        $param_ts = '&typ_sonstige=' . urlencode($typ_key);
        $param_ueb = '';
        foreach ($draft['uebungsleiter_member_ids'] ?? [] as $uid) {
            if ((int)$uid > 0) $param_ueb .= '&uebungsleiter[]=' . (int)$uid;
        }
        $personal_url .= $param_ts . $param_ueb;
        $fahrzeuge_url .= $param_ts . $param_ueb;
        $geraete_url .= $param_ts . $param_ueb;
        $maengel_url .= $param_ts . $param_ueb;
    }
}
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
    <?php if ($is_einsatz): ?>
    <script>
    (function(){
        var urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('typ_sonstige')) {
            try { sessionStorage.removeItem('anwesenheit_typ_sonstige'); sessionStorage.removeItem('anwesenheit_uebungsleiter'); } catch(e){}
            return;
        }
        try {
            var ts = sessionStorage.getItem('anwesenheit_typ_sonstige');
            var ueb = sessionStorage.getItem('anwesenheit_uebungsleiter');
            if (!ts || ts === '') return;
            var uebIds = [];
            try { uebIds = ueb ? JSON.parse(ueb) : []; } catch(e){}
            var base = window.location.pathname + window.location.search;
            var sep = base.indexOf('?') >= 0 ? '&' : '?';
            var add = 'typ_sonstige=' + encodeURIComponent(ts);
            uebIds.forEach(function(id){ if(id) add += '&uebungsleiter[]=' + encodeURIComponent(id); });
            window.location.replace(base + sep + add);
        } catch(e){}
    })();
    </script>
    <?php endif; ?>
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
            <div class="col-lg-8">
                <div class="card shadow">
                    <div class="card-header"><h3 class="mb-0"><i class="fas fa-clipboard-list"></i> Anwesenheitsliste – Personal & Fahrzeuge</h3></div>
                    <div class="card-body p-4">
                        <div id="validationError" class="alert alert-danger" style="display: none;" role="alert"></div>
                        <?php if ($error): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
                        <div class="alert alert-light border mb-4">
                            <strong>Gewählt:</strong><br>
                            <div class="d-flex flex-wrap align-items-center gap-3 mt-2">
                                <form method="get" class="d-inline-flex align-items-center gap-2">
                                    <input type="hidden" name="auswahl" value="<?php echo htmlspecialchars($auswahl); ?>">
                                    <label for="datum_aendern" class="form-label mb-0 small">Datum:</label>
                                    <input type="date" id="datum_aendern" name="datum" class="form-control form-control-sm" value="<?php echo htmlspecialchars($draft['datum'] ?? $datum); ?>" style="width: auto;">
                                    <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="fas fa-sync-alt"></i> Übernehmen</button>
                                </form>
                                <?php
                                $time_fields = array_filter($anwesenheitsliste_felder, fn($f) => ($f['type'] ?? '') === 'time' && !empty($f['visible']));
                                if (count($time_fields) > 0):
                                ?>
                                <div class="d-inline-flex align-items-center gap-2 ms-2">
                                    <?php foreach ($time_fields as $tf):
                                        $tid = $tf['id'];
                                        $tval = $tid === 'uhrzeit_bis' && empty($draft[$tid]) ? date('H:i') : ($draft[$tid] ?? '');
                                    ?>
                                    <label for="uhrzeit_header_<?php echo htmlspecialchars($tid); ?>" class="form-label mb-0 small"><?php echo htmlspecialchars($tf['label'] ?? $tid); ?>:</label>
                                    <input type="time" form="mainForm" id="uhrzeit_header_<?php echo htmlspecialchars($tid); ?>" name="<?php echo htmlspecialchars($tid); ?>" class="form-control form-control-sm" value="<?php echo htmlspecialchars($tval); ?>" style="width: auto;">
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            <span class="d-block mt-1 text-muted"><?php echo htmlspecialchars($titel_anzeige); ?></span>
                        </div>
                        <form method="post" id="mainForm" enctype="multipart/form-data">
                            <input type="hidden" name="save_final" value="1">
                            <input type="hidden" name="datum" id="datum_for_main" value="<?php echo htmlspecialchars($draft['datum'] ?? $datum); ?>">
                            <input type="hidden" name="print_after_save" id="print_after_save" value="0">
                            <input type="hidden" name="print_maengelbericht_after_save" id="print_maengelbericht_after_save" value="0">
                            <input type="hidden" name="print_geraetewartmitteilung_after_save" id="print_geraetewartmitteilung_after_save" value="0">
                            <?php if ($edit_id > 0): ?><input type="hidden" name="edit_id" value="<?php echo (int)$edit_id; ?>"><?php endif; ?>
                            <?php if ($return_formularcenter): ?><input type="hidden" name="return" value="formularcenter"><?php endif; ?>
                            <?php if ($is_einsatz): 
                                $dienstplan_themen = [];
                                $beschreibung_optionen_sonstiges = [];
                                $einheit_where_a = $einheit_id > 0 ? " AND (a.einheit_id = " . (int)$einheit_id . " OR a.einheit_id IS NULL)" : "";
                                try {
                                    if ($einheit_id > 0) {
                                        $stmt = $db->prepare("SELECT DISTINCT d.bezeichnung FROM dienstplan d WHERE d.einheit_id = ? OR d.einheit_id IS NULL ORDER BY d.bezeichnung");
                                        $stmt->execute([$einheit_id]);
                                    } else {
                                        $stmt = $db->query("SELECT DISTINCT bezeichnung FROM dienstplan ORDER BY bezeichnung");
                                    }
                                    $dienstplan_themen = $stmt->fetchAll(PDO::FETCH_COLUMN);
                                } catch (Exception $e) { /* ignore */ }
                                try {
                                    $von_b = date('Y-m-d', strtotime('-5 years'));
                                    $bis_b = date('Y-m-d', strtotime('+1 day'));
                                    $typ_cond_sonst = "((a.typ = 'dienst' AND d.typ IN ('sonstiges', 'jahreshauptversammlung')) OR (a.typ = 'manuell' AND (a.bezeichnung IN ('Sonstiges', 'Jahreshauptversammlung') OR (a.custom_data IS NOT NULL AND JSON_UNQUOTE(JSON_EXTRACT(a.custom_data, '$.typ_sonstige')) IN ('sonstiges', 'jahreshauptversammlung')))))";
                                    $stmt = $db->prepare("SELECT DISTINCT a.bezeichnung AS b FROM anwesenheitslisten a LEFT JOIN dienstplan d ON d.id = a.dienstplan_id WHERE a.datum BETWEEN ? AND ? AND " . $typ_cond_sonst . $einheit_where_a . " AND a.bezeichnung IS NOT NULL AND TRIM(a.bezeichnung) != '' ORDER BY b");
                                    $stmt->execute([$von_b, $bis_b]);
                                    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                                        $b = trim($r['b'] ?? '');
                                        if ($b !== '' && !in_array($b, $beschreibung_optionen_sonstiges)) $beschreibung_optionen_sonstiges[] = $b;
                                    }
                                    $stmt2 = $db->prepare("SELECT DISTINCT TRIM(JSON_UNQUOTE(JSON_EXTRACT(a.custom_data, '$.beschreibung'))) AS b FROM anwesenheitslisten a LEFT JOIN dienstplan d ON d.id = a.dienstplan_id WHERE a.datum BETWEEN ? AND ? AND " . $typ_cond_sonst . $einheit_where_a . " AND a.custom_data IS NOT NULL AND JSON_EXTRACT(a.custom_data, '$.beschreibung') IS NOT NULL AND TRIM(JSON_UNQUOTE(JSON_EXTRACT(a.custom_data, '$.beschreibung'))) != '' ORDER BY b");
                                    $stmt2->execute([$von_b, $bis_b]);
                                    while ($r = $stmt2->fetch(PDO::FETCH_ASSOC)) {
                                        $b = trim($r['b'] ?? '');
                                        if ($b !== '' && !in_array($b, $beschreibung_optionen_sonstiges)) $beschreibung_optionen_sonstiges[] = $b;
                                    }
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
                                <?php $bez = $draft['bezeichnung_sonstige'] ?? ''; $show_einsatzstichwort = $bez !== 'Übungsdienst' && $bez !== 'Sonstiges'; $show_thema = $bez === 'Übungsdienst'; $show_beschreibung = $bez === 'Sonstiges'; ?>
                                <div class="mt-3" id="einsatzstichwort_wrap" style="display: <?php echo $show_einsatzstichwort ? 'block' : 'none'; ?>;">
                                    <label for="einsatzstichwort" class="form-label">Einsatzstichwort</label>
                                    <input type="text" class="form-control" id="einsatzstichwort" name="einsatzstichwort" placeholder="z.B. FEUER3, THL" value="<?php echo htmlspecialchars($draft['einsatzstichwort'] ?? ''); ?>">
                                </div>
                                <div class="mt-3" id="beschreibung_sonstige_wrap" style="display: <?php echo $show_beschreibung ? 'block' : 'none'; ?>;">
                                    <label for="beschreibung_sonstige" class="form-label">Beschreibung</label>
                                    <input type="text" class="form-control" id="beschreibung_sonstige" name="beschreibung" list="beschreibung_sonstige_list" placeholder="Aus Liste wählen oder Freitext eingeben" value="<?php echo htmlspecialchars($draft['beschreibung'] ?? ''); ?>">
                                    <datalist id="beschreibung_sonstige_list">
                                        <?php foreach ($beschreibung_optionen_sonstiges as $opt): ?>
                                        <option value="<?php echo htmlspecialchars($opt); ?>">
                                        <?php endforeach; ?>
                                    </datalist>
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
                            <?php if (!$is_einsatz && isset($dienst)):
                                $dienst_typ = $dienst['typ'] ?? '';
                                $is_jhv_sonstiges_dienst = ($dienst_typ === 'sonstiges');
                                $dienstplan_themen = [];
                                $einheit_where_a = $einheit_id > 0 ? " AND (a.einheit_id = " . (int)$einheit_id . " OR a.einheit_id IS NULL)" : "";
                                try {
                                    if ($einheit_id > 0) {
                                        $stmt = $db->prepare("SELECT DISTINCT d.bezeichnung FROM dienstplan d WHERE d.einheit_id = ? OR d.einheit_id IS NULL ORDER BY d.bezeichnung");
                                        $stmt->execute([$einheit_id]);
                                    } else {
                                        $stmt = $db->query("SELECT DISTINCT bezeichnung FROM dienstplan ORDER BY bezeichnung");
                                    }
                                    $dienstplan_themen = $stmt->fetchAll(PDO::FETCH_COLUMN);
                                } catch (Exception $e) { /* ignore */ }
                                $thema_dienst = trim((string)($draft['thema'] ?? $dienst['bezeichnung'] ?? ''));
                            ?>
                            <?php if ($is_jhv_sonstiges_dienst): 
                                $beschreibung_optionen_dienst = [];
                                try {
                                    $von_b = date('Y-m-d', strtotime('-5 years'));
                                    $bis_b = date('Y-m-d', strtotime('+1 day'));
                                    $typ_cond_sonst = "((a.typ = 'dienst' AND d.typ IN ('sonstiges', 'jahreshauptversammlung')) OR (a.typ = 'manuell' AND (a.bezeichnung IN ('Sonstiges', 'Jahreshauptversammlung') OR (a.custom_data IS NOT NULL AND JSON_UNQUOTE(JSON_EXTRACT(a.custom_data, '$.typ_sonstige')) IN ('sonstiges', 'jahreshauptversammlung')))))";
                                    $stmt = $db->prepare("SELECT DISTINCT a.bezeichnung AS b FROM anwesenheitslisten a LEFT JOIN dienstplan d ON d.id = a.dienstplan_id WHERE a.datum BETWEEN ? AND ? AND " . $typ_cond_sonst . $einheit_where_a . " AND a.bezeichnung IS NOT NULL AND TRIM(a.bezeichnung) != '' ORDER BY b");
                                    $stmt->execute([$von_b, $bis_b]);
                                    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                                        $b = trim($r['b'] ?? '');
                                        if ($b !== '' && !in_array($b, $beschreibung_optionen_dienst)) $beschreibung_optionen_dienst[] = $b;
                                    }
                                    $stmt2 = $db->prepare("SELECT DISTINCT TRIM(JSON_UNQUOTE(JSON_EXTRACT(a.custom_data, '$.beschreibung'))) AS b FROM anwesenheitslisten a LEFT JOIN dienstplan d ON d.id = a.dienstplan_id WHERE a.datum BETWEEN ? AND ? AND " . $typ_cond_sonst . $einheit_where_a . " AND a.custom_data IS NOT NULL AND JSON_EXTRACT(a.custom_data, '$.beschreibung') IS NOT NULL AND TRIM(JSON_UNQUOTE(JSON_EXTRACT(a.custom_data, '$.beschreibung'))) != '' ORDER BY b");
                                    $stmt2->execute([$von_b, $bis_b]);
                                    while ($r = $stmt2->fetch(PDO::FETCH_ASSOC)) {
                                        $b = trim($r['b'] ?? '');
                                        if ($b !== '' && !in_array($b, $beschreibung_optionen_dienst)) $beschreibung_optionen_dienst[] = $b;
                                    }
                                } catch (Exception $e) { /* ignore */ }
                            ?>
                            <div class="mb-4">
                                <label for="beschreibung_dienst" class="form-label">Beschreibung</label>
                                <input type="text" class="form-control" id="beschreibung_dienst" name="beschreibung" list="beschreibung_dienst_list" placeholder="Aus Liste wählen oder Freitext eingeben" value="<?php echo htmlspecialchars($draft['beschreibung'] ?? ''); ?>">
                                <datalist id="beschreibung_dienst_list">
                                    <?php foreach ($beschreibung_optionen_dienst as $opt): ?>
                                    <option value="<?php echo htmlspecialchars($opt); ?>">
                                    <?php endforeach; ?>
                                </datalist>
                            </div>
                            <?php else: ?>
                            <div class="mb-4">
                                <label for="thema_dienst" class="form-label">Thema</label>
                                <select class="form-select" id="thema_dienst" name="thema">
                                    <option value="">— Bitte wählen oder neues Thema eingeben —</option>
                                    <?php foreach ($dienstplan_themen as $t): ?>
                                        <option value="<?php echo htmlspecialchars($t); ?>" <?php echo $thema_dienst === $t ? 'selected' : ''; ?>><?php echo htmlspecialchars($t); ?></option>
                                    <?php endforeach; ?>
                                    <option value="__neu__" <?php echo !in_array($thema_dienst, $dienstplan_themen) && $thema_dienst !== '' ? 'selected' : ''; ?>>— Neues Thema eingeben —</option>
                                </select>
                                <div class="mt-2" id="thema_dienst_neu_wrap" style="display: <?php echo !in_array($thema_dienst, $dienstplan_themen) && $thema_dienst !== '' ? 'block' : 'none'; ?>;">
                                    <input type="text" class="form-control" id="thema_dienst_neu" name="thema_neu" placeholder="Neues Thema" value="<?php echo !in_array($thema_dienst, $dienstplan_themen) ? htmlspecialchars($thema_dienst) : ''; ?>">
                                </div>
                            </div>
                            <?php endif; ?>
                            <?php endif; ?>
                            <div class="mb-4">
                                <label class="form-label">Berichtersteller <span class="text-danger">*</span></label>
                                <div class="position-relative">
                                    <input type="text" class="form-control" id="berichtersteller_display" name="berichtersteller_display" placeholder="Buchstaben eingeben zum Filtern der Mitglieder" autocomplete="off" value="<?php echo htmlspecialchars($berichtersteller_display); ?>">
                                    <input type="hidden" name="berichtersteller" id="berichtersteller" value="<?php echo htmlspecialchars($berichtersteller_val ?? ''); ?>">
                                    <div class="list-group position-absolute w-100 mt-1 shadow" id="berichtersteller_suggestions" style="z-index: 1050; max-height: 200px; overflow-y: auto; display: none;"></div>
                                </div>
                                <small class="text-muted">Wird als Vorbelegung für „Aufgenommen durch“ bei Mängeln verwendet</small>
                            </div>
                            <?php
                            $is_uebungsdienst_display = ($is_einsatz && trim($draft['bezeichnung_sonstige'] ?? '') === 'Übungsdienst') || (!$is_einsatz && isset($dienst) && ($dienst['typ'] ?? '') === 'uebungsdienst');
                            $is_jhv_sonstiges_display = ($is_einsatz && in_array(trim($draft['bezeichnung_sonstige'] ?? ''), ['Sonstiges'], true)) || (!$is_einsatz && isset($dienst) && ($dienst['typ'] ?? '') === 'sonstiges');
                            $uebungsdienst_hide_ids = ['alarmierung_durch', 'eigentuemer', 'geschaedigter', 'kostenpflichtiger_einsatz', 'personenschaeden', 'brandwache'];
                            ?>
                            <p class="form-label mb-2">Personal und Fahrzeuge erfassen:</p>
                            <?php
                            $gesamt_staerke = get_besatzungsstaerke($draft['members'] ?? [], $db);
                            ?>
                            <div class="row g-3 mb-4">
                                <div class="col-md-6">
                                    <a href="<?php echo htmlspecialchars($personal_url); ?>" class="btn btn-primary w-100 anwesenheits-option-btn anwesenheits-save-before-nav text-white">
                                        <i class="fas fa-users fa-2x mb-2"></i><span>Personal</span>
                                        <small class="d-block mt-1 opacity-90">Anwesende auswählen, Fahrzeug zuordnen</small>
                                        <?php if (!empty($draft['members'])): ?>
                                        <span class="badge bg-light text-dark mt-1"><?php echo htmlspecialchars($gesamt_staerke); ?></span>
                                        <?php endif; ?>
                                    </a>
                                </div>
                                <div class="col-md-6">
                                    <a href="<?php echo htmlspecialchars($fahrzeuge_url); ?>" class="btn btn-success w-100 anwesenheits-option-btn anwesenheits-save-before-nav text-white">
                                        <i class="fas fa-truck fa-2x mb-2"></i><span>Fahrzeuge</span>
                                        <small class="d-block mt-1 opacity-90">Eingesetzte Fahrzeuge, Maschinist & Einheitsführer</small>
                                    </a>
                                </div>
                                <div class="col-md-6">
                                    <a href="<?php echo htmlspecialchars($geraete_url); ?>" class="btn btn-info w-100 anwesenheits-option-btn anwesenheits-save-before-nav text-white">
                                        <i class="fas fa-tools fa-2x mb-2"></i><span>Geräte</span>
                                        <small class="d-block mt-1 opacity-90">Eingesetzte Gerätschaften pro Fahrzeug</small>
                                    </a>
                                </div>
                                <div class="col-md-6">
                                    <a href="<?php echo htmlspecialchars($maengel_url); ?>" class="btn btn-warning w-100 anwesenheits-option-btn anwesenheits-save-before-nav text-dark">
                                        <i class="fas fa-exclamation-triangle fa-2x mb-2"></i><span>Mängel</span>
                                        <small class="d-block mt-1 opacity-90">Mängel festhalten (werden als Mängelberichte gespeichert)</small>
                                        <?php if (!empty($draft['maengel'])): ?>
                                        <span class="badge bg-dark mt-1"><?php echo count($draft['maengel']); ?> erfasst</span>
                                        <?php endif; ?>
                                    </a>
                                </div>
                            </div>
                            <?php foreach ($anwesenheitsliste_felder as $f):
                                if (empty($f['visible']) || ($f['type'] ?? '') === 'time') continue;
                                $id = $f['id'] ?? '';
                                if (($is_uebungsdienst_display || $is_jhv_sonstiges_display) && in_array($id, $uebungsdienst_hide_ids)) continue;
                                $label = $f['label'] ?? $id;
                                $type = $f['type'] ?? 'text';
                                $opts = $f['options'] ?? [];
                                $val = _anwesenheitsliste_draft_value($id, $draft);
                                if ($id === 'uhrzeit_von' || $id === 'uhrzeit_bis') continue;
                                if ($id === 'einsatzstichwort' && $is_einsatz) continue;
                                $feld_uebungsdienst_hide = in_array($id, $uebungsdienst_hide_ids);
                                $feld_einsatzleiter = ($type === 'einsatzleiter');
                                $div_style = '';
                                if ($is_einsatz && $feld_uebungsdienst_hide && ($is_uebungsdienst_display || $is_jhv_sonstiges_display)) $div_style = 'display:none';
                                if ($is_einsatz && $feld_einsatzleiter) $div_style = '';
                            ?>
                            <div class="mb-3<?php echo $type === 'textarea' ? ' mb-4' : ''; ?> feld-uebungsdienst-toggle" data-feld="<?php echo htmlspecialchars($id); ?>" data-hide-uebungsdienst="<?php echo $feld_uebungsdienst_hide ? '1' : '0'; ?>" data-einsatzleiter="<?php echo $feld_einsatzleiter ? '1' : '0'; ?>"<?php echo $div_style !== '' ? ' style="' . $div_style . '"' : ''; ?>>
                                <?php if ($type === 'einsatzleiter'): ?>
                                <?php if (!$is_uebungsdienst_display || $is_einsatz): ?>
                                <div id="einsatzleiter_wrap" style="<?php echo $is_einsatz && ($is_uebungsdienst_display || $is_jhv_sonstiges_display) ? 'display:none' : ''; ?>">
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
                                </div>
                                <?php endif; ?>
                                <?php if ($is_uebungsdienst_display || $is_einsatz): ?>
                                <div id="uebungsleiter_wrap" class="feld-uebungsdienst-toggle" data-einsatzleiter="1" style="<?php echo ($is_einsatz && !$is_uebungsdienst_display && !$is_jhv_sonstiges_display) ? 'display:none' : ''; ?>">
                                <label class="form-label">Übungsleiter <span id="uebungsleiter_count" class="badge bg-secondary ms-1">0 ausgewählt</span></label>
                                <div class="uebungsleiter-list border rounded p-2" style="max-height: 220px; overflow-y: auto; display: flex; flex-direction: column; gap: 0.35rem;">
                                    <?php $uebungs_ids = $draft['uebungsleiter_member_ids'] ?? []; if (!is_array($uebungs_ids)) $uebungs_ids = []; ?>
                                    <?php foreach ($members_for_einsatzleiter as $m):
                                        $checked = in_array((int)$m['id'], array_map('intval', $uebungs_ids)) ? ' checked' : '';
                                        $sel_cls = $checked ? 'uebungsleiter-item-selected' : '';
                                    ?>
                                    <div class="uebungsleiter-item <?php echo $sel_cls; ?>" data-member-id="<?php echo (int)$m['id']; ?>" role="button" tabindex="0" style="cursor:pointer;padding:0.5rem 0.75rem;border-radius:6px;border:2px solid #e9ecef;transition:all 0.2s">
                                        <input type="checkbox" name="uebungsleiter[]" value="<?php echo (int)$m['id']; ?>" style="display:none"<?php echo $checked; ?>>
                                        <?php echo htmlspecialchars($m['last_name'] . ', ' . $m['first_name']); ?>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <small class="text-muted">Klicken zum Auswählen/Abwählen</small>
                                </div>
                                <?php endif; ?>
                                <?php elseif ($type === 'einsatzstelle'): ?>
                                <label for="einsatzstelle" class="form-label"><?php echo htmlspecialchars($label); ?></label>
                                <div class="position-relative">
                                    <div class="input-group">
                                        <input type="text" class="form-control" id="einsatzstelle" name="einsatzstelle" placeholder="Adresse eingeben (Autovervollständigung)" value="<?php echo htmlspecialchars($val); ?>" autocomplete="off">
                                        <?php if ($geraetehaus_adresse !== ''): ?>
                                        <button type="button" class="btn btn-outline-secondary" id="btn_geraetehaus" title="Adresse des Gerätehauses eintragen" data-address="<?php echo htmlspecialchars($geraetehaus_adresse); ?>">Gerätehaus</button>
                                        <?php endif; ?>
                                    </div>
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
                            <div class="mb-4 p-3 border rounded bg-light">
                                <label class="form-label fw-semibold"><i class="fas fa-paperclip"></i> Anhänge zur Anwesenheitsliste (optional)</label>
                                <p class="text-muted small mb-2">Fotos und Dokumente (PDF) werden direkt nach Auswahl oder Aufnahme gespeichert und bleiben beim Wechsel zu Personal, Fahrzeugen usw. erhalten. Sie erscheinen im erzeugten PDF hinter dem Bericht. Erlaubt: JPG, PNG, WebP, GIF, PDF (max. ca. 12&nbsp;MB pro Datei, insgesamt bis <?php echo (int)BERICHT_ANHAENGE_MAX_FILES; ?> Dateien).</p>
                                <?php $al_anhaenge_temp = $draft['anhaenge_temp'] ?? []; if (!is_array($al_anhaenge_temp)) { $al_anhaenge_temp = []; } ?>
                                <ul class="list-unstyled mb-2 border rounded p-2 bg-white" id="al_anhaenge_temp_list" style="min-height:0">
                                    <?php foreach ($al_anhaenge_temp as $al_att):
                                        $al_p = isset($al_att['path']) ? (string)$al_att['path'] : '';
                                        $al_o = isset($al_att['orig']) ? (string)$al_att['orig'] : 'Anhang';
                                        if ($al_p === '') { continue; }
                                        $al_href = 'uploads/' . htmlspecialchars(str_replace(['..', '\\'], '', $al_p), ENT_QUOTES, 'UTF-8');
                                    ?>
                                    <li class="d-flex flex-wrap align-items-center gap-2 mb-1 al-anhaenge-temp-row" data-path="<?php echo htmlspecialchars($al_p, ENT_QUOTES, 'UTF-8'); ?>">
                                        <a href="<?php echo $al_href; ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars($al_o, ENT_QUOTES, 'UTF-8'); ?></a>
                                        <button type="button" class="btn btn-sm btn-outline-danger al-anhaenge-temp-remove" data-path="<?php echo htmlspecialchars($al_p, ENT_QUOTES, 'UTF-8'); ?>">Entfernen</button>
                                    </li>
                                    <?php endforeach; ?>
                                </ul>
                                <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                                    <input type="file" class="form-control form-control-sm" style="max-width:280px" name="anwesenheitsliste_anhaenge[]" id="anwesenheitsliste_anhaenge_files" multiple accept="image/jpeg,image/png,image/webp,image/gif,application/pdf,.pdf">
                                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btnKameraAnhangAl" title="Kamera (mobil)"><i class="fas fa-camera"></i> Foto aufnehmen</button>
                                    <span class="small text-muted d-none" id="al_anhaenge_upload_status" aria-live="polite"></span>
                                </div>
                                <input type="file" class="d-none" id="anwesenheitsliste_anhaenge_camera" accept="image/*" capture="environment">
                                <small class="text-muted">Unterwegs: „Foto aufnehmen“ nutzen oder „Dateien auswählen“ – bei manchen Geräten bietet die Dateiauswahl ebenfalls die Kamera.</small>
                            </div>
                            <div class="d-flex flex-wrap gap-2">
                                <button type="button" class="btn btn-success" id="btnSaveAnwesenheit"><i class="fas fa-save"></i> Anwesenheitsliste speichern</button>
                                <a href="<?php echo $return_formularcenter ? 'admin/formularcenter.php?tab=submissions' : 'anwesenheitsliste.php' . ($einheit_id > 0 ? '?einheit_id=' . (int)$einheit_id : ''); ?>" class="btn btn-secondary"><?php echo $return_formularcenter ? 'Zurück zum Formularcenter' : 'Zurück zur Auswahl'; ?></a>
                            </div>
                        </form>
    <!-- Modal: Bericht absenden bestätigen -->
    <div class="modal fade" id="saveConfirmModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Bericht absenden</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Möchten Sie den Bericht wirklich absenden?</p>
                    <div class="mb-3 p-2 bg-light rounded">
                        <label class="form-label fw-bold mb-2">Uhrzeiten korrekt?</label>
                        <p class="text-muted small mb-2">Bitte prüfen Sie die Zeiten und passen Sie diese bei Bedarf an:</p>
                        <div class="row g-2">
                            <div class="col-6">
                                <label class="form-label small mb-0">Uhrzeit von</label>
                                <input type="time" class="form-control form-control-sm" id="modal_uhrzeit_von" value="<?php echo htmlspecialchars(strlen($draft['uhrzeit_von'] ?? '') >= 5 ? substr($draft['uhrzeit_von'], 0, 5) : ($draft['uhrzeit_von'] ?? '')); ?>">
                            </div>
                            <div class="col-6">
                                <label class="form-label small mb-0">Uhrzeit bis</label>
                                <input type="time" class="form-control form-control-sm" id="modal_uhrzeit_bis" value="<?php echo htmlspecialchars(strlen($draft['uhrzeit_bis'] ?? '') >= 5 ? substr($draft['uhrzeit_bis'], 0, 5) : ($draft['uhrzeit_bis'] ?? date('H:i'))); ?>">
                            </div>
                        </div>
                        <div class="mt-3 pt-2 border-top">
                            <label class="form-label small mb-2">Nach Speichern drucken:</label>
                            <?php
                            $print_al_default = (($unit_settings['anwesenheitsliste_print_after_save_default'] ?? '1') === '1');
                            $print_mb_default = (($unit_settings['maengelbericht_print_after_save_default'] ?? '1') === '1');
                            $print_gwm_default = (($unit_settings['geraetewartmitteilung_print_after_save_default'] ?? '1') === '1');
                            ?>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="cbPrintAfterSave" <?php echo $print_al_default ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="cbPrintAfterSave">Anwesenheitsliste</label>
                            </div>
                            <?php if (!empty($draft['maengel']) && is_array($draft['maengel'])): ?>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="cbPrintMaengelberichtAfterSave" <?php echo $print_mb_default ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="cbPrintMaengelberichtAfterSave">Mängelbericht(e)</label>
                            </div>
                            <?php endif; ?>
                            <?php $has_vehicles_for_gwm = !empty($draft['vehicles']) || !empty(array_filter($draft['member_vehicle'] ?? [])); if ($has_vehicles_for_gwm): ?>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="cbPrintGeraetewartmitteilungAfterSave" <?php echo $print_gwm_default ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="cbPrintGeraetewartmitteilungAfterSave">Gerätewartmitteilung</label>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="button" class="btn btn-success" id="btnConfirmSave"><i class="fas fa-check"></i> Ja, absenden</button>
                </div>
            </div>
        </div>
    </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
    <footer class="bg-light mt-5 py-4"><div class="container text-center"><p class="text-muted mb-0">&copy; 2025 Boedes Feuerwehr App</p></div></footer>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    (function(){
        function appendAlRows(added) {
            var ul = document.getElementById('al_anhaenge_temp_list');
            if (!ul || !added || !added.length) return;
            added.forEach(function(it) {
                var path = it.path || '';
                var orig = it.orig || 'Anhang';
                if (!path) return;
                var li = document.createElement('li');
                li.className = 'd-flex flex-wrap align-items-center gap-2 mb-1 al-anhaenge-temp-row';
                li.setAttribute('data-path', path);
                var a = document.createElement('a');
                a.href = 'uploads/' + path.replace(/\.\./g, '').replace(/\\/g, '');
                a.target = '_blank';
                a.rel = 'noopener';
                a.textContent = orig;
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'btn btn-sm btn-outline-danger al-anhaenge-temp-remove';
                btn.setAttribute('data-path', path);
                btn.textContent = 'Entfernen';
                li.appendChild(a);
                li.appendChild(btn);
                ul.appendChild(li);
            });
        }
        function uploadAlFiles(input) {
            if (!input || !input.files || !input.files.length) return;
            var st = document.getElementById('al_anhaenge_upload_status');
            if (st) { st.classList.remove('d-none'); st.textContent = 'Wird hochgeladen…'; }
            var fd = new FormData();
            var i;
            for (i = 0; i < input.files.length; i++) {
                fd.append('anwesenheitsliste_anhaenge[]', input.files[i]);
            }
            fetch('api/upload-anwesenheit-anhang.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (st) { st.textContent = ''; st.classList.add('d-none'); }
                    if (!data.success) {
                        window.alert(data.message || 'Upload fehlgeschlagen');
                        return;
                    }
                    appendAlRows(data.added || []);
                    input.value = '';
                })
                .catch(function() {
                    if (st) { st.textContent = ''; st.classList.add('d-none'); }
                    window.alert('Netzwerkfehler beim Upload');
                    input.value = '';
                });
        }
        var btnCamAl = document.getElementById('btnKameraAnhangAl');
        var inpCamAl = document.getElementById('anwesenheitsliste_anhaenge_camera');
        var inpFilesAl = document.getElementById('anwesenheitsliste_anhaenge_files');
        var ulAl = document.getElementById('al_anhaenge_temp_list');
        if (ulAl) {
            ulAl.addEventListener('click', function(e) {
                var btn = e.target.closest('.al-anhaenge-temp-remove');
                if (!btn) return;
                var path = btn.getAttribute('data-path') || '';
                if (!path) return;
                var fd = new FormData();
                fd.append('path', path);
                fetch('api/delete-anwesenheit-anhang.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (!data.success) {
                            window.alert(data.message || 'Entfernen fehlgeschlagen');
                            return;
                        }
                        var row = btn.closest('.al-anhaenge-temp-row');
                        if (row) row.remove();
                    })
                    .catch(function() { window.alert('Netzwerkfehler'); });
            });
        }
        if (btnCamAl && inpCamAl) {
            btnCamAl.addEventListener('click', function() { inpCamAl.click(); });
        }
        if (inpCamAl) {
            inpCamAl.addEventListener('change', function() { uploadAlFiles(this); });
        }
        if (inpFilesAl) {
            inpFilesAl.addEventListener('change', function() { uploadAlFiles(this); });
        }
    })();
    </script>
    <script>
    (function(){
        var btnSave = document.getElementById('btnSaveAnwesenheit');
        var modal = document.getElementById('saveConfirmModal');
        var form = document.getElementById('mainForm');
        var validationEl = document.getElementById('validationError');
        var isEinsatz = <?php echo $is_einsatz ? 'true' : 'false'; ?>;
        var dienstTyp = <?php echo isset($dienst) ? json_encode($dienst['typ'] ?? '') : '""'; ?>;
        function validateForm() {
            var fehler = [];
            var uhrzeitVon = (document.querySelector('[name="uhrzeit_von"]') || form.querySelector('[name="uhrzeit_von"]') || {}).value || '';
            var uhrzeitBis = (document.querySelector('[name="uhrzeit_bis"]') || form.querySelector('[name="uhrzeit_bis"]') || {}).value || '';
            if (!uhrzeitVon.trim()) fehler.push('Uhrzeit von');
            if (!uhrzeitBis.trim()) fehler.push('Uhrzeit bis');
            var typSonstige = (form.querySelector('[name="typ_sonstige"]') || {}).value || '';
            var isJhvSonstiges = (isEinsatz && typSonstige === 'sonstiges') || (!isEinsatz && dienstTyp === 'sonstiges');
            var isUebungsdienst = (isEinsatz && typSonstige === 'uebungsdienst') || (!isEinsatz && dienstTyp === 'uebungsdienst');
            var typSave = isEinsatz ? (typSonstige === 'einsatz' ? 'einsatz' : 'manuell') : 'dienst';
            if (isJhvSonstiges) {
            } else if (isUebungsdienst) {
                var themaSel = form.querySelector('[name="thema"]');
                var themaNeu = form.querySelector('[name="thema_neu"]');
                var themaVal = themaSel ? themaSel.value : '';
                var thema = (themaVal === '__neu__' && themaNeu) ? (themaNeu.value || '').trim() : (themaVal || '').trim();
                if (!thema) fehler.push('Thema');
                var uebChecked = form.querySelectorAll('.uebungsleiter-item-selected').length;
                if (uebChecked === 0) fehler.push('Übungsleiter');
            } else if (typSave === 'einsatz') {
                var einsatzstichwort = (form.querySelector('[name="einsatzstichwort"]') || {}).value || '';
                if (!einsatzstichwort.trim()) fehler.push('Einsatzstichwort');
                var einsatzstelle = (form.querySelector('[name="einsatzstelle"]') || {}).value || '';
                if (!einsatzstelle.trim()) fehler.push('Einsatzstelle');
                var einsatzleiter = (form.querySelector('[name="einsatzleiter"]') || {}).value || '';
                var einsatzleiterFreitext = (form.querySelector('[name="einsatzleiter_freitext"]') || {}).value || '';
                var hasEl = (einsatzleiter && einsatzleiter !== '') || (einsatzleiterFreitext && einsatzleiterFreitext.trim() !== '');
                if (!hasEl) fehler.push('Einsatzleiter');
            }
            var berichterstellerDisplay = (form.querySelector('[name="berichtersteller_display"]') || {}).value || '';
            var berichtersteller = (form.querySelector('[name="berichtersteller"]') || {}).value || '';
            if (!berichterstellerDisplay.trim() && !berichtersteller.trim()) fehler.push('Berichtersteller');
            return fehler;
        }
        if (btnSave && modal && form) {
            btnSave.addEventListener('click', function() {
                var fehler = validateForm();
                if (validationEl) {
                    validationEl.style.display = 'none';
                    validationEl.innerHTML = '';
                }
                if (fehler.length > 0) {
                    if (validationEl) {
                        validationEl.textContent = 'Bitte füllen Sie alle Pflichtfelder aus: ' + fehler.join(', ') + '. Sie können den Bericht später fortsetzen.';
                        validationEl.style.display = 'block';
                        validationEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                    }
                    return;
                }
                var modalVon = document.getElementById('modal_uhrzeit_von');
                var modalBis = document.getElementById('modal_uhrzeit_bis');
                var formVon = document.querySelector('[name="uhrzeit_von"]') || form.querySelector('[name="uhrzeit_von"]');
                var formBis = document.querySelector('[name="uhrzeit_bis"]') || form.querySelector('[name="uhrzeit_bis"]');
                if (modalVon && formVon) modalVon.value = formVon.value || '';
                if (modalBis && formBis) modalBis.value = formBis.value || (modalBis.value || '');
                new bootstrap.Modal(modal).show();
            });
            document.getElementById('btnConfirmSave').addEventListener('click', function() {
                var modalVon = document.getElementById('modal_uhrzeit_von');
                var modalBis = document.getElementById('modal_uhrzeit_bis');
                var formVon = document.querySelector('[name="uhrzeit_von"]') || form.querySelector('[name="uhrzeit_von"]');
                var formBis = document.querySelector('[name="uhrzeit_bis"]') || form.querySelector('[name="uhrzeit_bis"]');
                if (modalVon && formVon) formVon.value = modalVon.value || '';
                if (modalBis && formBis) formBis.value = modalBis.value || '';
                var cbPrint = document.getElementById('cbPrintAfterSave');
                var cbMaengel = document.getElementById('cbPrintMaengelberichtAfterSave');
                var cbGwm = document.getElementById('cbPrintGeraetewartmitteilungAfterSave');
                var inpPrint = document.getElementById('print_after_save');
                var inpMaengel = document.getElementById('print_maengelbericht_after_save');
                var inpGwm = document.getElementById('print_geraetewartmitteilung_after_save');
                if (inpPrint) inpPrint.value = (cbPrint && cbPrint.checked) ? '1' : '0';
                if (inpMaengel) inpMaengel.value = (cbMaengel && cbMaengel.checked) ? '1' : '0';
                if (inpGwm) inpGwm.value = (cbGwm && cbGwm.checked) ? '1' : '0';
                form.submit();
            });
        }
    })();
    window.addEventListener('beforeunload', function() {
        var form = document.getElementById('mainForm');
        if (form) {
            var datumInput = document.getElementById('datum_aendern');
            var datumHidden = form.querySelector('input[name="datum"]');
            if (datumInput && datumHidden) datumHidden.value = datumInput.value;
            var fd = new FormData(form);
            navigator.sendBeacon('api/save-anwesenheit-draft.php', fd);
        } else {
            navigator.sendBeacon('api/save-anwesenheit-draft.php', '');
        }
    });
    (function(){
        var datumInput = document.getElementById('datum_aendern');
        var datumHidden = document.getElementById('datum_for_main');
        if (datumInput && datumHidden) {
            datumInput.addEventListener('change', function() { datumHidden.value = this.value; });
            datumInput.addEventListener('blur', function() { datumHidden.value = this.value; });
        }
    })();
    document.querySelectorAll('.anwesenheits-save-before-nav').forEach(function(link) {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            var url = this.getAttribute('href');
            var form = document.getElementById('mainForm');
            if (!form || !url) { window.location.href = url || '#'; return; }
            var datumInput = document.getElementById('datum_aendern');
            var datumHidden = form.querySelector('input[name="datum"]');
            if (datumInput && datumHidden) datumHidden.value = datumInput.value;
            var datumVal = datumHidden ? datumHidden.value : '';
            if (datumVal && /^\d{4}-\d{2}-\d{2}$/.test(datumVal)) {
                try {
                    var u = new URL(url, window.location.origin);
                    u.searchParams.set('datum', datumVal);
                    url = u.pathname + u.search;
                } catch(z) {}
            }
            var typSel = form.querySelector('[name="typ_sonstige"]');
            var uebItems = form.querySelectorAll('.uebungsleiter-item-selected input[type="checkbox"][name="uebungsleiter[]"]');
            var params = [];
            var typVal = typSel && typSel.value ? typSel.value : '';
            if (typVal) params.push('typ_sonstige=' + encodeURIComponent(typVal));
            var uebIds = [];
            uebItems.forEach(function(cb){ if(cb.value) { params.push('uebungsleiter[]=' + encodeURIComponent(cb.value)); uebIds.push(cb.value); } });
            if (params.length) url += (url.indexOf('?') >= 0 ? '&' : '?') + params.join('&');
            try {
                sessionStorage.setItem('anwesenheit_typ_sonstige', typVal || '');
                sessionStorage.setItem('anwesenheit_uebungsleiter', JSON.stringify(uebIds));
            } catch(z){}
            var fd = new FormData(form);
            fetch('api/save-anwesenheit-draft.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function() { window.location.href = url; })
                .catch(function() { window.location.href = url; });
        });
    });
    </script>
    <script>
        (function(){var input=document.getElementById('einsatzstelle');var suggestionsEl=document.getElementById('einsatzstelle_suggestions');if(!input||!suggestionsEl)return;var debounceTimer;input.addEventListener('input',function(){clearTimeout(debounceTimer);var q=input.value.trim();if(q.length<3){suggestionsEl.style.display='none';suggestionsEl.innerHTML='';return;}debounceTimer=setTimeout(function(){fetch('https://nominatim.openstreetmap.org/search?format=json&q='+encodeURIComponent(q)+'&countrycodes=de,at,ch&limit=5&addressdetails=1',{headers:{'Accept':'application/json'}}).then(function(r){return r.json();}).then(function(data){suggestionsEl.innerHTML='';if(!data||data.length===0){suggestionsEl.style.display='none';return;}data.forEach(function(item){var addr=item.address||{};var strasse=addr.road||'';var hausnummer=addr.house_number||'';var plz=addr.postcode||'';var ort=addr.city||addr.town||addr.village||addr.municipality||'';var zeile1=[strasse,hausnummer].filter(Boolean).join(' ');var zeile2=[plz,ort].filter(Boolean).join(' ');var display=[zeile1,zeile2].filter(Boolean).join(', ');if(!display)display=item.display_name||item.name||'';var a=document.createElement('button');a.type='button';a.className='list-group-item list-group-item-action list-group-item-light text-start';a.textContent=display;a.addEventListener('click',function(){input.value=display;suggestionsEl.style.display='none';suggestionsEl.innerHTML='';});suggestionsEl.appendChild(a);});suggestionsEl.style.display='block';}).catch(function(){suggestionsEl.style.display='none';});},400);});input.addEventListener('blur',function(){setTimeout(function(){suggestionsEl.style.display='none';},200);});document.addEventListener('click',function(e){if(!input.contains(e.target)&&!suggestionsEl.contains(e.target))suggestionsEl.style.display='none';});})();
    </script>
    <script>
    (function(){var btn=document.getElementById('btn_geraetehaus');var input=document.getElementById('einsatzstelle');if(!btn||!input)return;btn.addEventListener('click',function(){var addr=btn.getAttribute('data-address')||'';if(addr)input.value=addr;});
    })();
    </script>
    <script>var el=document.getElementById('einsatzleiter');if(el)el.addEventListener('change',function(){var w=document.getElementById('einsatzleiter_freitext_wrap');if(w)w.style.display=this.value==='__freitext__'?'block':'none';});</script>
    <script>
    (function(){
        var membersData=<?php echo json_encode(array_map(function($m){return['id'=>(int)$m['id'],'label'=>trim($m['last_name'].', '.$m['first_name'])];},$members_all)); ?>;
        var display=document.getElementById('berichtersteller_display');
        var hidden=document.getElementById('berichtersteller');
        var suggestions=document.getElementById('berichtersteller_suggestions');
        if(!display||!suggestions)return;
        function filterMembers(q){q=(q||'').toLowerCase().trim();if(q==='')return membersData;return membersData.filter(function(m){return(m.label||'').toLowerCase().indexOf(q)>=0;});}
        function render(items){suggestions.innerHTML='';items.forEach(function(item){var btn=document.createElement('button');btn.type='button';btn.className='list-group-item list-group-item-action list-group-item-light text-start';btn.textContent=item.label;btn.dataset.id=item.id;btn.dataset.label=item.label;btn.addEventListener('click',function(){display.value=this.dataset.label;if(hidden)hidden.value=this.dataset.id;suggestions.style.display='none';});suggestions.appendChild(btn);});suggestions.style.display=items.length>0?'block':'none';}
        display.addEventListener('input',function(){if(hidden)hidden.value='';render(filterMembers(display.value.trim()));});
        display.addEventListener('focus',function(){render(filterMembers(display.value.trim()));});
        display.addEventListener('blur',function(){setTimeout(function(){suggestions.style.display='none';},200);});
        document.addEventListener('click',function(e){if(!display.contains(e.target)&&!suggestions.contains(e.target))suggestions.style.display='none';});
        document.getElementById('mainForm').addEventListener('submit',function(){var idVal=hidden?hidden.value.trim():'';if(!idVal&&display.value.trim())hidden.value=display.value.trim();});
    })();
    </script>
    <style>.uebungsleiter-item:hover{background:#f8f9fa}.uebungsleiter-item-selected{background:#0d6efd!important;color:#fff!important;border-color:#0d6efd!important}</style>
    <script>
    document.querySelectorAll('.uebungsleiter-item').forEach(function(el){
        el.addEventListener('click',function(){
            var cb=this.querySelector('input[type=checkbox]');
            cb.checked=!cb.checked;
            this.classList.toggle('uebungsleiter-item-selected',cb.checked);
            var cnt=document.querySelectorAll('.uebungsleiter-item-selected').length;
            var badge=document.getElementById('uebungsleiter_count');
            if(badge){badge.textContent=cnt+' ausgewählt';badge.className='badge ms-1 '+(cnt>0?'bg-primary':'bg-secondary');}
        });
    });
    (function(){var cnt=document.querySelectorAll('.uebungsleiter-item-selected').length;var badge=document.getElementById('uebungsleiter_count');if(badge){badge.textContent=cnt+' ausgewählt';badge.className='badge ms-1 '+(cnt>0?'bg-primary':'bg-secondary');}})();
    </script>
    <?php if ($is_einsatz): ?>
    <script>
    (function(){
        function applyUrlParams(){
            var params=new URLSearchParams(window.location.search);
            var ts=params.get('typ_sonstige');
            var ueb=params.getAll('uebungsleiter[]');
            if(ts!=='uebungsdienst'&&ts!=='sonstiges')return;
            var sel=document.getElementById('typ_sonstige');
            if(sel&&ts==='uebungsdienst'&&sel.value!=='uebungsdienst'){sel.value='uebungsdienst';}
            if(sel&&ts==='sonstiges'&&sel.value!=='sonstiges'){sel.value='sonstiges';}
            if(ueb.length>0){
                document.querySelectorAll('.uebungsleiter-item').forEach(function(el){
                    var cb=el.querySelector('input[name="uebungsleiter[]"]');
                    if(cb&&ueb.indexOf(cb.value)>=0){cb.checked=true;el.classList.add('uebungsleiter-item-selected');}
                });
                var badge=document.getElementById('uebungsleiter_count');
                if(badge){badge.textContent=ueb.length+' ausgewählt';badge.className='badge ms-1 bg-primary';}
            }
        }
        if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',applyUrlParams);}else{applyUrlParams();}
    })();
    function syncTypSonstigeVisibility(){
        var sel=document.getElementById('typ_sonstige');
        if(!sel)return;
        var params=new URLSearchParams(window.location.search);
        var tsUrl=params.get('typ_sonstige');
        if(tsUrl==='uebungsdienst'){sel.value='uebungsdienst';}
        if(tsUrl==='sonstiges'){sel.value='sonstiges';}
        var v=sel.value;
        var w=document.getElementById('typ_sonstige_freitext_wrap');if(w)w.style.display=v==='__custom__'?'block':'none';
        var showEinsatzstichwort=v==='einsatz';
        var showThema=v==='uebungsdienst';
        var showBeschreibung=v==='sonstiges';
        var elEinsatz=document.getElementById('einsatzstichwort_wrap');
        var elThema=document.getElementById('thema_wrap');
        var elBeschr=document.getElementById('beschreibung_sonstige_wrap');
        if(elEinsatz)elEinsatz.style.display=showEinsatzstichwort?'block':'none';
        if(elThema)elThema.style.display=showThema?'block':'none';
        if(elBeschr)elBeschr.style.display=showBeschreibung?'block':'none';
        var isUeb= v==='uebungsdienst'||v==='sonstiges';
        document.querySelectorAll('.feld-uebungsdienst-toggle[data-hide-uebungsdienst="1"]').forEach(function(el){el.style.display=isUeb?'none':'block';});
        var elWrap=document.getElementById('einsatzleiter_wrap');
        var uebWrap=document.getElementById('uebungsleiter_wrap');
        if(elWrap)elWrap.style.display=isUeb?'none':'block';
        if(uebWrap)uebWrap.style.display=isUeb?'block':'none';
    }
    var selTs=document.getElementById('typ_sonstige');
    if(selTs)selTs.addEventListener('change',syncTypSonstigeVisibility);
    document.addEventListener('DOMContentLoaded',function(){syncTypSonstigeVisibility();setTimeout(syncTypSonstigeVisibility,50);});
    if(document.readyState!=='loading')syncTypSonstigeVisibility();
    document.getElementById('thema').addEventListener('change',function(){
        document.getElementById('thema_neu_wrap').style.display=this.value==='__neu__'?'block':'none';
    });
    </script>
    <?php endif; ?>
    <?php if (!$is_einsatz && isset($dienst)): ?>
    <script>
    (function(){
        var sel=document.getElementById('thema_dienst');
        var wrap=document.getElementById('thema_dienst_neu_wrap');
        if(sel&&wrap){sel.addEventListener('change',function(){wrap.style.display=this.value==='__neu__'?'block':'none';});}
    })();
    </script>
    <?php endif; ?>
</body>
</html>
