<?php
/**
 * Anwesenheitsliste – Umfrage-Modus: Schrittweise Erfassung per Fragen.
 * Nur für Einsatz (auswahl=einsatz). Test-Version – alte Funktionsweise bleibt erhalten.
 */
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/dienstplan-typen.php';
require_once __DIR__ . '/includes/anwesenheitsliste-helper.php';

if (!$db) {
    header('Location: anwesenheitsliste.php');
    exit;
}
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$einheit_id = isset($_GET['einheit_id']) ? (int)$_GET['einheit_id'] : (isset($_POST['einheit_id']) ? (int)$_POST['einheit_id'] : 0);
if ($einheit_id <= 0) $einheit_id = isset($_SESSION['current_einheit_id']) ? (int)$_SESSION['current_einheit_id'] : 0;
if ($einheit_id <= 0 && isset($_SESSION['user_id'])) {
    $stmt = $db->prepare("SELECT einheit_id FROM users WHERE id = ?");
    $stmt->execute([(int)$_SESSION['user_id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $einheit_id = $row ? (int)($row['einheit_id'] ?? 0) : 0;
}
if ($einheit_id > 0) $_SESSION['current_einheit_id'] = $einheit_id;
$einheit_param = $einheit_id > 0 ? '&einheit_id=' . (int)$einheit_id : '';

if (!function_exists('is_superadmin') || !is_superadmin()) {
    header('Location: anwesenheitsliste.php' . ($einheit_id > 0 ? '?einheit_id=' . (int)$einheit_id : ''));
    exit;
}

$datum = isset($_GET['datum']) ? trim($_GET['datum']) : (isset($_POST['datum']) ? trim($_POST['datum']) : date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum)) {
    $datum = date('Y-m-d');
}
$auswahl = 'einsatz';
$draft_key = 'anwesenheit_draft';
$neu = isset($_GET['neu']) && $_GET['neu'] === '1';

// Draft initialisieren
if ($neu) {
    unset($_SESSION[$draft_key]);
}
if (!isset($_SESSION[$draft_key]) || $_SESSION[$draft_key]['datum'] !== $datum || $_SESSION[$draft_key]['auswahl'] !== $auswahl) {
    // Aus DB laden falls vorhanden
    $draft_loaded = false;
    if (isset($_SESSION['user_id'])) {
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
        } catch (Exception $e) {}
    }
    if (!$draft_loaded) {
        $_SESSION[$draft_key] = [
            'datum' => $datum,
            'auswahl' => $auswahl,
            'dienstplan_id' => null,
            'typ' => 'einsatz',
            'bezeichnung_sonstige' => 'Einsatz',
            'einsatzstichwort' => '',
            'thema' => '',
            'bemerkung' => '',
            'members' => [],
            'member_vehicle' => [],
            'member_pa' => [],
            'vehicles' => [],
            'vehicle_maschinist' => [],
            'vehicle_einheitsfuehrer' => [],
            'vehicle_equipment' => [],
            'vehicle_equipment_sonstiges' => [],
            'uhrzeit_von' => '',
            'uhrzeit_bis' => date('H:i'),
            'uebungsleiter_member_ids' => [],
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
            'beschreibung' => '',
        ];
    }
}
$draft = &$_SESSION[$draft_key];

// Gerätehaus-Adresse aus einheitsspezifischen Einstellungen laden
$geraetehaus_adresse = '';
try {
    require_once __DIR__ . '/includes/einheit-settings-helper.php';
    $unit_settings = load_settings_for_einheit($db, $einheit_id > 0 ? $einheit_id : null);
    $geraetehaus_adresse = trim((string)($unit_settings['geraetehaus_adresse'] ?? ''));
} catch (Exception $e) {}

// Klassifizierung-Optionen
$klassifizierung_opts = ['Grossbrand', 'Mittelbrand', 'Kleinbrand', 'Gelöschtes Feuer', 'Gefahrenmeldeanlage', 'Menschen in Notlage', 'Tiere in Notlage', 'Verkehrsunfall', 'Techn. Hilfeleistung', 'Wasserrettung', 'CBRN-Einsatz', 'Unterstützung RD', 'Sonstiger Einsatz', 'Fehlalarm', 'Böswill. Alarm'];
$alarmierung_opts = ['Telefon', 'DME Löschzug', 'DME Kleinhilfe', 'Sirene'];
$typen_map = get_dienstplan_typen_auswahl();

// POST: Schritt-Daten speichern
$current_step = isset($_GET['step']) ? (int)$_GET['step'] : 1;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_step = isset($_POST['umfrage_step']) ? (int)$_POST['umfrage_step'] : 0;
    if ($post_step === 1) {
        $draft['datum'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($_POST['datum'] ?? '')) ? trim($_POST['datum']) : $draft['datum'];
        $ts = trim($_POST['typ_sonstige'] ?? 'einsatz');
        $draft['bezeichnung_sonstige'] = $typen_map[$ts] ?? 'Einsatz';
        if ($ts === 'einsatz') {
            $draft['alarmierung_durch'] = trim($_POST['alarmierung_durch'] ?? '');
        } else {
            $draft['alarmierung_durch'] = '';
        }
        $draft['einsatzstichwort'] = trim($_POST['einsatzstichwort'] ?? '');
        $draft['klassifizierung'] = trim($_POST['klassifizierung'] ?? '');
        $draft['einsatzstelle'] = trim($_POST['einsatzstelle'] ?? '');
        $draft['objekt'] = trim($_POST['objekt'] ?? '');
        $draft['eigentuemer'] = trim($_POST['eigentuemer'] ?? '');
        $datum = $draft['datum'];
        $redirect = 'anwesenheitsliste-umfrage-schritt2.php?datum=' . urlencode($datum) . '&auswahl=' . urlencode($auswahl) . $einheit_param;
        header('Location: ' . $redirect);
        exit;
    }
    if ($post_step === 3) {
        $ps = trim($_POST['personenschaeden'] ?? '');
        $ps_detail = trim($_POST['personenschaeden_detail'] ?? '');
        if ($ps === 'Ja' && in_array($ps_detail, ['Person gerettet', 'Person verstorben'], true)) {
            $draft['personenschaeden'] = $ps_detail;
        } else {
            $draft['personenschaeden'] = $ps;
        }
        $draft['geschaedigter'] = trim($_POST['geschaedigter'] ?? '');
        header('Location: anwesenheitsliste-umfrage.php?datum=' . urlencode($draft['datum']) . '&auswahl=' . urlencode($auswahl) . '&step=4' . $einheit_param);
        exit;
    }
    if ($post_step === 4) {
        $draft['brandwache'] = trim($_POST['brandwache'] ?? '');
        header('Location: anwesenheitsliste-umfrage.php?datum=' . urlencode($draft['datum']) . '&auswahl=' . urlencode($auswahl) . '&step=5' . $einheit_param);
        exit;
    }
    if ($post_step === 5) {
        $draft['einsatzleiter_member_id'] = null;
        $draft['einsatzleiter_freitext'] = '';
        $ev = trim($_POST['einsatzleiter'] ?? '');
        if ($ev === '__freitext__') {
            $draft['einsatzleiter_freitext'] = trim($_POST['einsatzleiter_freitext'] ?? '');
        } elseif ($ev !== '' && ctype_digit($ev)) {
            $draft['einsatzleiter_member_id'] = (int)$ev;
        }
        $draft['bemerkung'] = trim($_POST['bemerkung'] ?? '');
        $draft['berichtersteller'] = trim($_POST['berichtersteller'] ?? '') ?: null;
        if (preg_match('/^\d+$/', (string)($draft['berichtersteller'] ?? ''))) {
            $draft['berichtersteller'] = (int)$draft['berichtersteller'];
        }
        header('Location: anwesenheitsliste-umfrage.php?datum=' . urlencode($draft['datum']) . '&auswahl=' . urlencode($auswahl) . '&step=6' . $einheit_param);
        exit;
    }
    if ($post_step === 6) {
        $draft['uhrzeit_von'] = trim($_POST['uhrzeit_von'] ?? $draft['uhrzeit_von']);
        $draft['uhrzeit_bis'] = trim($_POST['uhrzeit_bis'] ?? $draft['uhrzeit_bis']) ?: date('H:i');
        header('Location: anwesenheitsliste-eingaben.php?datum=' . urlencode($draft['datum']) . '&auswahl=' . urlencode($auswahl) . $einheit_param);
        exit;
    }
}

$current_step = isset($_GET['step']) ? (int)$_GET['step'] : 1;
if ($current_step < 1 || $current_step > 6) $current_step = 1;

// Mitglieder für Einsatzleiter/Berichtersteller
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
$members_for_einsatzleiter = anwesenheitsliste_members_for_leiter($db, $draft['members'] ?? [], $einheit_id);

$base_url = 'anwesenheitsliste-umfrage.php?datum=' . urlencode($draft['datum']) . '&auswahl=' . urlencode($auswahl) . $einheit_param;
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Anwesenheitsliste – Umfrage - Feuerwehr App</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <style>
        .umfrage-step { display: none; }
        .umfrage-step.active { display: block; }
        .progress-step { width: 16.66%; }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="index.php<?php echo $einheit_id > 0 ? '?einheit_id=' . (int)$einheit_id : ''; ?>"><i class="fas fa-fire"></i> Feuerwehr App</a>
            <?php if (isset($_SESSION['user_id']) && !is_system_user()): ?>
                <div class="d-flex ms-auto">
                <?php
                $admin_menu_in_navbar = true;
                $admin_menu_base = 'admin/';
                $admin_menu_logout = 'logout.php';
                $admin_menu_index = 'index.php' . ($einheit_id > 0 ? '?einheit_id=' . (int)$einheit_id : '');
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
                    <div class="card-header">
                        <h3 class="mb-0"><i class="fas fa-poll"></i> Anwesenheitsliste – Umfrage</h3>
                        <p class="text-muted mb-0 mt-1">Schrittweise Erfassung</p>
                        <div class="progress mt-2" style="height: 6px;">
                            <?php for ($s = 1; $s <= 6; $s++): ?>
                            <div class="progress-bar bg-<?php echo $s <= $current_step ? 'primary' : 'secondary'; ?>" role="progressbar" style="width: 16.66%;" aria-valuenow="<?php echo $current_step; ?>" aria-valuemin="1" aria-valuemax="6"></div>
                            <?php endfor; ?>
                        </div>
                    </div>
                    <div class="card-body p-4">
                        <?php if ($current_step === 1): ?>
                        <!-- Schritt 1: Einsatzdaten -->
                        <form method="post" class="umfrage-step active">
                            <input type="hidden" name="umfrage_step" value="1">
                            <h5 class="mb-3">Einsatzdaten</h5>
                            <div class="mb-3">
                                <label class="form-label">Datum</label>
                                <input type="date" class="form-control" name="datum" value="<?php echo htmlspecialchars($draft['datum']); ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Typ</label>
                                <select class="form-select" name="typ_sonstige" id="typ_sonstige_step1">
                                    <?php foreach ($typen_map as $key => $label): ?>
                                    <option value="<?php echo htmlspecialchars($key); ?>" <?php echo ($draft['bezeichnung_sonstige'] ?? 'Einsatz') === $label ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3" id="alarmierung_wrap" style="display: <?php echo ($draft['bezeichnung_sonstige'] ?? 'Einsatz') === 'Einsatz' ? 'block' : 'none'; ?>;">
                                <label class="form-label">Alarmierung durch</label>
                                <select class="form-select" name="alarmierung_durch">
                                    <option value="">— Bitte wählen —</option>
                                    <?php foreach ($alarmierung_opts as $o): ?>
                                    <option value="<?php echo htmlspecialchars($o); ?>" <?php echo ($draft['alarmierung_durch'] ?? '') === $o ? 'selected' : ''; ?>><?php echo htmlspecialchars($o); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Einsatzstichwort</label>
                                <input type="text" class="form-control" name="einsatzstichwort" value="<?php echo htmlspecialchars($draft['einsatzstichwort'] ?? ''); ?>" placeholder="z.B. FEUER3, THL">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Klassifizierung</label>
                                <select class="form-select" name="klassifizierung">
                                    <option value="">— Bitte wählen —</option>
                                    <?php foreach ($klassifizierung_opts as $o): ?>
                                    <option value="<?php echo htmlspecialchars($o); ?>" <?php echo ($draft['klassifizierung'] ?? '') === $o ? 'selected' : ''; ?>><?php echo htmlspecialchars($o); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Adresse / Einsatzstelle</label>
                                <div class="input-group">
                                    <input type="text" class="form-control" id="einsatzstelle_umfrage" name="einsatzstelle" value="<?php echo htmlspecialchars($draft['einsatzstelle'] ?? ''); ?>" placeholder="Adresse eingeben">
                                    <?php if ($geraetehaus_adresse !== ''): ?>
                                    <button type="button" class="btn btn-outline-secondary" id="btn_geraetehaus_umfrage" title="Adresse des Gerätehauses eintragen" data-address="<?php echo htmlspecialchars($geraetehaus_adresse); ?>">Gerätehaus</button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Objekt</label>
                                <input type="text" class="form-control" name="objekt" value="<?php echo htmlspecialchars($draft['objekt'] ?? ''); ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Eigentümer</label>
                                <input type="text" class="form-control" name="eigentuemer" value="<?php echo htmlspecialchars($draft['eigentuemer'] ?? ''); ?>">
                            </div>
                            <div class="d-flex justify-content-between mt-4">
                                <a href="anwesenheitsliste.php<?php echo $einheit_param ? '?' . ltrim($einheit_param, '&') : ''; ?>" class="btn btn-outline-secondary">Abbrechen</a>
                                <button type="submit" class="btn btn-primary">Weiter</button>
                            </div>
                        </form>
                        <?php endif; ?>

                        <?php if ($current_step === 3): ?>
                        <!-- Schritt 3: Personenschäden -->
                        <form method="post" class="umfrage-step active">
                            <input type="hidden" name="umfrage_step" value="3">
                            <input type="hidden" name="datum" value="<?php echo htmlspecialchars($draft['datum']); ?>">
                            <h5 class="mb-3">Gab es Personenschäden?</h5>
                            <div class="mb-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="personenschaeden" id="ps_ja" value="Ja" <?php echo in_array($draft['personenschaeden'] ?? '', ['Ja', 'Person gerettet', 'Person verstorben'], true) ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="ps_ja">Ja</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="personenschaeden" id="ps_nein" value="Nein" <?php echo ($draft['personenschaeden'] ?? '') === 'Nein' ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="ps_nein">Nein</label>
                                </div>
                            </div>
                            <div id="personenschaeden_felder" class="mb-3" style="display: <?php echo in_array($draft['personenschaeden'] ?? '', ['Ja', 'Person gerettet', 'Person verstorben'], true) ? 'block' : 'none'; ?>;">
                                <div class="mb-2">
                                    <label class="form-label">Geschädigter</label>
                                    <input type="text" class="form-control" name="geschaedigter" value="<?php echo htmlspecialchars($draft['geschaedigter'] ?? ''); ?>">
                                </div>
                                <div>
                                    <label class="form-label">Auswahl</label>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="personenschaeden_detail" id="ps_gerettet" value="Person gerettet" <?php echo ($draft['personenschaeden'] ?? '') === 'Person gerettet' ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="ps_gerettet">Person gerettet</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="personenschaeden_detail" id="ps_verstorben" value="Person verstorben" <?php echo ($draft['personenschaeden'] ?? '') === 'Person verstorben' ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="ps_verstorben">Person verstorben</label>
                                    </div>
                                </div>
                            </div>
                            <div class="d-flex justify-content-between mt-4">
                                <a href="anwesenheitsliste-geraete.php?datum=<?php echo urlencode($draft['datum']); ?>&auswahl=<?php echo urlencode($auswahl); ?>&umfrage=1<?php echo $einheit_param; ?>" class="btn btn-outline-secondary">Zurück</a>
                                <button type="submit" class="btn btn-primary">Weiter</button>
                            </div>
                        </form>
                        <?php endif; ?>

                        <?php if ($current_step === 4): ?>
                        <!-- Schritt 4: Brandwache -->
                        <form method="post" class="umfrage-step active">
                            <input type="hidden" name="umfrage_step" value="4">
                            <input type="hidden" name="datum" value="<?php echo htmlspecialchars($draft['datum']); ?>">
                            <h5 class="mb-3">Wurde oder wird eine Brandwache durchgeführt?</h5>
                            <div class="mb-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="brandwache" id="bw_ja" value="Ja" <?php echo ($draft['brandwache'] ?? '') === 'Ja' ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="bw_ja">Ja</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="brandwache" id="bw_nein" value="Nein" <?php echo ($draft['brandwache'] ?? '') === 'Nein' ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="bw_nein">Nein</label>
                                </div>
                            </div>
                            <div class="d-flex justify-content-between mt-4">
                                <a href="<?php echo $base_url; ?>&step=3" class="btn btn-outline-secondary">Zurück</a>
                                <button type="submit" class="btn btn-primary">Weiter</button>
                            </div>
                        </form>
                        <?php endif; ?>

                        <?php if ($current_step === 5): ?>
                        <!-- Schritt 5: Einsatzleiter, Einsatzkurzbericht, Berichtersteller -->
                        <form method="post" class="umfrage-step active">
                            <input type="hidden" name="umfrage_step" value="5">
                            <input type="hidden" name="datum" value="<?php echo htmlspecialchars($draft['datum']); ?>">
                            <h5 class="mb-3">Einsatzleiter, Einsatzkurzbericht und Berichtersteller</h5>
                            <div class="mb-3">
                                <label class="form-label">Einsatzleiter</label>
                                <select class="form-select" name="einsatzleiter">
                                    <option value="">— Bitte wählen —</option>
                                    <option value="__freitext__" <?php echo !empty($draft['einsatzleiter_freitext']) ? 'selected' : ''; ?>>— Freitext —</option>
                                    <?php foreach ($members_for_einsatzleiter as $m): ?>
                                    <option value="<?php echo (int)$m['id']; ?>" <?php echo $draft['einsatzleiter_member_id'] == $m['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($m['last_name'] . ', ' . $m['first_name']); ?></option>
                                    <?php endforeach; ?>
                                    <?php foreach ($members_all as $m): if (in_array($m['id'], array_column($members_for_einsatzleiter, 'id'))) continue; ?>
                                    <option value="<?php echo (int)$m['id']; ?>"><?php echo htmlspecialchars($m['last_name'] . ', ' . $m['first_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="mt-2" id="einsatzleiter_freitext_wrap" style="display: <?php echo !empty($draft['einsatzleiter_freitext']) ? 'block' : 'none'; ?>;">
                                    <input type="text" class="form-control" name="einsatzleiter_freitext" placeholder="Name eingeben" value="<?php echo htmlspecialchars($draft['einsatzleiter_freitext'] ?? ''); ?>">
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Einsatzkurzbericht</label>
                                <textarea class="form-control" name="bemerkung" rows="4"><?php echo htmlspecialchars($draft['bemerkung'] ?? ''); ?></textarea>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Berichtersteller</label>
                                <select class="form-select" name="berichtersteller">
                                    <option value="">— Bitte wählen —</option>
                                    <?php foreach ($members_all as $m): ?>
                                    <option value="<?php echo (int)$m['id']; ?>" <?php echo ($draft['berichtersteller'] ?? '') == $m['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($m['last_name'] . ', ' . $m['first_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="d-flex justify-content-between mt-4">
                                <a href="<?php echo $base_url; ?>&step=4" class="btn btn-outline-secondary">Zurück</a>
                                <button type="submit" class="btn btn-primary">Weiter</button>
                            </div>
                        </form>
                        <?php endif; ?>

                        <?php if ($current_step === 6): ?>
                        <!-- Schritt 6: Uhrzeit-Bestätigung -->
                        <form method="post" class="umfrage-step active">
                            <input type="hidden" name="umfrage_step" value="6">
                            <input type="hidden" name="datum" value="<?php echo htmlspecialchars($draft['datum']); ?>">
                            <h5 class="mb-3">Sind Beginn und Enduhrzeit korrekt?</h5>
                            <p class="text-muted">Bitte prüfen Sie die Uhrzeiten und geben Sie sie bei Bedarf an.</p>
                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label">Uhrzeit von</label>
                                    <input type="time" class="form-control" name="uhrzeit_von" value="<?php echo htmlspecialchars($draft['uhrzeit_von'] ?? ''); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Uhrzeit bis</label>
                                    <input type="time" class="form-control" name="uhrzeit_bis" value="<?php echo htmlspecialchars($draft['uhrzeit_bis'] ?? date('H:i')); ?>">
                                </div>
                            </div>
                            <div class="d-flex justify-content-between mt-4">
                                <a href="<?php echo $base_url; ?>&step=5" class="btn btn-outline-secondary">Zurück</a>
                                <button type="submit" class="btn btn-primary">Weiter zur Anwesenheitsliste</button>
                            </div>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
                <a href="anwesenheitsliste.php<?php echo $einheit_param ? '?' . ltrim($einheit_param, '&') : ''; ?>" class="btn btn-link mt-2">Zurück zur Anwesenheitsliste</a>
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
        var typSelect = document.getElementById('typ_sonstige_step1');
        var alarmierungWrap = document.getElementById('alarmierung_wrap');
        if (typSelect && alarmierungWrap) {
            function toggleAlarmierung() {
                alarmierungWrap.style.display = typSelect.value === 'einsatz' ? 'block' : 'none';
            }
            typSelect.addEventListener('change', toggleAlarmierung);
        }
        var btnGeraetehaus = document.getElementById('btn_geraetehaus_umfrage');
        var inputEinsatzstelle = document.getElementById('einsatzstelle_umfrage');
        if (btnGeraetehaus && inputEinsatzstelle) {
            btnGeraetehaus.addEventListener('click', function() {
                var addr = this.getAttribute('data-address') || '';
                if (addr) inputEinsatzstelle.value = addr;
            });
        }
        var psJa = document.getElementById('ps_ja');
        var psNein = document.getElementById('ps_nein');
        var psFelder = document.getElementById('personenschaeden_felder');
        if (psJa && psFelder) {
            function togglePs() {
                psFelder.style.display = psJa.checked ? 'block' : 'none';
            }
            psJa.addEventListener('change', togglePs);
            psNein && psNein.addEventListener('change', togglePs);
        }
        var elSelect = document.querySelector('select[name="einsatzleiter"]');
        var elFreitext = document.getElementById('einsatzleiter_freitext_wrap');
        if (elSelect && elFreitext) {
            function toggleEl() {
                elFreitext.style.display = elSelect.value === '__freitext__' ? 'block' : 'none';
            }
            elSelect.addEventListener('change', toggleEl);
        }
    })();
    </script>
</body>
</html>
