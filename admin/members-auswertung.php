<?php
/**
 * Mitglieder-Auswertung: Teilnahme an Übungen und Einsätzen.
 * Filter: Jahr, Zeitraum, Mitglied, Typ. Darstellungen: Tabelle, Kuchendiagramm, Balkendiagramm.
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

$jahr = isset($_GET['jahr']) ? (int)$_GET['jahr'] : (int)date('Y');
$von = isset($_GET['von']) ? trim($_GET['von']) : $jahr . '-01-01';
$bis = isset($_GET['bis']) ? trim($_GET['bis']) : $jahr . '-12-31';
$member_id = isset($_GET['member_id']) ? (int)$_GET['member_id'] : 0;
$typ_filter = isset($_GET['typ']) ? trim($_GET['typ']) : '';
$darstellung = isset($_GET['darstellung']) ? trim($_GET['darstellung']) : 'tabelle';

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $von)) $von = $jahr . '-01-01';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $bis)) $bis = $jahr . '-12-31';

$members = [];
try {
    $stmt = $db->query("SELECT id, first_name, last_name FROM members ORDER BY last_name, first_name");
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$typen_auswahl = [
    '' => '— Alle —',
    'uebungen' => 'Nur Übungen (Übungsdienst, Sonstiges)',
    'einsaetze' => 'Nur Einsätze',
    'dienst' => 'Dienst (Dienstplan)',
    'einsatz' => 'Einsatz (Sonstige Anwesenheit)',
    'manuell' => 'Manuell',
];

$stats = [];
$stats_nach_typ = [];
$stats_nach_mitglied = [];

try {
    $sql = "
        SELECT am.member_id, m.first_name, m.last_name, a.id AS liste_id, a.datum, a.typ AS liste_typ, a.bezeichnung,
               d.typ AS dienst_typ, d.bezeichnung AS dienst_bezeichnung
        FROM anwesenheitsliste_mitglieder am
        JOIN anwesenheitslisten a ON a.id = am.anwesenheitsliste_id
        LEFT JOIN dienstplan d ON d.id = a.dienstplan_id
        LEFT JOIN members m ON m.id = am.member_id
        WHERE a.datum BETWEEN ? AND ?
    ";
    $params = [$von, $bis];
    if ($member_id > 0) {
        $sql .= " AND am.member_id = ?";
        $params[] = $member_id;
    }
    $sql .= " ORDER BY a.datum DESC, m.last_name, m.first_name";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $kat = 'sonstiges';
        if ($r['liste_typ'] === 'einsatz') {
            $kat = 'einsatz';
        } elseif ($r['liste_typ'] === 'dienst' && !empty($r['dienst_typ'])) {
            $kat = $r['dienst_typ'] === 'einsatz' ? 'einsatz' : ($r['dienst_typ'] === 'uebungsdienst' || $r['dienst_typ'] === 'uebung' || $r['dienst_typ'] === 'dienst' ? 'uebung' : $r['dienst_typ']);
        } elseif ($r['liste_typ'] === 'dienst') {
            $kat = 'uebung';
        } elseif ($r['liste_typ'] === 'manuell') {
            $kat = 'manuell';
        }

        if ($typ_filter === 'uebungen' && $kat !== 'uebung' && $kat !== 'jahreshauptversammlung' && $kat !== 'sonstiges' && $kat !== 'manuell') continue;
        if ($typ_filter === 'einsaetze' && $kat !== 'einsatz') continue;
        if ($typ_filter === 'dienst' && $r['liste_typ'] !== 'dienst') continue;
        if ($typ_filter === 'einsatz' && $r['liste_typ'] !== 'einsatz') continue;
        if ($typ_filter === 'manuell' && $r['liste_typ'] !== 'manuell') continue;

        $mid = $r['member_id'];
        $stats_nach_mitglied[$mid] = ($stats_nach_mitglied[$mid] ?? 0) + 1;
        $stats_nach_typ[$kat] = ($stats_nach_typ[$kat] ?? 0) + 1;
        $stats[] = $r;
    }
} catch (Exception $e) {
    $stats = [];
}

$typ_labels = [
    'uebung' => 'Übungsdienst',
    'einsatz' => 'Einsatz',
    'jahreshauptversammlung' => 'JHV',
    'sonstiges' => 'Sonstiges',
    'manuell' => 'Manuell',
];

$chart_data_typ = [];
foreach ($stats_nach_typ as $k => $v) {
    $chart_data_typ[] = ['label' => $typ_labels[$k] ?? $k, 'count' => $v];
}
$chart_data_mitglied = [];
foreach ($stats_nach_mitglied as $mid => $cnt) {
    $m = null;
    foreach ($members as $mm) {
        if ((int)$mm['id'] === (int)$mid) { $m = $mm; break; }
    }
    $name = $m ? ($m['last_name'] . ', ' . $m['first_name']) : 'ID ' . $mid;
    $chart_data_mitglied[] = ['label' => $name, 'count' => $cnt];
}
usort($chart_data_mitglied, function ($a, $b) { return $b['count'] - $a['count']; });
$chart_data_mitglied = array_slice($chart_data_mitglied, 0, 15);
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
    <h1 class="h3 mb-4"><i class="fas fa-chart-pie"></i> Auswertung – Teilnahme an Übungen & Einsätzen</h1>

    <div class="card mb-4">
        <div class="card-header">Filter</div>
        <div class="card-body">
            <form method="get" class="row g-3">
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
                    <label class="form-label">Mitglied</label>
                    <select name="member_id" class="form-select">
                        <option value="">— Alle —</option>
                        <?php foreach ($members as $m): ?>
                        <option value="<?php echo (int)$m['id']; ?>" <?php echo $member_id === (int)$m['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($m['last_name'] . ', ' . $m['first_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Typ</label>
                    <select name="typ" class="form-select">
                        <?php foreach ($typen_auswahl as $k => $v): ?>
                        <option value="<?php echo htmlspecialchars($k); ?>" <?php echo $typ_filter === $k ? 'selected' : ''; ?>><?php echo htmlspecialchars($v); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Darstellung</label>
                    <select name="darstellung" class="form-select">
                        <option value="tabelle" <?php echo $darstellung === 'tabelle' ? 'selected' : ''; ?>>Tabelle</option>
                        <option value="kuchendiagramm" <?php echo $darstellung === 'kuchendiagramm' ? 'selected' : ''; ?>>Kuchendiagramm</option>
                        <option value="balkendiagramm" <?php echo $darstellung === 'balkendiagramm' ? 'selected' : ''; ?>>Balkendiagramm</option>
                        <option value="alle" <?php echo $darstellung === 'alle' ? 'selected' : ''; ?>>Alle (Tabelle + Diagramme)</option>
                    </select>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Anwenden</button>
                </div>
            </form>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header">Übersicht</div>
                <div class="card-body">
                    <p class="mb-0"><strong><?php echo count($stats); ?></strong> Teilnahmen im Zeitraum <?php echo date('d.m.Y', strtotime($von)); ?> – <?php echo date('d.m.Y', strtotime($bis)); ?></p>
                    <?php if (!empty($stats_nach_typ)): ?>
                    <p class="text-muted small mt-2">Nach Typ: <?php
                        $parts = [];
                        foreach ($stats_nach_typ as $k => $v) {
                            $parts[] = ($typ_labels[$k] ?? $k) . ': ' . $v;
                        }
                        echo implode(', ', $parts);
                    ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php if (!empty($stats_nach_mitglied)): ?>
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header">Teilnahmen pro Mitglied (Top 10)</div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead><tr><th>Mitglied</th><th class="text-end">Anzahl</th></tr></thead>
                            <tbody>
                                <?php
                                $top = array_slice($chart_data_mitglied, 0, 10);
                                foreach ($top as $row):
                                ?>
                                <tr><td><?php echo htmlspecialchars($row['label']); ?></td><td class="text-end"><?php echo (int)$row['count']; ?></td></tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($darstellung === 'kuchendiagramm' || $darstellung === 'alle'): ?>
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">Nach Typ (Kuchen)</div>
                <div class="card-body"><canvas id="chartTyp" height="250"></canvas></div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">Top 15 Mitglieder (Kuchen)</div>
                <div class="card-body"><canvas id="chartMitglied" height="250"></canvas></div>
            </div>
        </div>
    </div>
    <?php if (empty($chart_data_typ) && empty($chart_data_mitglied)): ?>
    <p class="text-muted">Keine Daten für Diagramme.</p>
    <?php endif; ?>
    <?php endif; ?>

    <?php if ($darstellung === 'balkendiagramm'): ?>
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">Nach Typ (Balken)</div>
                <div class="card-body"><canvas id="chartTypBar" height="250"></canvas></div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">Top 15 Mitglieder (Balken)</div>
                <div class="card-body"><canvas id="chartMitgliedBar" height="250"></canvas></div>
            </div>
        </div>
    </div>
    <?php if (empty($chart_data_typ) && empty($chart_data_mitglied)): ?>
    <p class="text-muted">Keine Daten für Diagramme.</p>
    <?php endif; ?>
    <?php endif; ?>

    <?php if ($darstellung === 'tabelle' || $darstellung === 'alle'): ?>
    <div class="card">
        <div class="card-header">Detaillierte Teilnahmen</div>
        <div class="card-body">
            <?php if (empty($stats)): ?>
            <p class="text-muted mb-0">Keine Teilnahmen im gewählten Zeitraum.</p>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover">
                    <thead>
                        <tr>
                            <th>Datum</th>
                            <th>Mitglied</th>
                            <th>Typ</th>
                            <th>Bezeichnung</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($stats as $s):
                            $k = $s['liste_typ'] === 'einsatz' ? 'einsatz' : ($s['liste_typ'] === 'dienst' && !empty($s['dienst_typ']) ? $s['dienst_typ'] : $s['liste_typ']);
                            $typ_label = get_dienstplan_typ_label($k);
                        ?>
                        <tr>
                            <td><?php echo date('d.m.Y', strtotime($s['datum'])); ?></td>
                            <td><?php echo htmlspecialchars(trim($s['last_name'] . ', ' . $s['first_name'])); ?></td>
                            <td><span class="badge bg-secondary"><?php echo htmlspecialchars($typ_label); ?></span></td>
                            <td><?php echo htmlspecialchars($s['bezeichnung'] ?? $s['dienst_bezeichnung'] ?? '-'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function() {
    var colors = ['#0d6efd','#198754','#ffc107','#dc3545','#6f42c1','#6c757d','#0dcaf0','#fd7e14'];
    function getColor(i) { return colors[i % colors.length]; }
    var dataTyp = <?php echo json_encode($chart_data_typ); ?>;
    var dataMitglied = <?php echo json_encode($chart_data_mitglied); ?>;

    <?php if ($darstellung === 'kuchendiagramm' || $darstellung === 'alle'): ?>
    if (dataTyp.length > 0 && document.getElementById('chartTyp')) {
        new Chart(document.getElementById('chartTyp'), {
            type: 'doughnut',
            data: {
                labels: dataTyp.map(function(d){ return d.label + ' (' + d.count + ')'; }),
                datasets: [{ data: dataTyp.map(function(d){ return d.count; }), backgroundColor: dataTyp.map(function(d,i){ return getColor(i); }) }]
            },
            options: { responsive: true, maintainAspectRatio: false }
        });
    }
    if (dataMitglied.length > 0 && document.getElementById('chartMitglied')) {
        new Chart(document.getElementById('chartMitglied'), {
            type: 'doughnut',
            data: {
                labels: dataMitglied.map(function(d){ return d.label + ' (' + d.count + ')'; }),
                datasets: [{ data: dataMitglied.map(function(d){ return d.count; }), backgroundColor: dataMitglied.map(function(d,i){ return getColor(i); }) }]
            },
            options: { responsive: true, maintainAspectRatio: false }
        });
    }
    <?php endif; ?>

    <?php if ($darstellung === 'balkendiagramm'): ?>
    if (dataTyp.length > 0 && document.getElementById('chartTypBar')) {
        new Chart(document.getElementById('chartTypBar'), {
            type: 'bar',
            data: {
                labels: dataTyp.map(function(d){ return d.label; }),
                datasets: [{ label: 'Teilnahmen', data: dataTyp.map(function(d){ return d.count; }), backgroundColor: dataTyp.map(function(d,i){ return getColor(i); }) }]
            },
            options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true } } }
        });
    }
    if (dataMitglied.length > 0 && document.getElementById('chartMitgliedBar')) {
        new Chart(document.getElementById('chartMitgliedBar'), {
            type: 'bar',
            data: {
                labels: dataMitglied.map(function(d){ return d.label; }),
                datasets: [{ label: 'Teilnahmen', data: dataMitglied.map(function(d){ return d.count; }), backgroundColor: '#0d6efd' }]
            },
            options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, scales: { x: { beginAtZero: true } } }
        });
    }
    <?php endif; ?>
})();
</script>
</body>
</html>
