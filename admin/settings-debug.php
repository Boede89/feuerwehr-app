<?php
/**
 * Debug-Einstellungen: Fahrzeugzuordnungen und weitere Debug-Ansichten.
 * Nur für Superadmins / Admins mit Einstellungsrechten.
 */
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/einheiten-setup.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('Location: ../login.php');
    exit;
}
if (!hasAdminPermission()) {
    header('Location: ../login.php?error=access_denied');
    exit;
}

$active_tab = isset($_GET['tab']) ? trim($_GET['tab']) : 'fahrzeugzuordnungen';
if ($active_tab !== 'fahrzeugzuordnungen') $active_tab = 'fahrzeugzuordnungen';

$jahr = isset($_GET['jahr']) ? (int)$_GET['jahr'] : (int)date('Y');
$von = isset($_GET['von']) ? trim($_GET['von']) : $jahr . '-01-01';
$bis = isset($_GET['bis']) ? trim($_GET['bis']) : date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $von)) $von = $jahr . '-01-01';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $bis)) $bis = date('Y-m-d');

// Einheiten laden
$einheiten = [];
try {
    $stmt = $db->query("SELECT id, name FROM einheiten WHERE is_active = 1 ORDER BY sort_order, name");
    $einheiten = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Debug-Daten pro Einheit
$debug_data_by_einheit = [];
$vehicles_by_einheit = [];
$members_by_einheit = [];

foreach ($einheiten as $e) {
    $eid = (int)$e['id'];
    $einheit_where_a = " AND (a.einheit_id = " . $eid . " OR a.einheit_id IS NULL)";
    
    $debug_data = [];
    $vehicles = [];
    $members = [];
    
    try {
        $stmt = $db->prepare("SELECT id, name FROM vehicles WHERE einheit_id = ? OR einheit_id IS NULL ORDER BY name");
        $stmt->execute([$eid]);
        $vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $ex) {}
    
    try {
        $stmt = $db->prepare("SELECT id, first_name, last_name FROM members WHERE einheit_id = ? OR einheit_id IS NULL ORDER BY last_name, first_name");
        $stmt->execute([$eid]);
        $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $ex) {}
    
    $init_member = function($mid) use (&$debug_data) {
        if (!isset($debug_data[$mid])) {
            $debug_data[$mid] = [
                'auf_fahrzeug' => [],
                'maschinist' => [],
                'einheitsfuehrer' => []
            ];
        }
    };
    
    try {
        $stmt = $db->prepare("SELECT am.member_id, am.vehicle_id, a.datum FROM anwesenheitsliste_mitglieder am JOIN anwesenheitslisten a ON a.id = am.anwesenheitsliste_id WHERE a.datum BETWEEN ? AND ? AND am.vehicle_id IS NOT NULL AND am.vehicle_id > 0" . $einheit_where_a);
        $stmt->execute([$von, $bis]);
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $mid = (int)$r['member_id'];
            $vid = (int)$r['vehicle_id'];
            $init_member($mid);
            $debug_data[$mid]['auf_fahrzeug'][$vid] = ($debug_data[$mid]['auf_fahrzeug'][$vid] ?? 0) + 1;
        }
        
        $stmt = $db->prepare("SELECT af.vehicle_id, af.maschinist_member_id, af.einheitsfuehrer_member_id FROM anwesenheitsliste_fahrzeuge af JOIN anwesenheitslisten a ON a.id = af.anwesenheitsliste_id WHERE a.datum BETWEEN ? AND ?" . $einheit_where_a);
        $stmt->execute([$von, $bis]);
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $vid = (int)$r['vehicle_id'];
            if (!empty($r['maschinist_member_id'])) {
                $mid = (int)$r['maschinist_member_id'];
                $init_member($mid);
                $debug_data[$mid]['maschinist'][$vid] = ($debug_data[$mid]['maschinist'][$vid] ?? 0) + 1;
            }
            if (!empty($r['einheitsfuehrer_member_id'])) {
                $mid = (int)$r['einheitsfuehrer_member_id'];
                $init_member($mid);
                $debug_data[$mid]['einheitsfuehrer'][$vid] = ($debug_data[$mid]['einheitsfuehrer'][$vid] ?? 0) + 1;
            }
        }
    } catch (Exception $e) {}
    
    $debug_data_by_einheit[$eid] = $debug_data;
    $vehicles_by_einheit[$eid] = $vehicles;
    $members_by_einheit[$eid] = $members;
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Debug – Feuerwehr App</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
    <div class="container-fluid">
        <a class="navbar-brand" href="../index.php"><i class="fas fa-fire"></i> Feuerwehr App</a>
        <div class="d-flex ms-auto align-items-center">
            <?php $admin_menu_in_navbar = true; include __DIR__ . '/includes/admin-menu.inc.php'; ?>
        </div>
    </div>
</nav>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <h1 class="h3 mb-0"><i class="fas fa-bug text-warning"></i> Debug</h1>
        <a href="settings.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left"></i> Zurück zu Einstellungen</a>
    </div>

    <ul class="nav nav-tabs mb-4">
        <li class="nav-item">
            <a class="nav-link <?php echo $active_tab === 'fahrzeugzuordnungen' ? 'active' : ''; ?>" href="?tab=fahrzeugzuordnungen&von=<?php echo urlencode($von); ?>&bis=<?php echo urlencode($bis); ?>">
                <i class="fas fa-truck"></i> Fahrzeugzuordnungen
            </a>
        </li>
    </ul>

    <?php if ($active_tab === 'fahrzeugzuordnungen'): ?>
    <div class="card mb-4">
        <div class="card-header">Zeitraum</div>
        <div class="card-body">
            <form method="get" class="row g-3 align-items-end">
                <input type="hidden" name="tab" value="fahrzeugzuordnungen">
                <div class="col-auto">
                    <label class="form-label">Von</label>
                    <input type="date" name="von" class="form-control" value="<?php echo htmlspecialchars($von); ?>">
                </div>
                <div class="col-auto">
                    <label class="form-label">Bis</label>
                    <input type="date" name="bis" class="form-control" value="<?php echo htmlspecialchars($bis); ?>">
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Anwenden</button>
                </div>
            </form>
        </div>
    </div>

    <p class="text-muted small mb-3"><i class="fas fa-info-circle"></i> Person × Fahrzeug (pro Fahrzeug: Besatzung | Maschinist | Einheitsführer). Nur Anwesenheitslisten, keine Gerätewartmitteilungen. Zeitraum: <?php echo htmlspecialchars($von); ?> – <?php echo htmlspecialchars($bis); ?></p>

    <?php foreach ($einheiten as $e):
        $eid = (int)$e['id'];
        $debug_data = $debug_data_by_einheit[$eid] ?? [];
        $vehicles = $vehicles_by_einheit[$eid] ?? [];
        $members = $members_by_einheit[$eid] ?? [];
        $member_map = [];
        foreach ($members as $m) $member_map[(int)$m['id']] = $m;
        $debug_mids = array_keys($debug_data);
        $debug_missing = array_filter($debug_mids, fn($mid) => (int)$mid > 0 && !isset($member_map[(int)$mid]));
        if (!empty($debug_missing)) {
            try {
                $ph = implode(',', array_fill(0, count($debug_missing), '?'));
                $st = $db->prepare("SELECT id, first_name, last_name FROM members WHERE id IN ($ph)");
                $st->execute(array_values($debug_missing));
                while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                    $member_map[(int)$row['id']] = ['last_name' => $row['last_name'] ?? '', 'first_name' => $row['first_name'] ?? ''];
                }
            } catch (Exception $ex) {}
        }
    ?>
    <div class="card mb-4">
        <div class="card-header bg-light"><strong><?php echo htmlspecialchars($e['name']); ?></strong></div>
        <div class="card-body p-0">
            <?php if (empty($debug_data)): ?>
            <p class="text-muted p-3 mb-0">Keine Daten im gefilterten Zeitraum.</p>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-bordered mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Person</th>
                            <?php foreach ($vehicles as $v): ?>
                            <th colspan="3" class="text-center border-start bg-light" title="<?php echo htmlspecialchars($v['name']); ?>"><?php echo htmlspecialchars($v['name']); ?></th>
                            <?php endforeach; ?>
                        </tr>
                        <tr>
                            <th></th>
                            <?php foreach ($vehicles as $v): ?>
                            <th class="text-center border-start" style="min-width: 40px;">Besatzung</th>
                            <th class="text-center bg-info bg-opacity-25" style="min-width: 40px;">Masch.</th>
                            <th class="text-center bg-success bg-opacity-25" style="min-width: 40px;">EF</th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($debug_data as $mid => $d):
                            $m = $member_map[(int)$mid] ?? null;
                            $name = $m ? trim(($m['last_name'] ?? '') . ', ' . ($m['first_name'] ?? '')) : 'Unbekannt (ID ' . (int)$mid . ')';
                            if ($name === '' && $m) $name = 'ID ' . (int)$mid;
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($name); ?></td>
                            <?php foreach ($vehicles as $v):
                                $vid = (int)$v['id'];
                                $besatzung = $d['auf_fahrzeug'][$vid] ?? 0;
                                $masch = $d['maschinist'][$vid] ?? 0;
                                $ef = $d['einheitsfuehrer'][$vid] ?? 0;
                            ?>
                            <td class="text-center border-start"><?php echo $besatzung > 0 ? $besatzung : '–'; ?></td>
                            <td class="text-center bg-info bg-opacity-10"><?php echo $masch > 0 ? $masch : '–'; ?></td>
                            <td class="text-center bg-success bg-opacity-10"><?php echo $ef > 0 ? $ef : '–'; ?></td>
                            <?php endforeach; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
