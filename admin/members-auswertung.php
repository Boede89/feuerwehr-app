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
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $von)) $von = $jahr . '-01-01';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $bis)) $bis = date('Y-m-d');

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

// Hilfsfunktion: Ist Anwesenheit Einsatz oder Übung?
function ist_einsatz($row) {
    if ($row['liste_typ'] === 'einsatz') return true;
    if ($row['liste_typ'] === 'dienst' && !empty($row['dienst_typ']) && $row['dienst_typ'] === 'einsatz') return true;
    return false;
}

$filter_params = ['jahr' => $jahr, 'von' => $von, 'bis' => $bis];
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
                        <p class="card-text text-muted small">Durchschn. Personen, GF/ZF, Klassifizierung, Einsatzdauer, Top-Themen</p>
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
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Anwenden</button>
                </div>
            </form>
        </div>
    </div>

    <?php
    // ==================== BEREICH PERSONEN ====================
    if ($bereich === 'personen'):
        $personen_stats = [];
        $personen_maschinist = [];
        $personen_einheitsfuehrer = [];
        $personen_letzte = [];
        try {
            $stmt = $db->prepare("
                SELECT am.member_id, am.vehicle_id, a.id AS liste_id, a.datum, a.typ AS liste_typ, a.uhrzeit_von, a.uhrzeit_bis,
                       d.typ AS dienst_typ
                FROM anwesenheitsliste_mitglieder am
                JOIN anwesenheitslisten a ON a.id = am.anwesenheitsliste_id
                LEFT JOIN dienstplan d ON d.id = a.dienstplan_id
                WHERE a.datum BETWEEN ? AND ?
            ");
            $stmt->execute([$von, $bis]);
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $mid = (int)$r['member_id'];
                $einsatz = ist_einsatz($r);
                $personen_stats[$mid] = $personen_stats[$mid] ?? ['einsaetze' => 0, 'uebungen' => 0, 'fahrzeuge' => [], 'stunden' => 0];
                if ($einsatz) $personen_stats[$mid]['einsaetze']++;
                else $personen_stats[$mid]['uebungen']++;
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
            $stmt = $db->prepare("
                SELECT af.maschinist_member_id, af.einheitsfuehrer_member_id, a.datum
                FROM anwesenheitsliste_fahrzeuge af
                JOIN anwesenheitslisten a ON a.id = af.anwesenheitsliste_id
                WHERE a.datum BETWEEN ? AND ?
            ");
            $stmt->execute([$von, $bis]);
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
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
        usort($members, function($a,$b) use ($personen_stats) {
            $ta = (($personen_stats[(int)$a['id']]['einsaetze'] ?? 0) + ($personen_stats[(int)$a['id']]['uebungen'] ?? 0));
            $tb = (($personen_stats[(int)$b['id']]['einsaetze'] ?? 0) + ($personen_stats[(int)$b['id']]['uebungen'] ?? 0));
            return $tb - $ta;
        });
    ?>
    <h2 class="h5 mb-3"><i class="fas fa-users"></i> Personen</h2>
    <div class="table-responsive">
        <table class="table table-sm table-hover">
            <thead>
                <tr>
                    <th>Mitglied</th>
                    <th class="text-end">Einsätze</th>
                    <th class="text-end">Übungen</th>
                    <th class="text-end">Gesamt</th>
                    <th class="text-end">Als Maschinist</th>
                    <th class="text-end">Als Einheitsführer</th>
                    <th>Letzte Teilnahme</th>
                    <th class="text-end">Stunden</th>
                    <th>Fahrzeuge</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($members as $m):
                    $mid = (int)$m['id'];
                    $s = $personen_stats[$mid] ?? ['einsaetze' => 0, 'uebungen' => 0, 'fahrzeuge' => [], 'stunden' => 0];
                    $gesamt = $s['einsaetze'] + $s['uebungen'];
                    if ($gesamt === 0) continue;
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
                    <td class="text-end"><?php echo (int)$s['einsaetze']; ?></td>
                    <td class="text-end"><?php echo (int)$s['uebungen']; ?></td>
                    <td class="text-end"><strong><?php echo $gesamt; ?></strong></td>
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
    <?php if (empty(array_filter($personen_stats, fn($x) => ($x['einsaetze'] + $x['uebungen']) > 0))): ?>
    <p class="text-muted">Keine Teilnahmen im gewählten Zeitraum.</p>
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
            $stmt = $db->prepare("
                SELECT a.id, a.datum, a.typ AS liste_typ, a.bezeichnung, a.uhrzeit_von, a.uhrzeit_bis,
                       a.klassifizierung, a.einsatzstichwort, d.typ AS dienst_typ, d.bezeichnung AS dienst_bezeichnung
                FROM anwesenheitslisten a
                LEFT JOIN dienstplan d ON d.id = a.dienstplan_id
                WHERE a.datum BETWEEN ? AND ?
            ");
            $stmt->execute([$von, $bis]);
            $listen = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($listen as $a) {
                $einsatz = ($a['liste_typ'] === 'einsatz') || ($a['liste_typ'] === 'dienst' && ($a['dienst_typ'] ?? '') === 'einsatz');
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
        } catch (Exception $e) {}
        $personen_pro_liste = [];
        $gf_zf_pro_liste = [];
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
                AND (LOWER(COALESCE(q.name,'')) LIKE '%gruppenführer%' OR LOWER(COALESCE(q.name,'')) LIKE '%zugführer%')
            ");
            $stmt->execute([$lid]);
            $gf_zf_pro_liste[$lid] = (int)$stmt->fetchColumn();
        }
        $durchschn_personen = count($personen_pro_liste) > 0 ? array_sum($personen_pro_liste) / count($personen_pro_liste) : 0;
        $durchschn_gf_zf = count($gf_zf_pro_liste) > 0 ? array_sum($gf_zf_pro_liste) / count($gf_zf_pro_liste) : 0;
        $durchschn_dauer = count($dauern) > 0 ? array_sum($dauern) / count($dauern) : 0;
        arsort($themen);
        $top_themen = array_slice($themen, 0, 15);
    ?>
    <h2 class="h5 mb-3"><i class="fas fa-clipboard-list"></i> Einsätze / Dienste</h2>
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card">
                <div class="card-body">
                    <h6 class="card-subtitle text-muted">Durchschn. Personen pro Einsatz/Übung</h6>
                    <p class="h4 mb-0"><?php echo number_format($durchschn_personen, 1, ',', '.'); ?></p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card">
                <div class="card-body">
                    <h6 class="card-subtitle text-muted">Durchschn. GF/ZF pro Einsatz/Übung</h6>
                    <p class="h4 mb-0"><?php echo number_format($durchschn_gf_zf, 1, ',', '.'); ?></p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card">
                <div class="card-body">
                    <h6 class="card-subtitle text-muted">Durchschn. Einsatzdauer</h6>
                    <p class="h4 mb-0"><?php echo number_format($durchschn_dauer, 1, ',', '.'); ?> h</p>
                </div>
            </div>
        </div>
    </div>
    <div class="row">
        <div class="col-md-6 mb-4">
            <div class="card">
                <div class="card-header">Klassifizierung / Einsatzstichwort</div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead><tr><th>Klassifizierung</th><th class="text-end">Anzahl</th></tr></thead>
                            <tbody>
                                <?php arsort($klassifizierung); foreach ($klassifizierung as $k => $v): ?>
                                <tr><td><?php echo htmlspecialchars($k); ?></td><td class="text-end"><?php echo $v; ?></td></tr>
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
                            <thead><tr><th>Stichwort</th><th class="text-end">Anzahl</th></tr></thead>
                            <tbody>
                                <?php arsort($einsatzstichwort); foreach ($einsatzstichwort as $k => $v): ?>
                                <tr><td><?php echo htmlspecialchars($k); ?></td><td class="text-end"><?php echo $v; ?></td></tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="card mb-4">
        <div class="card-header">Top-Themen bei Übungsdiensten</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Thema / Bezeichnung</th><th class="text-end">Anzahl</th></tr></thead>
                    <tbody>
                        <?php foreach ($top_themen as $k => $v): ?>
                        <tr><td><?php echo htmlspecialchars($k); ?></td><td class="text-end"><?php echo $v; ?></td></tr>
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
            $stmt = $db->prepare("
                SELECT af.vehicle_id, af.maschinist_member_id, af.einheitsfuehrer_member_id, a.id AS liste_id, a.typ AS liste_typ,
                       d.typ AS dienst_typ
                FROM anwesenheitsliste_fahrzeuge af
                JOIN anwesenheitslisten a ON a.id = af.anwesenheitsliste_id
                LEFT JOIN dienstplan d ON d.id = a.dienstplan_id
                WHERE a.datum BETWEEN ? AND ?
            ");
            $stmt->execute([$von, $bis]);
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $vid = (int)$r['vehicle_id'];
                $einsatz = ($r['liste_typ'] === 'einsatz') || ($r['liste_typ'] === 'dienst' && ($r['dienst_typ'] ?? '') === 'einsatz');
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
    ?>
    <h2 class="h5 mb-3"><i class="fas fa-truck"></i> Fahrzeuge</h2>
    <div class="table-responsive">
        <table class="table table-sm table-hover">
            <thead>
                <tr>
                    <th>Fahrzeug</th>
                    <th class="text-end">Einsätze</th>
                    <th class="text-end">Übungen</th>
                    <th class="text-end">Gesamt</th>
                    <th class="text-end">Durchschn. Besatzung</th>
                    <th>Häufigste Maschinisten</th>
                    <th>Häufigste Einheitsführer</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $sorted_vids = array_keys($fahrzeug_stats);
                usort($sorted_vids, function($a,$b) use ($fahrzeug_stats) {
                    $ta = ($fahrzeug_stats[$a]['einsaetze'] ?? 0) + ($fahrzeug_stats[$a]['uebungen'] ?? 0);
                    $tb = ($fahrzeug_stats[$b]['einsaetze'] ?? 0) + ($fahrzeug_stats[$b]['uebungen'] ?? 0);
                    return $tb - $ta;
                });
                foreach ($sorted_vids as $vid):
                    $s = $fahrzeug_stats[$vid];
                    $gesamt = $s['einsaetze'] + $s['uebungen'];
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
            $stmt = $db->prepare("SELECT id, custom_data FROM anwesenheitslisten WHERE datum BETWEEN ? AND ? AND custom_data IS NOT NULL AND custom_data != '' AND custom_data != 'null'");
            $stmt->execute([$von, $bis]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
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
    ?>
    <h2 class="h5 mb-3"><i class="fas fa-wrench"></i> Geräte – Einsatzhäufigkeit</h2>
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
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($geraete_count as $eqid => $cnt):
                            $info = $geraete_names[$eqid] ?? ['name' => 'ID'.$eqid, 'vehicle_id' => 0];
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($info['name']); ?></td>
                            <td><?php echo htmlspecialchars($vehicle_map[$info['vehicle_id']] ?? '-'); ?></td>
                            <td class="text-end"><?php echo $cnt; ?></td>
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
</style>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
