<?php
/**
 * Mitglieder-Auswertung: Statistik-Bereiche Personen, Einsätze/Dienste, Fahrzeuge, Geräte.
 * Filter: Jahr, Zeitraum (von/bis).
 */
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/dienstplan-typen.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('Location: ../login.php');
    exit;
}
if (!has_permission('members')) {
    header('Location: ../login.php?error=access_denied');
    exit;
}

$bereich = isset($_GET['bereich']) ? trim($_GET['bereich']) : '';
$gueltige_bereiche = ['personen', 'einsaetze', 'fahrzeuge', 'geraete'];
if ($bereich !== '' && !in_array($bereich, $gueltige_bereiche)) {
    $bereich = '';
}

$jahr = isset($_GET['jahr']) ? (int)$_GET['jahr'] : (int)date('Y');
$von = isset($_GET['von']) ? trim($_GET['von']) : $jahr . '-01-01';
$bis = isset($_GET['bis']) ? trim($_GET['bis']) : date('Y-m-d');
$member_id = isset($_GET['member_id']) ? (int)$_GET['member_id'] : 0;
$vehicle_id = isset($_GET['vehicle_id']) ? (int)$_GET['vehicle_id'] : 0;
$ansicht = isset($_GET['ansicht']) ? trim($_GET['ansicht']) : 'alle';
$typ_filter = isset($_GET['typ']) ? trim($_GET['typ']) : 'beides';
$beschreibung_filter = isset($_GET['beschreibung']) ? trim($_GET['beschreibung']) : '';
$thema_filter = isset($_GET['thema']) ? trim($_GET['thema']) : '';
$zeit_von = isset($_GET['zeit_von']) ? trim($_GET['zeit_von']) : '';
$zeit_bis = isset($_GET['zeit_bis']) ? trim($_GET['zeit_bis']) : '';
if (!in_array($ansicht, ['tabelle', 'diagramme', 'karten', 'alle'])) $ansicht = 'alle';
if (!in_array($typ_filter, ['einsaetze', 'uebungen', 'beides', 'jhv', 'sonstiges'])) $typ_filter = 'beides';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $von)) $von = $jahr . '-01-01';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $bis)) $bis = date('Y-m-d');
if (!preg_match('/^\d{1,2}:\d{2}$/', $zeit_von)) $zeit_von = '';
if (!preg_match('/^\d{1,2}:\d{2}$/', $zeit_bis)) $zeit_bis = '';
$zeit_filter_aktiv = $zeit_von !== '' || $zeit_bis !== '';

$members = [];
$vehicles = [];
try {
    $stmt = $db->query("SELECT id, first_name, last_name FROM members ORDER BY last_name, first_name");
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}
try {
    $stmt = $db->query("SELECT id, name FROM vehicles ORDER BY name");
    $vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}
$beschreibung_optionen = [];
$beschreibung_optionen_jhv = [];
$beschreibung_optionen_sonstiges = [];
$is_jhv_sonstiges_filter = in_array($typ_filter, ['jhv', 'sonstiges']);
$is_uebungen_filter = ($typ_filter === 'uebungen');
if ($bereich !== '') {
    $load_beschreibung_opts = function($typ_cond) use ($db, $von, $bis) {
        $opts = [];
        try {
            $sql = "SELECT DISTINCT a.bezeichnung AS b FROM anwesenheitslisten a LEFT JOIN dienstplan d ON d.id = a.dienstplan_id WHERE a.datum BETWEEN ? AND ? AND " . $typ_cond . " AND a.bezeichnung IS NOT NULL AND TRIM(a.bezeichnung) != '' ORDER BY b";
            $stmt = $db->prepare($sql);
            $stmt->execute([$von, $bis]);
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $b = trim($r['b'] ?? '');
                if ($b !== '' && !in_array($b, $opts)) $opts[] = $b;
            }
            $sql2 = "SELECT DISTINCT TRIM(JSON_UNQUOTE(JSON_EXTRACT(a.custom_data, '$.beschreibung'))) AS b FROM anwesenheitslisten a LEFT JOIN dienstplan d ON d.id = a.dienstplan_id WHERE a.datum BETWEEN ? AND ? AND " . $typ_cond . " AND a.custom_data IS NOT NULL AND JSON_EXTRACT(a.custom_data, '$.beschreibung') IS NOT NULL AND TRIM(JSON_UNQUOTE(JSON_EXTRACT(a.custom_data, '$.beschreibung'))) != '' ORDER BY b";
            $stmt2 = $db->prepare($sql2);
            $stmt2->execute([$von, $bis]);
            while ($r = $stmt2->fetch(PDO::FETCH_ASSOC)) {
                $b = trim($r['b'] ?? '');
                if ($b !== '' && !in_array($b, $opts)) $opts[] = $b;
            }
        } catch (Exception $e) {}
        return $opts;
    };
    $typ_cond_jhv = "((a.typ = 'dienst' AND d.typ = 'jahreshauptversammlung') OR (a.typ = 'manuell' AND (a.bezeichnung = 'Jahreshauptversammlung' OR (a.custom_data IS NOT NULL AND JSON_UNQUOTE(JSON_EXTRACT(a.custom_data, '$.typ_sonstige')) = 'jahreshauptversammlung'))))";
    $typ_cond_sonst = "((a.typ = 'dienst' AND d.typ = 'sonstiges') OR (a.typ = 'manuell' AND (a.bezeichnung = 'Sonstiges' OR (a.custom_data IS NOT NULL AND JSON_UNQUOTE(JSON_EXTRACT(a.custom_data, '$.typ_sonstige')) = 'sonstiges'))))";
    $beschreibung_optionen_jhv = $load_beschreibung_opts($typ_cond_jhv);
    $beschreibung_optionen_sonstiges = $load_beschreibung_opts($typ_cond_sonst);
}
$beschreibung_optionen = $typ_filter === 'jhv' ? $beschreibung_optionen_jhv : ($typ_filter === 'sonstiges' ? $beschreibung_optionen_sonstiges : []);

$thema_optionen = [];
$is_uebungen_filter = ($typ_filter === 'uebungen');
if ($bereich !== '') {
    try {
        $ueb_cond = "((a.typ = 'dienst' AND d.typ IN ('uebungsdienst', 'dienst', 'uebung')) OR (a.typ = 'manuell' AND a.bezeichnung = 'Übungsdienst'))";
        $stmt = $db->prepare("SELECT DISTINCT COALESCE(NULLIF(TRIM(a.bezeichnung), ''), d.bezeichnung) AS b FROM anwesenheitslisten a LEFT JOIN dienstplan d ON d.id = a.dienstplan_id WHERE a.datum BETWEEN ? AND ? AND " . $ueb_cond . " AND (a.bezeichnung IS NOT NULL AND TRIM(a.bezeichnung) != '' OR d.bezeichnung IS NOT NULL AND TRIM(d.bezeichnung) != '') ORDER BY b");
        $stmt->execute([$von, $bis]);
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $b = trim($r['b'] ?? '');
            if ($b !== '' && !in_array($b, $thema_optionen)) $thema_optionen[] = $b;
        }
    } catch (Exception $e) {}
}

// Hilfsfunktion: Ist Anwesenheit Einsatz oder Übung?
// Übungsdienste und Dienste aus dem Dienstplan (dienst_typ 'uebungsdienst', 'dienst', 'uebung') werden alle als Übung gewertet.
// JHV und Sonstiges sind NICHT in der Hauptstatistik, sondern separat auswertbar.
function ist_einsatz($row) {
    if ($row['liste_typ'] === 'einsatz') return true;
    if ($row['liste_typ'] === 'dienst' && !empty($row['dienst_typ']) && $row['dienst_typ'] === 'einsatz') return true;
    return false;
}
function ist_jhv_sonstiges($row) {
    $dt = $row['dienst_typ'] ?? '';
    if ($dt === 'jahreshauptversammlung' || $dt === 'sonstiges') return true;
    if (($row['liste_typ'] ?? '') === 'manuell') {
        $bez = trim($row['bezeichnung'] ?? '');
        if ($bez === 'Jahreshauptversammlung' || $bez === 'Sonstiges') return true;
        $ts = $row['typ_sonstige'] ?? '';
        if ($ts === '') {
            $cd = $row['custom_data'] ?? '';
            if (is_string($cd)) {
                $dec = json_decode($cd, true);
                $ts = $dec['typ_sonstige'] ?? '';
            }
        }
        if ($ts === 'jahreshauptversammlung' || $ts === 'sonstiges') return true;
    }
    return false;
}
function ist_jhv($row) {
    $dt = $row['dienst_typ'] ?? '';
    if ($dt === 'jahreshauptversammlung') return true;
    if (($row['liste_typ'] ?? '') === 'manuell') {
        $bez = trim($row['bezeichnung'] ?? '');
        if ($bez === 'Jahreshauptversammlung') return true;
        $ts = $row['typ_sonstige'] ?? '';
        if ($ts === '' && !empty($row['custom_data'])) {
            $dec = is_string($row['custom_data']) ? json_decode($row['custom_data'], true) : $row['custom_data'];
            $ts = $dec['typ_sonstige'] ?? '';
        }
        if ($ts === 'jahreshauptversammlung') return true;
    }
    return false;
}
function ist_sonstiges($row) {
    $dt = $row['dienst_typ'] ?? '';
    if ($dt === 'sonstiges') return true;
    if (($row['liste_typ'] ?? '') === 'manuell') {
        $bez = trim($row['bezeichnung'] ?? '');
        if ($bez === 'Sonstiges') return true;
        $ts = $row['typ_sonstige'] ?? '';
        if ($ts === '' && !empty($row['custom_data'])) {
            $dec = is_string($row['custom_data']) ? json_decode($row['custom_data'], true) : $row['custom_data'];
            $ts = $dec['typ_sonstige'] ?? '';
        }
        if ($ts === 'sonstiges') return true;
    }
    return false;
}

function get_zeit_filter_sql(&$params) {
    global $zeit_von, $zeit_bis, $zeit_filter_aktiv;
    if (!$zeit_filter_aktiv) return '';
    $conds = [];
    if ($zeit_von !== '') { $conds[] = 'a.uhrzeit_von >= ?'; $params[] = $zeit_von; }
    if ($zeit_bis !== '') { $conds[] = 'a.uhrzeit_von <= ?'; $params[] = $zeit_bis; }
    if (empty($conds)) return '';
    return ' AND a.uhrzeit_von IS NOT NULL AND ' . implode(' AND ', $conds);
}
function get_typ_filter_sql() {
    global $typ_filter;
    if ($typ_filter === 'jhv') {
        return " AND ((a.typ = 'dienst' AND d.typ = 'jahreshauptversammlung') OR (a.typ = 'manuell' AND (a.bezeichnung = 'Jahreshauptversammlung' OR (a.custom_data IS NOT NULL AND JSON_UNQUOTE(JSON_EXTRACT(a.custom_data, '$.typ_sonstige')) = 'jahreshauptversammlung'))))";
    }
    if ($typ_filter === 'sonstiges') {
        return " AND ((a.typ = 'dienst' AND d.typ = 'sonstiges') OR (a.typ = 'manuell' AND (a.bezeichnung = 'Sonstiges' OR (a.custom_data IS NOT NULL AND JSON_UNQUOTE(JSON_EXTRACT(a.custom_data, '$.typ_sonstige')) = 'sonstiges'))))";
    }
    return '';
}
function get_beschreibung_filter_sql(&$params) {
    global $typ_filter, $beschreibung_filter;
    if (!in_array($typ_filter, ['jhv', 'sonstiges']) || $beschreibung_filter === '') return '';
    $params[] = $beschreibung_filter;
    $params[] = $beschreibung_filter;
    return ' AND (a.bezeichnung = ? OR (a.custom_data IS NOT NULL AND JSON_UNQUOTE(JSON_EXTRACT(a.custom_data, \'$.beschreibung\')) = ?))';
}
function get_thema_filter_sql(&$params) {
    global $typ_filter, $thema_filter;
    if ($typ_filter !== 'uebungen' || $thema_filter === '') return '';
    $params[] = $thema_filter;
    return ' AND (COALESCE(NULLIF(TRIM(a.bezeichnung), \'\'), d.bezeichnung) = ?)';
}

$filter_params = ['jahr' => $jahr, 'von' => $von, 'bis' => $bis, 'zeit_von' => $zeit_von, 'zeit_bis' => $zeit_bis, 'member_id' => $member_id, 'vehicle_id' => $vehicle_id, 'ansicht' => $ansicht, 'typ' => $typ_filter, 'beschreibung' => $beschreibung_filter, 'thema' => $thema_filter];
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mitglieder-Auswertung – Feuerwehr App</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
    <div class="container-fluid">
        <a class="navbar-brand" href="../index.php"><i class="fas fa-fire"></i> Feuerwehr App</a>
        <div class="navbar-nav ms-auto">
            <a class="nav-link" href="members.php"><i class="fas fa-arrow-left"></i> Zurück zur Mitgliederverwaltung</a>
        </div>
    </div>
</nav>

<div class="container-fluid mt-4">
    <h1 class="h3 mb-4"><i class="fas fa-chart-pie"></i> Auswertung</h1>

    <?php if ($bereich === ''): ?>
    <!-- Übersicht: 4 Bereiche -->
    <div class="row g-3 mb-4">
        <div class="col-md-6 col-lg-3">
            <a href="?bereich=personen&<?php echo http_build_query($filter_params); ?>" class="text-decoration-none">
                <div class="card h-100 border-primary hover-shadow">
                    <div class="card-body text-center">
                        <i class="fas fa-users fa-3x text-primary mb-2"></i>
                        <h5 class="card-title">Personen</h5>
                        <p class="card-text text-muted small">Teilnahme, Fahrzeuge, Stunden, Maschinist/EF, Letzte Teilnahme</p>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-6 col-lg-3">
            <a href="?bereich=einsaetze&<?php echo http_build_query($filter_params); ?>" class="text-decoration-none">
                <div class="card h-100 border-success hover-shadow">
                    <div class="card-body text-center">
                        <i class="fas fa-clipboard-list fa-3x text-success mb-2"></i>
                        <h5 class="card-title">Einsätze / Dienste</h5>
                        <p class="card-text text-muted small">Anzahl, Gesamtstunden, Durchschn. Personen, Gruppenführer, Zugführer, Klassifizierung, Top-Themen</p>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-6 col-lg-3">
            <a href="?bereich=fahrzeuge&<?php echo http_build_query($filter_params); ?>" class="text-decoration-none">
                <div class="card h-100 border-warning hover-shadow">
                    <div class="card-body text-center">
                        <i class="fas fa-truck fa-3x text-warning mb-2"></i>
                        <h5 class="card-title">Fahrzeuge</h5>
                        <p class="card-text text-muted small">Einsätze/Übungen, Besatzungsstärke, Häufigste Maschinisten/EF</p>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-6 col-lg-3">
            <a href="?bereich=geraete&<?php echo http_build_query($filter_params); ?>" class="text-decoration-none">
                <div class="card h-100 border-info hover-shadow">
                    <div class="card-body text-center">
                        <i class="fas fa-wrench fa-3x text-info mb-2"></i>
                        <h5 class="card-title">Geräte</h5>
                        <p class="card-text text-muted small">Welche Geräte wie oft eingesetzt</p>
                    </div>
                </div>
            </a>
        </div>
    </div>
    <?php else: ?>
    <div class="mb-3">
        <a href="members-auswertung.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left"></i> Zurück zur Übersicht</a>
    </div>

    <!-- Filter -->
    <div class="card mb-4">
        <div class="card-header">Filter</div>
        <div class="card-body">
            <form method="get" class="row g-3">
                <input type="hidden" name="bereich" value="<?php echo htmlspecialchars($bereich); ?>">
                <div class="col-md-2">
                    <label class="form-label">Jahr</label>
                    <select name="jahr" class="form-select">
                        <?php for ($y = date('Y') + 1; $y >= date('Y') - 5; $y--): ?>
                        <option value="<?php echo $y; ?>" <?php echo $jahr === $y ? 'selected' : ''; ?>><?php echo $y; ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Von</label>
                    <input type="date" name="von" class="form-control" value="<?php echo htmlspecialchars($von); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Bis</label>
                    <input type="date" name="bis" class="form-control" value="<?php echo htmlspecialchars($bis); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label" title="Optional: nur Dienste, die in diesem Zeitraum begonnen haben">Zeit von</label>
                    <input type="time" name="zeit_von" class="form-control" value="<?php echo htmlspecialchars($zeit_von); ?>" title="Optional: Filter nach Tageszeit (Beginn)">
                </div>
                <div class="col-md-2">
                    <label class="form-label" title="Optional: nur Dienste, die in diesem Zeitraum begonnen haben">Zeit bis</label>
                    <input type="time" name="zeit_bis" class="form-control" value="<?php echo htmlspecialchars($zeit_bis); ?>" title="Optional: Filter nach Tageszeit (Beginn)">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Typ</label>
                    <select name="typ" id="filter-typ" class="form-select">
                        <option value="beides" <?php echo $typ_filter === 'beides' ? 'selected' : ''; ?>>Einsätze + Übungen</option>
                        <option value="einsaetze" <?php echo $typ_filter === 'einsaetze' ? 'selected' : ''; ?>>Nur Einsätze</option>
                        <option value="uebungen" <?php echo $typ_filter === 'uebungen' ? 'selected' : ''; ?>>Nur Übungen</option>
                        <option value="jhv" <?php echo $typ_filter === 'jhv' ? 'selected' : ''; ?>>JHV</option>
                        <option value="sonstiges" <?php echo $typ_filter === 'sonstiges' ? 'selected' : ''; ?>>Sonstiges</option>
                    </select>
                </div>
                <div class="col-md-2" id="filter-beschreibung-wrap" style="<?php echo $is_jhv_sonstiges_filter ? '' : 'display:none'; ?>">
                    <label class="form-label">Beschreibung</label>
                    <select name="beschreibung" id="filter-beschreibung" class="form-select">
                        <option value="">— Alle —</option>
                        <?php foreach ($beschreibung_optionen as $opt): ?>
                        <option value="<?php echo htmlspecialchars($opt); ?>" <?php echo $beschreibung_filter === $opt ? 'selected' : ''; ?>><?php echo htmlspecialchars($opt); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2" id="filter-thema-wrap" style="<?php echo $is_uebungen_filter ? '' : 'display:none'; ?>">
                    <label class="form-label">Thema</label>
                    <select name="thema" id="filter-thema" class="form-select">
                        <option value="">— Alle —</option>
                        <?php foreach ($thema_optionen as $opt): ?>
                        <option value="<?php echo htmlspecialchars($opt); ?>" <?php echo $thema_filter === $opt ? 'selected' : ''; ?>><?php echo htmlspecialchars($opt); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($bereich === 'personen'): ?>
                <div class="col-md-2">
                    <label class="form-label">Mitglied</label>
                    <select name="member_id" class="form-select">
                        <option value="">— Alle —</option>
                        <?php foreach ($members as $m): ?>
                        <option value="<?php echo (int)$m['id']; ?>" <?php echo $member_id === (int)$m['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($m['last_name'] . ', ' . $m['first_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <?php if ($bereich === 'fahrzeuge'): ?>
                <div class="col-md-2">
                    <label class="form-label">Fahrzeug</label>
                    <select name="vehicle_id" class="form-select">
                        <option value="">— Alle —</option>
                        <?php foreach ($vehicles as $v): ?>
                        <option value="<?php echo (int)$v['id']; ?>" <?php echo $vehicle_id === (int)$v['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($v['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="col-md-2">
                    <label class="form-label">Ansicht</label>
                    <select name="ansicht" class="form-select">
                        <option value="alle" <?php echo $ansicht === 'alle' ? 'selected' : ''; ?>>Alle</option>
                        <option value="tabelle" <?php echo $ansicht === 'tabelle' ? 'selected' : ''; ?>>Tabelle</option>
                        <option value="diagramme" <?php echo $ansicht === 'diagramme' ? 'selected' : ''; ?>>Diagramme</option>
                        <?php if ($bereich === 'personen'): ?>
                        <option value="karten" <?php echo $ansicht === 'karten' ? 'selected' : ''; ?>>Karten</option>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Anwenden</button>
                </div>
                <?php if ($zeit_filter_aktiv): ?>
                <div class="col-12">
                    <span class="badge bg-secondary"><i class="fas fa-clock"></i> Zeitfilter aktiv: <?php echo $zeit_von !== '' ? htmlspecialchars($zeit_von) : '00:00'; ?> – <?php echo $zeit_bis !== '' ? htmlspecialchars($zeit_bis) : '24:00'; ?> (nach Beginn)</span>
                </div>
                <?php endif; ?>
            </form>
        </div>
    </div>
    <script>
    (function(){
        var sel = document.getElementById('filter-typ');
        var wrap = document.getElementById('filter-beschreibung-wrap');
        var beschrSel = document.getElementById('filter-beschreibung');
        var optsJhv = <?php echo json_encode($beschreibung_optionen_jhv); ?>;
        var optsSonstiges = <?php echo json_encode($beschreibung_optionen_sonstiges); ?>;
        var currentFilter = <?php echo json_encode($beschreibung_filter); ?>;
        function fillBeschreibung(opts) {
            if (!beschrSel) return;
            beschrSel.innerHTML = '<option value="">— Alle —</option>';
            (opts || []).forEach(function(o) {
                var opt = document.createElement('option');
                opt.value = o;
                opt.textContent = o;
                if (o === currentFilter) opt.selected = true;
                beschrSel.appendChild(opt);
            });
        }
        var themaWrap = document.getElementById('filter-thema-wrap');
        function toggle() {
            var v = sel.value;
            if (v === 'jhv') {
                wrap.style.display = '';
                fillBeschreibung(optsJhv);
            } else if (v === 'sonstiges') {
                wrap.style.display = '';
                fillBeschreibung(optsSonstiges);
            } else {
                wrap.style.display = 'none';
            }
            if (themaWrap) themaWrap.style.display = (v === 'uebungen') ? '' : 'none';
        }
        if (sel && wrap) sel.addEventListener('change', toggle);
    })();
    </script>

    <?php
    // ==================== BEREICH PERSONEN ====================
    if ($bereich === 'personen'):
        $personen_stats = [];
        $personen_maschinist = [];
        $personen_einheitsfuehrer = [];
        $personen_letzte = [];
        $anzahl_einsaetze = 0;
        $anzahl_uebungen = 0;
        $anzahl_jhv_sonstiges = 0;
        try {
            $params_anz = [$von, $bis];
            $sql_anz = "SELECT a.id, a.typ AS liste_typ, a.bezeichnung, a.custom_data, d.typ AS dienst_typ FROM anwesenheitslisten a LEFT JOIN dienstplan d ON d.id = a.dienstplan_id WHERE a.datum BETWEEN ? AND ?" . get_zeit_filter_sql($params_anz) . get_typ_filter_sql() . get_beschreibung_filter_sql($params_anz) . get_thema_filter_sql($params_anz);
            $stmt = $db->prepare($sql_anz);
            $stmt->execute($params_anz);
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (ist_einsatz($r)) $anzahl_einsaetze++;
                elseif (ist_jhv_sonstiges($r)) $anzahl_jhv_sonstiges++;
                else $anzahl_uebungen++;
            }
            $anzahl_gesamt = $anzahl_einsaetze + $anzahl_uebungen;
            $sql = "SELECT am.member_id, am.vehicle_id, a.id AS liste_id, a.datum, a.typ AS liste_typ, a.bezeichnung, a.custom_data, a.uhrzeit_von, a.uhrzeit_bis, d.typ AS dienst_typ FROM anwesenheitsliste_mitglieder am JOIN anwesenheitslisten a ON a.id = am.anwesenheitsliste_id LEFT JOIN dienstplan d ON d.id = a.dienstplan_id WHERE a.datum BETWEEN ? AND ?";
            $params = [$von, $bis];
            $sql .= get_zeit_filter_sql($params) . get_typ_filter_sql() . get_beschreibung_filter_sql($params) . get_thema_filter_sql($params);
            if ($member_id > 0) { $sql .= " AND am.member_id = ?"; $params[] = $member_id; }
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $mid = (int)$r['member_id'];
                $einsatz = ist_einsatz($r);
                $jhv = ist_jhv_sonstiges($r);
                if ($typ_filter === 'einsaetze' && !$einsatz) continue;
                if ($typ_filter === 'uebungen' && ($einsatz || $jhv)) continue;
                if ($typ_filter === 'beides' && $jhv) continue;
                if ($typ_filter === 'jhv' && !ist_jhv($r)) continue;
                if ($typ_filter === 'sonstiges' && !ist_sonstiges($r)) continue;
                $personen_stats[$mid] = $personen_stats[$mid] ?? ['einsaetze' => 0, 'uebungen' => 0, 'jhv_sonstiges' => 0, 'jhv' => 0, 'sonstiges' => 0, 'fahrzeuge' => [], 'stunden' => 0];
                if ($einsatz) $personen_stats[$mid]['einsaetze']++;
                elseif ($jhv) {
                    $personen_stats[$mid]['jhv_sonstiges']++;
                    if (ist_jhv($r)) $personen_stats[$mid]['jhv']++;
                    else $personen_stats[$mid]['sonstiges']++;
                } else $personen_stats[$mid]['uebungen']++;
                if (!empty($r['vehicle_id'])) {
                    $vid = (int)$r['vehicle_id'];
                    $personen_stats[$mid]['fahrzeuge'][$vid] = ($personen_stats[$mid]['fahrzeuge'][$vid] ?? 0) + 1;
                }
                $von_t = $r['uhrzeit_von'] ?? null;
                $bis_t = $r['uhrzeit_bis'] ?? null;
                if ($von_t && $bis_t) {
                    $s = strtotime($r['datum'] . ' ' . $bis_t) - strtotime($r['datum'] . ' ' . $von_t);
                    if ($s > 0) $personen_stats[$mid]['stunden'] += $s / 3600;
                }
                $personen_letzte[$mid] = max($personen_letzte[$mid] ?? '', $r['datum']);
            }
            $params_af = [$von, $bis];
            $sql_af = "SELECT af.maschinist_member_id, af.einheitsfuehrer_member_id, a.typ AS liste_typ, a.bezeichnung, a.custom_data, d.typ AS dienst_typ FROM anwesenheitsliste_fahrzeuge af JOIN anwesenheitslisten a ON a.id = af.anwesenheitsliste_id LEFT JOIN dienstplan d ON d.id = a.dienstplan_id WHERE a.datum BETWEEN ? AND ?" . get_zeit_filter_sql($params_af) . get_typ_filter_sql() . get_beschreibung_filter_sql($params_af) . get_thema_filter_sql($params_af);
            $stmt = $db->prepare($sql_af);
            $stmt->execute($params_af);
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $einsatz = ist_einsatz($r);
                $jhv = ist_jhv_sonstiges($r);
                if ($typ_filter === 'einsaetze' && !$einsatz) continue;
                if ($typ_filter === 'uebungen' && ($einsatz || $jhv)) continue;
                if ($typ_filter === 'beides' && $jhv) continue;
                if ($typ_filter === 'jhv' && !ist_jhv($r)) continue;
                if ($typ_filter === 'sonstiges' && !ist_sonstiges($r)) continue;
                if (!empty($r['maschinist_member_id'])) {
                    $mid = (int)$r['maschinist_member_id'];
                    $personen_maschinist[$mid] = ($personen_maschinist[$mid] ?? 0) + 1;
                }
                if (!empty($r['einheitsfuehrer_member_id'])) {
                    $mid = (int)$r['einheitsfuehrer_member_id'];
                    $personen_einheitsfuehrer[$mid] = ($personen_einheitsfuehrer[$mid] ?? 0) + 1;
                }
            }
        } catch (Exception $e) {}
        $member_map = [];
        foreach ($members as $m) $member_map[(int)$m['id']] = $m;
        $vehicle_map = [];
        foreach ($vehicles as $v) $vehicle_map[(int)$v['id']] = $v['name'];
        $get_teilnahme_count = function($m) use ($personen_stats, $typ_filter) {
            $s = $personen_stats[(int)$m['id']] ?? [];
            if ($typ_filter === 'einsaetze') return $s['einsaetze'] ?? 0;
            if ($typ_filter === 'uebungen') return $s['uebungen'] ?? 0;
            if ($typ_filter === 'jhv') return $s['jhv'] ?? 0;
            if ($typ_filter === 'sonstiges') return $s['sonstiges'] ?? 0;
            return ($s['einsaetze'] ?? 0) + ($s['uebungen'] ?? 0);
        };
        usort($members, function($a,$b) use ($get_teilnahme_count) {
            return $get_teilnahme_count($b) - $get_teilnahme_count($a);
        });
        if ($member_id > 0) {
            $members = array_values(array_filter($members, fn($m) => (int)$m['id'] === $member_id));
        }
        $members_mit_teilnahme = array_values(array_filter($members, fn($m) => $get_teilnahme_count($m) > 0));
        $basis_fuer_prozent = $typ_filter === 'einsaetze' ? $anzahl_einsaetze : ($typ_filter === 'uebungen' ? $anzahl_uebungen : ($typ_filter === 'jhv' || $typ_filter === 'sonstiges' ? $anzahl_jhv_sonstiges : $anzahl_gesamt));
        $chart_person_fahrzeuge = [];
        $chart_person_rollen = [];
        $chart_personen_top = [];
        $chart_personen_einsatz_uebung = ['Einsätze' => 0, 'Übungen' => 0];
        foreach ($members_mit_teilnahme as $m) {
            $mid = (int)$m['id'];
            $s = $personen_stats[$mid] ?? ['einsaetze' => 0, 'uebungen' => 0, 'jhv_sonstiges' => 0];
            $t = $get_teilnahme_count($m);
            $chart_personen_top[] = ['label' => $m['last_name'] . ', ' . $m['first_name'], 'count' => $t];
            if ($typ_filter !== 'jhv' && $typ_filter !== 'sonstiges') {
                $chart_personen_einsatz_uebung['Einsätze'] += $s['einsaetze'];
                $chart_personen_einsatz_uebung['Übungen'] += $s['uebungen'];
            }
        }
        usort($chart_personen_top, fn($a,$b) => $b['count'] - $a['count']);
        $chart_personen_top = array_slice($chart_personen_top, 0, 10);
        $chart_personen_einsatz_uebung = array_filter($chart_personen_einsatz_uebung);
        $gesamt_stunden_personen = 0;
        foreach ($personen_stats as $s) { $gesamt_stunden_personen += $s['stunden'] ?? 0; }
    ?>
    <h2 class="h5 mb-3"><i class="fas fa-users"></i> Personen</h2>

    <?php if ($member_id === 0 && !empty($members_mit_teilnahme)): ?>
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card">
                <div class="card-body">
                    <h6 class="card-subtitle text-muted">Gesamtstunden aller Personen</h6>
                    <p class="h4 mb-0"><?php echo number_format($gesamt_stunden_personen, 1, ',', '.'); ?> h</p>
                    <p class="small text-muted mb-0">Summe der Einsatz-/Übungsstunden aller Mitglieder</p>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($member_id > 0 && !empty($members_mit_teilnahme)): ?>
    <!-- Einzelperson-Profil -->
    <?php
    $m = $members_mit_teilnahme[0];
    $chart_person_fahrzeuge = [];
    $chart_person_rollen = [];
    $mid = (int)$m['id'];
    $s = $personen_stats[$mid] ?? ['einsaetze' => 0, 'uebungen' => 0, 'jhv_sonstiges' => 0, 'jhv' => 0, 'sonstiges' => 0, 'fahrzeuge' => [], 'stunden' => 0];
    $gesamt = ($typ_filter === 'jhv' || $typ_filter === 'sonstiges') ? (($typ_filter === 'jhv' ? ($s['jhv'] ?? 0) : ($s['sonstiges'] ?? 0))) : ($s['einsaetze'] + $s['uebungen']);
    $masch = $personen_maschinist[$mid] ?? 0;
    $ef = $personen_einheitsfuehrer[$mid] ?? 0;
    $letzte = $personen_letzte[$mid] ?? '-';
    if ($letzte !== '-') $letzte = date('d.m.Y', strtotime($letzte));
    $chart_person_fahrzeuge = [];
    arsort($s['fahrzeuge']);
    foreach (array_slice($s['fahrzeuge'], 0, 8) as $vid => $cnt) {
        $chart_person_fahrzeuge[] = ['label' => $vehicle_map[$vid] ?? 'ID'.$vid, 'count' => $cnt];
    }
    $chart_person_rollen = [];
    if ($masch > 0) $chart_person_rollen[] = ['label' => 'Als Maschinist', 'count' => $masch];
    if ($ef > 0) $chart_person_rollen[] = ['label' => 'Als Einheitsführer', 'count' => $ef];
    $teilnehmer = $gesamt - $masch - $ef;
    if ($teilnehmer > 0) $chart_person_rollen[] = ['label' => 'Als Teilnehmer', 'count' => $teilnehmer];
    ?>
    <div class="row mb-4">
        <div class="col-12">
            <div class="card border-primary">
                <div class="card-header bg-primary text-white">
                    <i class="fas fa-user"></i> <?php echo htmlspecialchars($m['last_name'] . ', ' . $m['first_name']); ?>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <div class="card bg-light h-100">
                                <div class="card-body text-center">
                                    <p class="text-muted mb-0 small">Gesamt-Teilnahmen</p>
                                    <p class="h2 mb-0 text-primary"><?php echo $gesamt; ?></p>
                                    <p class="small mb-0"><?php
                                        if ($typ_filter === 'einsaetze') echo (int)$s['einsaetze'] . ' Einsätze';
                                        elseif ($typ_filter === 'uebungen') echo (int)$s['uebungen'] . ' Übungen';
                                        elseif ($typ_filter === 'jhv') echo (int)($s['jhv'] ?? 0) . ' JHV';
                                        elseif ($typ_filter === 'sonstiges') echo (int)($s['sonstiges'] ?? 0) . ' Sonstiges';
                                        else { echo (int)$s['einsaetze'] . ' Einsätze, ' . (int)$s['uebungen'] . ' Übungen'; if (($s['jhv_sonstiges'] ?? 0) > 0) echo ', ' . (int)$s['jhv_sonstiges'] . ' JHV/Sonstiges'; }
                                    ?></p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card bg-light h-100">
                                <div class="card-body text-center">
                                    <p class="text-muted mb-0 small">Einsatzstunden</p>
                                    <p class="h2 mb-0 text-success"><?php echo number_format($s['stunden'], 1, ',', '.'); ?> h</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card bg-light h-100">
                                <div class="card-body text-center">
                                    <p class="text-muted mb-0 small">Als Maschinist / EF</p>
                                    <p class="h2 mb-0 text-warning"><?php echo $masch; ?> / <?php echo $ef; ?></p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card bg-light h-100">
                                <div class="card-body text-center">
                                    <p class="text-muted mb-0 small">Letzte Teilnahme</p>
                                    <p class="h4 mb-0"><?php echo htmlspecialchars($letzte); ?></p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php if (($ansicht === 'diagramme' || $ansicht === 'alle') && (!empty($chart_person_fahrzeuge) || !empty($chart_person_rollen))): ?>
    <div class="row mb-4">
        <?php if (!empty($chart_person_fahrzeuge)): ?>
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">Fahrzeugverteilung</div>
                <div class="card-body"><canvas id="chartPersonFahrzeuge" height="220"></canvas></div>
            </div>
        </div>
        <?php endif; ?>
        <?php if (!empty($chart_person_rollen)): ?>
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">Rollenverteilung</div>
                <div class="card-body"><canvas id="chartPersonRollen" height="220"></canvas></div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php if (!empty($s['fahrzeuge'])): ?>
    <div class="card mb-4">
        <div class="card-header">Fahrzeuge im Detail</div>
        <div class="card-body">
            <?php foreach ($s['fahrzeuge'] as $vid => $cnt): ?>
            <span class="badge bg-secondary me-1 mb-1"><?php echo htmlspecialchars($vehicle_map[$vid] ?? 'ID'.$vid); ?>: <?php echo $cnt; ?>×</span>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php elseif (empty($members_mit_teilnahme)): ?>
    <p class="text-muted">Keine Teilnahmen im gewählten Zeitraum<?php if ($member_id > 0): ?> für dieses Mitglied<?php endif; ?>.</p>
    <?php else: ?>

    <?php if (($ansicht === 'diagramme' || $ansicht === 'alle') && (!empty($chart_personen_top) || !empty($chart_personen_einsatz_uebung))): ?>
    <div class="row mb-4">
        <?php if (!empty($chart_personen_einsatz_uebung)): ?>
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">Einsätze vs. Übungen (Gesamt)</div>
                <div class="card-body"><canvas id="chartPersonenEinsatzUebung" height="220"></canvas></div>
            </div>
        </div>
        <?php endif; ?>
        <?php if (!empty($chart_personen_top)): ?>
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">Top 10 Teilnehmer</div>
                <div class="card-body" style="min-height: 380px;"><canvas id="chartPersonenTop" height="360"></canvas></div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($ansicht === 'karten' || $ansicht === 'alle'): ?>
    <div class="row mb-4">
        <?php foreach (array_slice($members_mit_teilnahme, 0, 12) as $m):
            $mid = (int)$m['id'];
            $s = $personen_stats[$mid] ?? ['einsaetze' => 0, 'uebungen' => 0, 'jhv_sonstiges' => 0, 'stunden' => 0];
            $t = ($typ_filter === 'jhv' || $typ_filter === 'sonstiges') ? (($typ_filter === 'jhv' ? ($s['jhv'] ?? 0) : ($s['sonstiges'] ?? 0))) : ($s['einsaetze'] + $s['uebungen']);
            $person_count = $typ_filter === 'einsaetze' ? $s['einsaetze'] : ($typ_filter === 'uebungen' ? $s['uebungen'] : ($typ_filter === 'jhv' || $typ_filter === 'sonstiges' ? $t : $t));
            $prozent = $basis_fuer_prozent > 0 ? round($person_count / $basis_fuer_prozent * 100, 1) : 0;
        ?>
        <div class="col-md-4 col-lg-3 mb-3">
            <div class="card h-100 auswertung-karte">
                <div class="card-body py-3">
                    <h6 class="card-title mb-1"><?php echo htmlspecialchars($m['last_name'] . ', ' . $m['first_name']); ?></h6>
                    <p class="mb-0 small text-muted"><?php echo $t; ?> Teilnahmen <span class="text-primary">(<?php echo $prozent; ?>%)</span></p>
                    <p class="mb-0 small"><?php
                        if ($typ_filter === 'einsaetze') echo (int)$s['einsaetze'] . ' E';
                        elseif ($typ_filter === 'uebungen') echo (int)$s['uebungen'] . ' Ü';
                        elseif ($typ_filter === 'jhv') echo (int)($s['jhv'] ?? 0) . ' JHV';
                        elseif ($typ_filter === 'sonstiges') echo (int)($s['sonstiges'] ?? 0) . ' Sonstiges';
                        else echo (int)$s['einsaetze'] . ' E / ' . (int)$s['uebungen'] . ' Ü';
                        ?> · <?php echo number_format($s['stunden'], 1, ',', '.'); ?> h</p>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($ansicht === 'tabelle' || $ansicht === 'alle'): ?>
    <?php if ($basis_fuer_prozent > 0): ?>
    <p class="text-muted small mb-2"><i class="fas fa-info-circle"></i> Anteil: 100% = Teilnahme an allen <?php echo $basis_fuer_prozent; ?> <?php echo $typ_filter === 'einsaetze' ? 'Einsätzen' : ($typ_filter === 'uebungen' ? 'Übungen' : ($typ_filter === 'jhv' ? 'JHV' : ($typ_filter === 'sonstiges' ? 'Sonstiges' : 'Einsätzen/Übungen'))); ?> im Zeitraum</p>
    <?php endif; ?>
    <div class="table-responsive">
        <table class="table table-sm table-hover">
            <thead>
                <tr>
                    <th>Mitglied</th>
                    <?php if ($typ_filter !== 'jhv' && $typ_filter !== 'sonstiges'): ?>
                    <th class="text-end">Einsätze</th>
                    <th class="text-end">Übungen</th>
                    <?php else: ?>
                    <th class="text-end"><?php echo $typ_filter === 'jhv' ? 'JHV' : 'Sonstiges'; ?></th>
                    <?php endif; ?>
                    <th class="text-end">Gesamt</th>
                    <th class="text-end">Anteil</th>
                    <th class="text-end">Als Maschinist</th>
                    <th class="text-end">Als Einheitsführer</th>
                    <th>Letzte Teilnahme</th>
                    <th class="text-end">Stunden</th>
                    <th>Fahrzeuge</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($members_mit_teilnahme as $m):
                    $mid = (int)$m['id'];
                    $s = $personen_stats[$mid] ?? ['einsaetze' => 0, 'uebungen' => 0, 'jhv_sonstiges' => 0, 'fahrzeuge' => [], 'stunden' => 0];
                    $gesamt = ($typ_filter === 'jhv' || $typ_filter === 'sonstiges') ? (($typ_filter === 'jhv' ? ($s['jhv'] ?? 0) : ($s['sonstiges'] ?? 0))) : ($s['einsaetze'] + $s['uebungen']);
                    $person_count = $typ_filter === 'einsaetze' ? $s['einsaetze'] : ($typ_filter === 'uebungen' ? $s['uebungen'] : ($typ_filter === 'jhv' || $typ_filter === 'sonstiges' ? $gesamt : $gesamt));
                    $prozent = $basis_fuer_prozent > 0 ? number_format($person_count / $basis_fuer_prozent * 100, 1, ',', '.') : '0';
                    $masch = $personen_maschinist[$mid] ?? 0;
                    $ef = $personen_einheitsfuehrer[$mid] ?? 0;
                    $letzte = $personen_letzte[$mid] ?? '-';
                    if ($letzte !== '-') $letzte = date('d.m.Y', strtotime($letzte));
                    $fahrzeuge_str = [];
                    arsort($s['fahrzeuge']);
                    foreach (array_slice($s['fahrzeuge'], 0, 5) as $vid => $cnt) {
                        $fahrzeuge_str[] = ($vehicle_map[$vid] ?? 'ID'.$vid) . ' (' . $cnt . ')';
                    }
                ?>
                <tr>
                    <td><?php echo htmlspecialchars($m['last_name'] . ', ' . $m['first_name']); ?></td>
                    <?php if ($typ_filter !== 'jhv' && $typ_filter !== 'sonstiges'): ?>
                    <td class="text-end"><?php echo (int)$s['einsaetze']; ?></td>
                    <td class="text-end"><?php echo (int)$s['uebungen']; ?></td>
                    <?php else: ?>
                    <td class="text-end"><?php echo (int)($typ_filter === 'jhv' ? ($s['jhv'] ?? 0) : ($s['sonstiges'] ?? 0)); ?></td>
                    <?php endif; ?>
                    <td class="text-end"><strong><?php echo $gesamt; ?></strong></td>
                    <td class="text-end"><span class="badge bg-primary"><?php echo $prozent; ?>%</span></td>
                    <td class="text-end"><?php echo $masch; ?></td>
                    <td class="text-end"><?php echo $ef; ?></td>
                    <td><?php echo htmlspecialchars($letzte); ?></td>
                    <td class="text-end"><?php echo number_format($s['stunden'], 1, ',', '.'); ?> h</td>
                    <td><?php echo htmlspecialchars(implode(', ', $fahrzeuge_str)); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <?php
    // ==================== BEREICH EINSÄTZE / DIENSTE ====================
    elseif ($bereich === 'einsaetze'):
        $listen = [];
        $klassifizierung = [];
        $einsatzstichwort = [];
        $themen = [];
        $dauern = [];
        try {
            $params_listen = [$von, $bis];
            $sql_listen = "SELECT a.id, a.datum, a.typ AS liste_typ, a.bezeichnung, a.custom_data, a.uhrzeit_von, a.uhrzeit_bis, a.klassifizierung, a.einsatzstichwort, d.typ AS dienst_typ, d.bezeichnung AS dienst_bezeichnung FROM anwesenheitslisten a LEFT JOIN dienstplan d ON d.id = a.dienstplan_id WHERE a.datum BETWEEN ? AND ?" . get_zeit_filter_sql($params_listen) . get_typ_filter_sql() . get_beschreibung_filter_sql($params_listen) . get_thema_filter_sql($params_listen);
            $stmt = $db->prepare($sql_listen);
            $stmt->execute($params_listen);
            $listen = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($listen as $a) {
                $einsatz = ($a['liste_typ'] === 'einsatz') || ($a['liste_typ'] === 'dienst' && ($a['dienst_typ'] ?? '') === 'einsatz');
                $jhv = ist_jhv_sonstiges($a);
                if ($typ_filter === 'einsaetze' && !$einsatz) continue;
                if ($typ_filter === 'uebungen' && ($einsatz || $jhv)) continue;
                if ($typ_filter === 'beides' && $jhv) continue;
                if ($typ_filter === 'jhv' && !ist_jhv($a)) continue;
                if ($typ_filter === 'sonstiges' && !ist_sonstiges($a)) continue;
                if ($einsatz) {
                    $k = trim($a['klassifizierung'] ?? '') ?: '-';
                    $klassifizierung[$k] = ($klassifizierung[$k] ?? 0) + 1;
                    $st = trim($a['einsatzstichwort'] ?? '') ?: '-';
                    $einsatzstichwort[$st] = ($einsatzstichwort[$st] ?? 0) + 1;
                } else {
                    $thema = trim($a['bezeichnung'] ?? $a['dienst_bezeichnung'] ?? '') ?: '-';
                    $themen[$thema] = ($themen[$thema] ?? 0) + 1;
                }
                if (!empty($a['uhrzeit_von']) && !empty($a['uhrzeit_bis'])) {
                    $s = strtotime($a['datum'] . ' ' . $a['uhrzeit_bis']) - strtotime($a['datum'] . ' ' . $a['uhrzeit_von']);
                    if ($s > 0) $dauern[] = $s / 3600;
                }
            }
            $listen = array_filter($listen, function($a) use ($typ_filter) {
                $einsatz = ($a['liste_typ'] === 'einsatz') || ($a['liste_typ'] === 'dienst' && ($a['dienst_typ'] ?? '') === 'einsatz');
                $jhv = ist_jhv_sonstiges($a);
                if ($typ_filter === 'einsaetze' && !$einsatz) return false;
                if ($typ_filter === 'uebungen' && ($einsatz || $jhv)) return false;
                if ($typ_filter === 'beides' && $jhv) return false;
                if ($typ_filter === 'jhv' && !ist_jhv($a)) return false;
                if ($typ_filter === 'sonstiges' && !ist_sonstiges($a)) return false;
                return true;
            });
        } catch (Exception $e) {}
        $personen_pro_liste = [];
        $gf_pro_liste = [];
        $zf_pro_liste = [];
        foreach ($listen as $a) {
            $lid = (int)$a['id'];
            $stmt = $db->prepare("SELECT COUNT(*) FROM anwesenheitsliste_mitglieder WHERE anwesenheitsliste_id = ?");
            $stmt->execute([$lid]);
            $personen_pro_liste[$lid] = (int)$stmt->fetchColumn();
            $stmt = $db->prepare("
                SELECT COUNT(*) FROM anwesenheitsliste_mitglieder am
                JOIN members m ON m.id = am.member_id
                LEFT JOIN member_qualifications q ON q.id = m.qualification_id
                WHERE am.anwesenheitsliste_id = ?
                AND LOWER(COALESCE(q.name,'')) LIKE '%gruppenführer%'
            ");
            $stmt->execute([$lid]);
            $gf_pro_liste[$lid] = (int)$stmt->fetchColumn();
            $stmt = $db->prepare("
                SELECT COUNT(*) FROM anwesenheitsliste_mitglieder am
                JOIN members m ON m.id = am.member_id
                LEFT JOIN member_qualifications q ON q.id = m.qualification_id
                WHERE am.anwesenheitsliste_id = ?
                AND LOWER(COALESCE(q.name,'')) LIKE '%zugführer%'
            ");
            $stmt->execute([$lid]);
            $zf_pro_liste[$lid] = (int)$stmt->fetchColumn();
        }
        $anzahl_listen = count($listen);
        $gesamt_stunden_listen = array_sum($dauern);
        $durchschn_personen = count($personen_pro_liste) > 0 ? array_sum($personen_pro_liste) / count($personen_pro_liste) : 0;
        $durchschn_gf = count($gf_pro_liste) > 0 ? array_sum($gf_pro_liste) / count($gf_pro_liste) : 0;
        $durchschn_zf = count($zf_pro_liste) > 0 ? array_sum($zf_pro_liste) / count($zf_pro_liste) : 0;
        $durchschn_dauer = count($dauern) > 0 ? array_sum($dauern) / count($dauern) : 0;
        arsort($themen);
        $top_themen = array_slice($themen, 0, 15);
    ?>
    <h2 class="h5 mb-3"><i class="fas fa-clipboard-list"></i> Einsätze / Dienste</h2>
    <div class="row mb-4">
        <div class="col-6 col-md-2">
            <div class="card">
                <div class="card-body">
                    <h6 class="card-subtitle text-muted">Anzahl Einsätze/Übungen</h6>
                    <p class="h4 mb-0"><?php echo $anzahl_listen; ?></p>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card">
                <div class="card-body">
                    <h6 class="card-subtitle text-muted">Gesamtstunden Einsätze/Übungen</h6>
                    <p class="h4 mb-0"><?php echo number_format($gesamt_stunden_listen, 1, ',', '.'); ?> h</p>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card">
                <div class="card-body">
                    <h6 class="card-subtitle text-muted">Durchschn. Personen pro Einsatz/Übung</h6>
                    <p class="h4 mb-0"><?php echo number_format($durchschn_personen, 1, ',', '.'); ?></p>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card">
                <div class="card-body">
                    <h6 class="card-subtitle text-muted">Durchschn. Gruppenführer pro Einsatz/Übung</h6>
                    <p class="h4 mb-0"><?php echo number_format($durchschn_gf, 1, ',', '.'); ?></p>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card">
                <div class="card-body">
                    <h6 class="card-subtitle text-muted">Durchschn. Zugführer pro Einsatz/Übung</h6>
                    <p class="h4 mb-0"><?php echo number_format($durchschn_zf, 1, ',', '.'); ?></p>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card">
                <div class="card-body">
                    <h6 class="card-subtitle text-muted">Durchschn. Einsatzdauer</h6>
                    <p class="h4 mb-0"><?php echo number_format($durchschn_dauer, 1, ',', '.'); ?> h</p>
                </div>
            </div>
        </div>
    </div>
    <?php
        $sum_klass = array_sum($klassifizierung ?: []);
        $sum_stich = array_sum($einsatzstichwort ?: []);
        $sum_themen = array_sum($top_themen ?: []);
        $chart_klass = [];
        foreach ($klassifizierung ?: [] as $k => $v) {
            $chart_klass[] = ['label' => $k, 'count' => $v, 'pct' => $sum_klass > 0 ? round($v/$sum_klass*100, 1) : 0];
        }
        $chart_stich = [];
        foreach ($einsatzstichwort ?: [] as $k => $v) {
            $chart_stich[] = ['label' => $k, 'count' => $v, 'pct' => $sum_stich > 0 ? round($v/$sum_stich*100, 1) : 0];
        }
        $chart_themen = [];
        foreach ($top_themen ?: [] as $k => $v) {
            $chart_themen[] = ['label' => $k, 'count' => $v, 'pct' => $sum_themen > 0 ? round($v/$sum_themen*100, 1) : 0];
        }
    ?>
    <div class="row">
    <?php if (($ansicht === 'diagramme' || $ansicht === 'alle') && (!empty($chart_klass) || !empty($chart_stich) || !empty($chart_themen))): ?>
    <div class="row mb-4">
        <?php if (!empty($chart_klass)): ?>
        <div class="col-md-4">
            <div class="card">
                <div class="card-header">Klassifizierung</div>
                <div class="card-body"><canvas id="chartEinsaetzeKlass" height="220"></canvas></div>
            </div>
        </div>
        <?php endif; ?>
        <?php if (!empty($chart_stich)): ?>
        <div class="col-md-4">
            <div class="card">
                <div class="card-header">Einsatzstichwort</div>
                <div class="card-body"><canvas id="chartEinsaetzeStich" height="220"></canvas></div>
            </div>
        </div>
        <?php endif; ?>
        <?php if (!empty($chart_themen)): ?>
        <div class="col-md-4">
            <div class="card">
                <div class="card-header">Top-Themen Übungen</div>
                <div class="card-body"><canvas id="chartEinsaetzeThemen" height="220"></canvas></div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <div class="row">
        <div class="col-md-6 mb-4">
            <div class="card">
                <div class="card-header">Klassifizierung</div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead><tr><th>Klassifizierung</th><th class="text-end">Anzahl</th><th class="text-end">Anteil</th></tr></thead>
                            <tbody>
                                <?php arsort($klassifizierung); foreach ($klassifizierung as $k => $v): 
                                    $pct = $sum_klass > 0 ? number_format($v/$sum_klass*100, 1, ',', '.') : '0';
                                ?>
                                <tr><td><?php echo htmlspecialchars($k); ?></td><td class="text-end"><?php echo $v; ?></td><td class="text-end"><span class="badge bg-secondary"><?php echo $pct; ?>%</span></td></tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6 mb-4">
            <div class="card">
                <div class="card-header">Einsatzstichwort</div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead><tr><th>Stichwort</th><th class="text-end">Anzahl</th><th class="text-end">Anteil</th></tr></thead>
                            <tbody>
                                <?php arsort($einsatzstichwort); foreach ($einsatzstichwort as $k => $v): 
                                    $pct = $sum_stich > 0 ? number_format($v/$sum_stich*100, 1, ',', '.') : '0';
                                ?>
                                <tr><td><?php echo htmlspecialchars($k); ?></td><td class="text-end"><?php echo $v; ?></td><td class="text-end"><span class="badge bg-secondary"><?php echo $pct; ?>%</span></td></tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="card mb-4">
        <div class="card-header">Top-Themen <?php echo ($typ_filter === 'jhv' || $typ_filter === 'sonstiges') ? '(' . ($typ_filter === 'jhv' ? 'JHV' : 'Sonstiges') . ')' : 'bei Übungsdiensten'; ?></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Thema / Bezeichnung</th><th class="text-end">Anzahl</th><th class="text-end">Anteil</th></tr></thead>
                    <tbody>
                        <?php foreach ($top_themen as $k => $v): 
                            $pct = $sum_themen > 0 ? number_format($v/$sum_themen*100, 1, ',', '.') : '0';
                        ?>
                        <tr><td><?php echo htmlspecialchars($k); ?></td><td class="text-end"><?php echo $v; ?></td><td class="text-end"><span class="badge bg-secondary"><?php echo $pct; ?>%</span></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php
    // ==================== BEREICH FAHRZEUGE ====================
    elseif ($bereich === 'fahrzeuge'):
        $fahrzeug_stats = [];
        $fahrzeug_besatzung = [];
        $fahrzeug_maschinist = [];
        $fahrzeug_ef = [];
        try {
            $sql_f = "SELECT af.vehicle_id, af.maschinist_member_id, af.einheitsfuehrer_member_id, a.id AS liste_id, a.typ AS liste_typ, a.bezeichnung, a.custom_data, d.typ AS dienst_typ FROM anwesenheitsliste_fahrzeuge af JOIN anwesenheitslisten a ON a.id = af.anwesenheitsliste_id LEFT JOIN dienstplan d ON d.id = a.dienstplan_id WHERE a.datum BETWEEN ? AND ?";
            $params_f = [$von, $bis];
            $sql_f .= get_zeit_filter_sql($params_f) . get_typ_filter_sql() . get_beschreibung_filter_sql($params_f) . get_thema_filter_sql($params_f);
            if ($vehicle_id > 0) {
                $sql_f .= " AND af.vehicle_id = ?";
                $params_f[] = $vehicle_id;
            }
            $stmt = $db->prepare($sql_f);
            $stmt->execute($params_f);
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $einsatz = ($r['liste_typ'] === 'einsatz') || ($r['liste_typ'] === 'dienst' && ($r['dienst_typ'] ?? '') === 'einsatz');
                $jhv = ist_jhv_sonstiges($r);
                if ($typ_filter === 'einsaetze' && !$einsatz) continue;
                if ($typ_filter === 'uebungen' && ($einsatz || $jhv)) continue;
                if ($typ_filter === 'beides' && $jhv) continue;
                if ($typ_filter === 'jhv' && !ist_jhv($r)) continue;
                if ($typ_filter === 'sonstiges' && !ist_sonstiges($r)) continue;
                $vid = (int)$r['vehicle_id'];
                $fahrzeug_stats[$vid] = $fahrzeug_stats[$vid] ?? ['einsaetze' => 0, 'uebungen' => 0];
                if ($einsatz) $fahrzeug_stats[$vid]['einsaetze']++;
                else $fahrzeug_stats[$vid]['uebungen']++;
                $lid = (int)$r['liste_id'];
                $fahrzeug_besatzung[$vid][] = $lid;
                if (!empty($r['maschinist_member_id'])) {
                    $mid = (int)$r['maschinist_member_id'];
                    $fahrzeug_maschinist[$vid][$mid] = ($fahrzeug_maschinist[$vid][$mid] ?? 0) + 1;
                }
                if (!empty($r['einheitsfuehrer_member_id'])) {
                    $mid = (int)$r['einheitsfuehrer_member_id'];
                    $fahrzeug_ef[$vid][$mid] = ($fahrzeug_ef[$vid][$mid] ?? 0) + 1;
                }
            }
            foreach ($fahrzeug_besatzung as $vid => $liste_ids) {
                $anz = 0;
                $liste_ids = array_unique($liste_ids);
                foreach ($liste_ids as $lid) {
                    $stmt = $db->prepare("SELECT COUNT(*) FROM anwesenheitsliste_mitglieder WHERE anwesenheitsliste_id = ? AND vehicle_id = ?");
                    $stmt->execute([$lid, $vid]);
                    $anz += (int)$stmt->fetchColumn();
                }
                $fahrzeug_besatzung[$vid] = count($liste_ids) > 0 ? $anz / count($liste_ids) : 0;
            }
        } catch (Exception $e) {}
        $vehicle_map = [];
        foreach ($vehicles as $v) $vehicle_map[(int)$v['id']] = $v['name'];
        $member_map = [];
        foreach ($members as $m) $member_map[(int)$m['id']] = $m['last_name'] . ', ' . $m['first_name'];
        $sorted_vids = array_keys($fahrzeug_stats);
        usort($sorted_vids, function($a,$b) use ($fahrzeug_stats) {
            $ta = ($fahrzeug_stats[$a]['einsaetze'] ?? 0) + ($fahrzeug_stats[$a]['uebungen'] ?? 0);
            $tb = ($fahrzeug_stats[$b]['einsaetze'] ?? 0) + ($fahrzeug_stats[$b]['uebungen'] ?? 0);
            return $tb - $ta;
        });
        $gesamt_fahrzeuge = 0;
        foreach ($sorted_vids as $vid) {
            $gesamt_fahrzeuge += ($fahrzeug_stats[$vid]['einsaetze'] ?? 0) + ($fahrzeug_stats[$vid]['uebungen'] ?? 0);
        }
        $chart_fahrzeuge = [];
        $chart_fahrzeuge_einsatz_uebung = ['Einsätze' => 0, 'Übungen' => 0];
        foreach ($sorted_vids as $vid) {
            $s = $fahrzeug_stats[$vid];
            $g = $s['einsaetze'] + $s['uebungen'];
            $chart_fahrzeuge[] = ['label' => $vehicle_map[$vid] ?? 'ID'.$vid, 'count' => $g];
            $chart_fahrzeuge_einsatz_uebung['Einsätze'] += $s['einsaetze'];
            $chart_fahrzeuge_einsatz_uebung['Übungen'] += $s['uebungen'];
        }
        $chart_fahrzeuge = array_slice($chart_fahrzeuge, 0, 12);
        $chart_fahrzeuge_einsatz_uebung = array_filter($chart_fahrzeuge_einsatz_uebung);
    ?>
    <h2 class="h5 mb-3"><i class="fas fa-truck"></i> Fahrzeuge</h2>
    <?php if (($ansicht === 'diagramme' || $ansicht === 'alle') && (!empty($chart_fahrzeuge) || !empty($chart_fahrzeuge_einsatz_uebung))): ?>
    <div class="row mb-4">
        <?php if (!empty($chart_fahrzeuge_einsatz_uebung)): ?>
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">Einsätze vs. Übungen (Fahrzeuge gesamt)</div>
                <div class="card-body"><canvas id="chartFahrzeugeEinsatzUebung" height="220"></canvas></div>
            </div>
        </div>
        <?php endif; ?>
        <?php if (!empty($chart_fahrzeuge)): ?>
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">Einsätze pro Fahrzeug</div>
                <div class="card-body"><canvas id="chartFahrzeugeTop" height="220"></canvas></div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <div class="table-responsive">
        <table class="table table-sm table-hover">
            <thead>
                <tr>
                    <th>Fahrzeug</th>
                    <th class="text-end">Einsätze</th>
                    <th class="text-end">Übungen</th>
                    <th class="text-end">Gesamt</th>
                    <th class="text-end">Anteil</th>
                    <th class="text-end">Durchschn. Besatzung</th>
                    <th>Häufigste Maschinisten</th>
                    <th>Häufigste Einheitsführer</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($sorted_vids as $vid):
                    $s = $fahrzeug_stats[$vid];
                    $gesamt = $s['einsaetze'] + $s['uebungen'];
                    $prozent = $gesamt_fahrzeuge > 0 ? number_format($gesamt / $gesamt_fahrzeuge * 100, 1, ',', '.') : '0';
                    $besatz = $fahrzeug_besatzung[$vid] ?? 0;
                    $masch_list = $fahrzeug_maschinist[$vid] ?? [];
                    $ef_list = $fahrzeug_ef[$vid] ?? [];
                    arsort($masch_list);
                    arsort($ef_list);
                    $masch_str = [];
                    foreach (array_slice($masch_list, 0, 3) as $mid => $cnt) {
                        $masch_str[] = ($member_map[$mid] ?? 'ID'.$mid) . ' (' . $cnt . ')';
                    }
                    $ef_str = [];
                    foreach (array_slice($ef_list, 0, 3) as $mid => $cnt) {
                        $ef_str[] = ($member_map[$mid] ?? 'ID'.$mid) . ' (' . $cnt . ')';
                    }
                ?>
                <tr>
                    <td><?php echo htmlspecialchars($vehicle_map[$vid] ?? 'ID'.$vid); ?></td>
                    <td class="text-end"><?php echo (int)$s['einsaetze']; ?></td>
                    <td class="text-end"><?php echo (int)$s['uebungen']; ?></td>
                    <td class="text-end"><strong><?php echo $gesamt; ?></strong></td>
                    <td class="text-end"><span class="badge bg-warning text-dark"><?php echo $prozent; ?>%</span></td>
                    <td class="text-end"><?php echo number_format($besatz, 1, ',', '.'); ?></td>
                    <td><?php echo htmlspecialchars(implode(', ', $masch_str) ?: '-'); ?></td>
                    <td><?php echo htmlspecialchars(implode(', ', $ef_str) ?: '-'); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php
    // ==================== BEREICH GERÄTE ====================
    elseif ($bereich === 'geraete'):
        $geraete_count = [];
        try {
            $params_ger = [$von, $bis];
            $sql_ger = "SELECT a.id, a.typ AS liste_typ, a.bezeichnung, a.custom_data, d.typ AS dienst_typ FROM anwesenheitslisten a LEFT JOIN dienstplan d ON d.id = a.dienstplan_id WHERE a.datum BETWEEN ? AND ? AND a.custom_data IS NOT NULL AND a.custom_data != '' AND a.custom_data != 'null'" . get_zeit_filter_sql($params_ger) . get_typ_filter_sql() . get_beschreibung_filter_sql($params_ger) . get_thema_filter_sql($params_ger);
            $stmt = $db->prepare($sql_ger);
            $stmt->execute($params_ger);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $einsatz = ($row['liste_typ'] ?? '') === 'einsatz' || (($row['dienst_typ'] ?? '') === 'einsatz');
                $jhv = ist_jhv_sonstiges($row);
                if ($typ_filter === 'einsaetze' && !$einsatz) continue;
                if ($typ_filter === 'uebungen' && ($einsatz || $jhv)) continue;
                if ($typ_filter === 'beides' && $jhv) continue;
                if ($typ_filter === 'jhv' && !ist_jhv($row)) continue;
                if ($typ_filter === 'sonstiges' && !ist_sonstiges($row)) continue;
                $dec = is_string($row['custom_data']) ? json_decode($row['custom_data'], true) : $row['custom_data'];
                if (!is_array($dec)) continue;
                $veq = $dec['vehicle_equipment'] ?? [];
                if (!is_array($veq)) continue;
                foreach ($veq as $vid => $eq_ids) {
                    if (!is_array($eq_ids)) continue;
                    foreach ($eq_ids as $eqid) {
                        $eqid = (int)$eqid;
                        if ($eqid > 0) $geraete_count[$eqid] = ($geraete_count[$eqid] ?? 0) + 1;
                    }
                }
            }
            arsort($geraete_count);
        } catch (Exception $e) {}
        $geraete_names = [];
        if (!empty($geraete_count)) {
            $ph = implode(',', array_fill(0, count($geraete_count), '?'));
            $stmt = $db->prepare("SELECT id, name, vehicle_id FROM vehicle_equipment WHERE id IN ($ph)");
            $stmt->execute(array_keys($geraete_count));
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $geraete_names[(int)$r['id']] = ['name' => $r['name'], 'vehicle_id' => (int)$r['vehicle_id']];
            }
        }
        $vehicle_map = [];
        foreach ($vehicles as $v) $vehicle_map[(int)$v['id']] = $v['name'];
        $gesamt_geraete = array_sum($geraete_count);
        $chart_geraete = [];
        foreach (array_slice($geraete_count, 0, 12, true) as $eqid => $cnt) {
            $info = $geraete_names[$eqid] ?? ['name' => 'ID'.$eqid];
            $chart_geraete[] = ['label' => $info['name'], 'count' => $cnt];
        }
    ?>
    <h2 class="h5 mb-3"><i class="fas fa-wrench"></i> Geräte – Einsatzhäufigkeit</h2>
    <?php if (!empty($geraete_count) && ($ansicht === 'diagramme' || $ansicht === 'alle') && !empty($chart_geraete)): ?>
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">Top Geräte (Kuchen)</div>
                <div class="card-body"><canvas id="chartGeraeteKuchen" height="220"></canvas></div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">Top Geräte (Balken)</div>
                <div class="card-body"><canvas id="chartGeraeteBalken" height="220"></canvas></div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <div class="card">
        <div class="card-body p-0">
            <?php if (empty($geraete_count)): ?>
            <p class="text-muted p-3 mb-0">Keine Geräte-Einsätze im gewählten Zeitraum (vehicle_equipment in custom_data).</p>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Gerät</th>
                            <th>Fahrzeug</th>
                            <th class="text-end">Einsatzanzahl</th>
                            <th class="text-end">Anteil</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($geraete_count as $eqid => $cnt):
                            $info = $geraete_names[$eqid] ?? ['name' => 'ID'.$eqid, 'vehicle_id' => 0];
                            $prozent = $gesamt_geraete > 0 ? number_format($cnt / $gesamt_geraete * 100, 1, ',', '.') : '0';
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($info['name']); ?></td>
                            <td><?php echo htmlspecialchars($vehicle_map[$info['vehicle_id']] ?? '-'); ?></td>
                            <td class="text-end"><?php echo $cnt; ?></td>
                            <td class="text-end"><span class="badge bg-info"><?php echo $prozent; ?>%</span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<style>
.hover-shadow:hover { box-shadow: 0 .5rem 1rem rgba(0,0,0,.15); }
.auswertung-karte { transition: transform 0.2s, box-shadow 0.2s; }
.auswertung-karte:hover { transform: translateY(-2px); box-shadow: 0 .25rem .5rem rgba(0,0,0,.1); }
</style>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function() {
    var colors = ['#0d6efd','#198754','#ffc107','#dc3545','#6f42c1','#0dcaf0','#fd7e14','#20c997','#e83e8c','#6c757d'];
    function getColor(i) { return colors[i % colors.length]; }
    function makeDoughnut(canvasId, labels, data) {
        var el = document.getElementById(canvasId);
        if (!el || !labels.length) return;
        new Chart(el, {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{ data: data, backgroundColor: labels.map(function(_,i){ return getColor(i); }) }]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'right' } } }
        });
    }
    function makeBar(canvasId, labels, data, horizontal, opts) {
        opts = opts || {};
        var el = document.getElementById(canvasId);
        if (!el || !labels.length) return;
        var ds = { label: 'Anzahl', data: data, backgroundColor: labels.map(function(_,i){ return getColor(i); }) };
        if (opts.barThickness) ds.barThickness = opts.barThickness;
        var scales = horizontal ? {
            x: { beginAtZero: true },
            y: { ticks: { font: { size: 11 }, maxRotation: 0, autoSkip: false } }
        } : { y: { beginAtZero: true } };
        new Chart(el, {
            type: 'bar',
            data: { labels: labels, datasets: [ds] },
            options: {
                indexAxis: horizontal ? 'y' : 'x',
                responsive: true, maintainAspectRatio: false,
                scales: scales,
                plugins: { legend: { display: false } }
            }
        });
    }

    <?php if ($bereich === 'personen'): ?>
    <?php if ($member_id > 0 && !empty($chart_person_fahrzeuge)): ?>
    makeDoughnut('chartPersonFahrzeuge', <?php echo json_encode(array_column($chart_person_fahrzeuge, 'label')); ?>, <?php echo json_encode(array_column($chart_person_fahrzeuge, 'count')); ?>);
    <?php endif; ?>
    <?php if ($member_id > 0 && !empty($chart_person_rollen)): ?>
    makeDoughnut('chartPersonRollen', <?php echo json_encode(array_column($chart_person_rollen, 'label')); ?>, <?php echo json_encode(array_column($chart_person_rollen, 'count')); ?>);
    <?php endif; ?>
    <?php if ($member_id === 0 && !empty($chart_personen_einsatz_uebung)): ?>
    makeDoughnut('chartPersonenEinsatzUebung', <?php echo json_encode(array_keys($chart_personen_einsatz_uebung)); ?>, <?php echo json_encode(array_values($chart_personen_einsatz_uebung)); ?>);
    <?php endif; ?>
    <?php if ($member_id === 0 && !empty($chart_personen_top)): ?>
    makeBar('chartPersonenTop', <?php echo json_encode(array_column($chart_personen_top, 'label')); ?>, <?php echo json_encode(array_column($chart_personen_top, 'count')); ?>, true, { barThickness: 28 });
    <?php endif; ?>
    <?php endif; ?>

    <?php if ($bereich === 'einsaetze'): ?>
    <?php if (!empty($chart_klass)): ?>
    makeDoughnut('chartEinsaetzeKlass', <?php echo json_encode(array_column($chart_klass, 'label')); ?>, <?php echo json_encode(array_column($chart_klass, 'count')); ?>);
    <?php endif; ?>
    <?php if (!empty($chart_stich)): ?>
    makeDoughnut('chartEinsaetzeStich', <?php echo json_encode(array_column($chart_stich, 'label')); ?>, <?php echo json_encode(array_column($chart_stich, 'count')); ?>);
    <?php endif; ?>
    <?php if (!empty($chart_themen)): ?>
    makeBar('chartEinsaetzeThemen', <?php echo json_encode(array_column($chart_themen, 'label')); ?>, <?php echo json_encode(array_column($chart_themen, 'count')); ?>, true);
    <?php endif; ?>
    <?php endif; ?>

    <?php if ($bereich === 'fahrzeuge'): ?>
    <?php if (!empty($chart_fahrzeuge_einsatz_uebung)): ?>
    makeDoughnut('chartFahrzeugeEinsatzUebung', <?php echo json_encode(array_keys($chart_fahrzeuge_einsatz_uebung)); ?>, <?php echo json_encode(array_values($chart_fahrzeuge_einsatz_uebung)); ?>);
    <?php endif; ?>
    <?php if (!empty($chart_fahrzeuge)): ?>
    makeBar('chartFahrzeugeTop', <?php echo json_encode(array_column($chart_fahrzeuge, 'label')); ?>, <?php echo json_encode(array_column($chart_fahrzeuge, 'count')); ?>, true);
    <?php endif; ?>
    <?php endif; ?>

    <?php if ($bereich === 'geraete'): ?>
    <?php if (!empty($chart_geraete)): ?>
    makeDoughnut('chartGeraeteKuchen', <?php echo json_encode(array_column($chart_geraete, 'label')); ?>, <?php echo json_encode(array_column($chart_geraete, 'count')); ?>);
    makeBar('chartGeraeteBalken', <?php echo json_encode(array_column($chart_geraete, 'label')); ?>, <?php echo json_encode(array_column($chart_geraete, 'count')); ?>, true);
    <?php endif; ?>
    <?php endif; ?>
})();
</script>
</body>
</html>
