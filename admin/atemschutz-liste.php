<?php
// Standalone-Variante ohne get_admin_navigation(), um Redirects zu vermeiden
require_once __DIR__ . '/../includes/db.php';

// Lightweight-Probe: /admin/atemschutz-liste.php?plain=1
if (isset($_GET['plain'])) {
    echo 'ATEMSCHUTZ_LISTE_OK';
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

// Berechtigung: Admin hat immer Zugriff; sonst Atemschutz-Recht
$isAdmin = !empty($_SESSION['is_admin']) && (int)$_SESSION['is_admin'] === 1;
$canAtemschutz = !empty($_SESSION['can_atemschutz']) && (int)$_SESSION['can_atemschutz'] === 1;
$permissionWarning = null;

try {
    $uid = (int)($_SESSION['user_id'] ?? 0);
    if ($uid > 0) {
        $stmtPerm = $db->prepare('SELECT is_admin, can_atemschutz FROM users WHERE id = ?');
        $stmtPerm->execute([$uid]);
        $rowPerm = $stmtPerm->fetch(PDO::FETCH_ASSOC);
        if ($rowPerm) {
            if ((int)($rowPerm['is_admin'] ?? 0) === 1) {
                $isAdmin = true;
                $_SESSION['is_admin'] = 1; // Session synchronisieren
            }
            if ((int)($rowPerm['can_atemschutz'] ?? 0) === 1) {
                $canAtemschutz = true;
                $_SESSION['can_atemschutz'] = 1; // Session synchronisieren
            }
        }
    }
} catch (Exception $e) {
    // still proceed below
}

if (!$isAdmin && !$canAtemschutz) {
    // Temporär: Seite dennoch anzeigen, aber Hinweis einblenden
    $permissionWarning = 'Hinweis: Ihnen fehlt aktuell die Berechtigung "Atemschutz". Anzeige erfolgt vorübergehend zu Testzwecken.';
}

?><!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Atemschutz – Aktuelle Liste</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
    <style>
    .bis-badge { padding: .25rem .5rem; border-radius: .375rem; display: inline-block; }
    .bis-warn { background-color: #fff3cd; color: #664d03; } /* gelb */
    .bis-expired { background-color: #dc3545; color: #fff; } /* kräftiges rot */
    .status-badge { font-weight: 600; }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
    <div class="container-fluid">
        <a class="navbar-brand" href="dashboard.php"><i class="fas fa-fire"></i> Feuerwehr App</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="nav">
            <ul class="navbar-nav me-auto">
                <li class="nav-item"><a class="nav-link" href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                <li class="nav-item"><a class="nav-link active" href="atemschutz-liste.php"><i class="fas fa-list"></i> Atemschutz-Liste</a></li>
            </ul>
            <ul class="navbar-nav">
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                        <i class="fas fa-user"></i> <?php echo htmlspecialchars($_SESSION['first_name'] ?? 'Benutzer'); ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user-edit"></i> Profil</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="../logout.php"><i class="fas fa-sign-out-alt"></i> Abmelden</a></li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>
    </nav>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h3 mb-0"><i class="fas fa-list"></i> Aktuelle Liste der Atemschutzgeräteträger</h1>
        <div>
            <a class="btn btn-outline-secondary" href="atemschutz.php">
                <i class="fas fa-arrow-left"></i> Zurück
            </a>
        </div>
    </div>

    <?php if (!empty($permissionWarning)): ?>
        <div class="alert alert-warning"><?php echo htmlspecialchars($permissionWarning); ?></div>
    <?php endif; ?>

    <?php
    // Update-Handler (Bearbeiten)
    $updateMsg = '';
    $updateErr = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_traeger') {
        $id = (int)($_POST['id'] ?? 0);
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName = trim($_POST['last_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $birthdate = trim($_POST['birthdate'] ?? '');
        $streckeAm = trim($_POST['strecke_am'] ?? '');
        $g263Am = trim($_POST['g263_am'] ?? '');
        $uebungAm = trim($_POST['uebung_am'] ?? '');

        if ($id <= 0 || $firstName === '' || $lastName === '' || $birthdate === '' || $streckeAm === '' || $g263Am === '' || $uebungAm === '') {
            $updateErr = 'Bitte alle Pflichtfelder ausfüllen.';
        } else {
            try {
                $stmtU = $db->prepare("UPDATE atemschutz_traeger SET first_name=?, last_name=?, email=?, birthdate=?, strecke_am=?, g263_am=?, uebung_am=? WHERE id=?");
                $stmtU->execute([$firstName, $lastName, ($email !== '' ? $email : null), $birthdate, $streckeAm, $g263Am, $uebungAm, $id]);
                $updateMsg = 'Geräteträger aktualisiert.';
            } catch (Exception $e) {
                $updateErr = 'Fehler beim Speichern: ' . htmlspecialchars($e->getMessage());
            }
        }
    }
    // Suche & Sortierung
    $q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
    $allowedSort = [
        'name','age','strecke_am','strecke_bis','g263_am','g263_bis','uebung_am','uebung_bis','status'
    ];
    $sort = isset($_GET['sort']) && in_array($_GET['sort'], $allowedSort, true) ? $_GET['sort'] : 'name';
    $dir = strtolower($_GET['dir'] ?? 'asc');
    $dir = $dir === 'desc' ? 'DESC' : 'ASC';

    // Schwellwert (Tage) für gelbe Warnung aus settings, Default 90
    $warnDays = 90;
    try {
        $stmtWarn = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'atemschutz_warn_days' LIMIT 1");
        $stmtWarn->execute();
        $v = $stmtWarn->fetchColumn();
        if ($v !== false && is_numeric($v)) { $warnDays = (int)$v; }
    } catch (Exception $e) { /* ignore, fallback 90 */ }

    $traeger = [];
    $error = null;
    try {
        // Tabelle robust lesen – wir erkennen Spalten dynamisch
        $columns = [];
        try {
            $stmtCols = $db->query("SHOW COLUMNS FROM atemschutz_traeger");
            $columns = $stmtCols->fetchAll(PDO::FETCH_COLUMN, 0);
        } catch (Exception $e) {
            $error = 'Tabelle atemschutz_traeger fehlt oder ist nicht erreichbar.';
        }

        $selectParts = [];
        $wanted = [
            'id','first_name','last_name','birthdate','strecke_am','g263_am','uebung_am','status'
        ];
        foreach ($wanted as $col) {
            if (in_array($col, $columns, true)) {
                $selectParts[] = $col;
            }
        }
        if (empty($selectParts)) {
            throw new Exception('Benötigte Spalten fehlen (z.B. first_name/last_name/birthdate).');
        }
        // Abgeleitete Felder für Sortierung (Name und Bis-Daten)
        $select = implode(", ", $selectParts)
            . ", CONCAT(IFNULL(last_name,''), ', ', IFNULL(first_name,'')) AS name_full"
            . ", DATE_ADD(strecke_am, INTERVAL 1 YEAR) AS strecke_bis"
            . ", CASE WHEN TIMESTAMPDIFF(YEAR, birthdate, CURDATE()) < 50 THEN DATE_ADD(g263_am, INTERVAL 3 YEAR) ELSE DATE_ADD(g263_am, INTERVAL 1 YEAR) END AS g263_bis"
            . ", DATE_ADD(uebung_am, INTERVAL 1 YEAR) AS uebung_bis";

        // WHERE (Suche)
        $where = '';
        $params = [];
        if ($q !== '' && (in_array('first_name', $columns, true) || in_array('last_name', $columns, true))) {
            $where = 'WHERE (CONCAT(IFNULL(last_name,\'\'), ", ", IFNULL(first_name,\'\')) LIKE ? OR CONCAT(IFNULL(first_name,\'\'), " ", IFNULL(last_name,\'\')) LIKE ?)';
            $params[] = "%$q%";
            $params[] = "%$q%";
        }

        // ORDER BY
        $orderMap = [
            'name' => 'name_full',
            'age' => 'TIMESTAMPDIFF(YEAR, birthdate, CURDATE())',
            'strecke_am' => 'strecke_am',
            'strecke_bis' => 'strecke_bis',
            'g263_am' => 'g263_am',
            'g263_bis' => 'g263_bis',
            'uebung_am' => 'uebung_am',
            'uebung_bis' => 'uebung_bis',
            'status' => 'status',
        ];
        $orderExpr = $orderMap[$sort] ?? 'name_full';
        $order = "ORDER BY $orderExpr $dir";

        $sql = "SELECT $select FROM atemschutz_traeger $where $order";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $traeger = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $error = $error ?: $e->getMessage();
    }

    $fmtDate = function($d) {
        if (!$d) return '';
        try { $dt = new DateTime($d); return $dt->format('d.m.Y'); } catch (Exception $e) { return ''; }
    };
    $calcAge = function($birthdate) {
        if (!$birthdate) return '';
        try { $b = new DateTime($birthdate); $now = new DateTime('today'); return $b->diff($now)->y; } catch (Exception $e) { return ''; }
    };
    $addYears = function($dateStr, $years) use ($fmtDate) {
        if (!$dateStr) return '';
        try { $d = new DateTime($dateStr); $d->modify('+' . (int)$years . ' year'); return $fmtDate($d->format('Y-m-d')); } catch (Exception $e) { return ''; }
    };
    $bisStrecke = function($streckeAm) use ($addYears) { return $addYears($streckeAm, 1); };
    $bisUebung = function($uebungAm) use ($addYears) { return $addYears($uebungAm, 1); };
    $bisG263 = function($g263Am, $age) use ($addYears) { return $addYears($g263Am, ($age !== '' && (int)$age < 50) ? 3 : 1); };
    ?>

    <?php if ($error): ?>
        <div class="alert alert-danger">Fehler beim Laden der Geräteträger: <?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <?php if (!empty($updateMsg)): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($updateMsg); ?></div>
    <?php endif; ?>
    <?php if (!empty($updateErr)): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($updateErr); ?></div>
    <?php endif; ?>

    <form class="mb-3" method="get" action="atemschutz-liste.php">
        <div class="row g-2 align-items-end">
            <div class="col-12 col-md-6 col-lg-4">
                <label class="form-label">Nach Namen suchen</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-search"></i></span>
                    <input type="text" class="form-control" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="Nachname, Vorname oder Vorname Nachname">
                </div>
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label class="form-label">Sortieren nach</label>
                <select class="form-select" name="sort">
                    <option value="name" <?php echo $sort==='name'?'selected':''; ?>>Name</option>
                    <option value="age" <?php echo $sort==='age'?'selected':''; ?>>Alter</option>
                    <option value="strecke_am" <?php echo $sort==='strecke_am'?'selected':''; ?>>Strecke Am</option>
                    <option value="strecke_bis" <?php echo $sort==='strecke_bis'?'selected':''; ?>>Strecke Bis</option>
                    <option value="g263_am" <?php echo $sort==='g263_am'?'selected':''; ?>>G26.3 Am</option>
                    <option value="g263_bis" <?php echo $sort==='g263_bis'?'selected':''; ?>>G26.3 Bis</option>
                    <option value="uebung_am" <?php echo $sort==='uebung_am'?'selected':''; ?>>Übung/Einsatz Am</option>
                    <option value="uebung_bis" <?php echo $sort==='uebung_bis'?'selected':''; ?>>Übung/Einsatz Bis</option>
                    <option value="status" <?php echo $sort==='status'?'selected':''; ?>>Status</option>
                </select>
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label class="form-label">Richtung</label>
                <select class="form-select" name="dir">
                    <option value="asc" <?php echo strtolower($dir)==='asc'?'selected':''; ?>>Aufsteigend</option>
                    <option value="desc" <?php echo strtolower($dir)==='desc'?'selected':''; ?>>Absteigend</option>
                </select>
            </div>
            <div class="col-12 col-lg-4 d-flex gap-2">
                <button class="btn btn-primary" type="submit"><i class="fas fa-filter"></i> Anwenden</button>
                <a class="btn btn-outline-secondary" href="atemschutz-liste.php"><i class="fas fa-rotate-left"></i> Zurücksetzen</a>
            </div>
        </div>
    </form>

    <div class="card">
        <div class="card-header d-flex align-items-center justify-content-between">
            <span class="fw-semibold">Aktuelle Liste</span>
            <span class="badge bg-secondary"><?php echo count($traeger); ?> Einträge</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th><a href="?<?php echo http_build_query(array_merge($_GET,['sort'=>'name','dir'=>$sort==='name' && strtolower($dir)==='asc'?'desc':'asc'])); ?>" class="text-decoration-none">Name</a></th>
                        <th><a href="?<?php echo http_build_query(array_merge($_GET,['sort'=>'age','dir'=>$sort==='age' && strtolower($dir)==='asc'?'desc':'asc'])); ?>" class="text-decoration-none">Alter</a></th>
                        <th><a href="?<?php echo http_build_query(array_merge($_GET,['sort'=>'strecke_bis','dir'=>$sort==='strecke_bis' && strtolower($dir)==='asc'?'desc':'asc'])); ?>" class="text-decoration-none">Strecke</a></th>
                        <th><a href="?<?php echo http_build_query(array_merge($_GET,['sort'=>'g263_bis','dir'=>$sort==='g263_bis' && strtolower($dir)==='asc'?'desc':'asc'])); ?>" class="text-decoration-none">G26.3</a></th>
                        <th><a href="?<?php echo http_build_query(array_merge($_GET,['sort'=>'uebung_bis','dir'=>$sort==='uebung_bis' && strtolower($dir)==='asc'?'desc':'asc'])); ?>" class="text-decoration-none">Übung/Einsatz</a></th>
                        <th><a href="?<?php echo http_build_query(array_merge($_GET,['sort'=>'status','dir'=>$sort==='status' && strtolower($dir)==='asc'?'desc':'asc'])); ?>" class="text-decoration-none">Status</a></th>
                        <th>Aktion</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($traeger)): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">Noch keine Geräteträger erfasst.</td>
                        </tr>
                    <?php else: ?>
                            <?php foreach ($traeger as $t): ?>
                            <?php
                                $first = $t['first_name'] ?? '';
                                $last = $t['last_name'] ?? '';
                                $name = trim(($last ? $last : '') . ($first ? ', ' . $first : ''));
                                $age = $calcAge($t['birthdate'] ?? null);
                                $streckeAm = $fmtDate($t['strecke_am'] ?? null);
                                $streckeBis = $bisStrecke($t['strecke_am'] ?? null);
                                $g263Am = $fmtDate($t['g263_am'] ?? null);
                                $g263Bis = $bisG263($t['g263_am'] ?? null, $age);
                                $uebungAm = $fmtDate($t['uebung_am'] ?? null);
                                $uebungBis = $bisUebung($t['uebung_am'] ?? null);
                                $status = $t['status'] ?? 'Aktiv';
                                    // Status anhand Bis-Daten bestimmen
                                    $now = new DateTime('today');
                                    $isExpired = false; $isWarn = false;
                                    $datesCheck = [];
                                    if (!empty($t['strecke_bis'])) { $datesCheck[] = new DateTime($t['strecke_bis']); }
                                    if (!empty($t['g263_bis'])) { $datesCheck[] = new DateTime($t['g263_bis']); }
                                    if (!empty($t['uebung_bis'])) { $datesCheck[] = new DateTime($t['uebung_bis']); }
                                    foreach ($datesCheck as $d) {
                                        $diff = (int)$now->diff($d)->format('%r%a');
                                        if ($diff < 0) { $isExpired = true; break; }
                                        if ($diff <= $warnDays) { $isWarn = true; }
                                    }
                                    $statusText = $isExpired ? 'Abgelaufen' : ($isWarn ? 'Warnung' : 'Tauglich');
                                    $statusClass = $isExpired ? 'bg-danger' : ($isWarn ? 'bg-warning text-dark' : 'bg-success');
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($name); ?></td>
                                <td><?php echo htmlspecialchars($age); ?></td>
                                <td>
                                    <div>Am: <?php echo htmlspecialchars($streckeAm); ?></div>
                                    <?php
                                        $streckeBisDate = $t['strecke_bis'] ?? null;
                                        $cls = '';
                                        if ($streckeBisDate) {
                                            $now = new DateTime('today');
                                            $bis = new DateTime($streckeBisDate);
                                            $diff = (int)$now->diff($bis)->format('%r%a');
                                            if ($diff < 0) { $cls = 'bis-expired'; }
                                            elseif ($diff <= $warnDays) { $cls = 'bis-warn'; }
                                        }
                                    ?>
                                    <div>Bis: <span class="bis-badge <?php echo $cls; ?>"><?php echo htmlspecialchars($streckeBis); ?></span></div>
                                </td>
                                <td>
                                    <div>Am: <?php echo htmlspecialchars($g263Am); ?></div>
                                    <?php
                                        $g263BisDate = $t['g263_bis'] ?? null;
                                        $cls = '';
                                        if ($g263BisDate) {
                                            $now = new DateTime('today');
                                            $bis = new DateTime($g263BisDate);
                                            $diff = (int)$now->diff($bis)->format('%r%a');
                                            if ($diff < 0) { $cls = 'bis-expired'; }
                                            elseif ($diff <= $warnDays) { $cls = 'bis-warn'; }
                                        }
                                    ?>
                                    <div>Bis: <span class="bis-badge <?php echo $cls; ?>"><?php echo htmlspecialchars($g263Bis); ?></span></div>
                                </td>
                                <td>
                                    <div>Am: <?php echo htmlspecialchars($uebungAm); ?></div>
                                    <?php
                                        $uebungBisDate = $t['uebung_bis'] ?? null;
                                        $cls = '';
                                        if ($uebungBisDate) {
                                            $now = new DateTime('today');
                                            $bis = new DateTime($uebungBisDate);
                                            $diff = (int)$now->diff($bis)->format('%r%a');
                                            if ($diff < 0) { $cls = 'bis-expired'; }
                                            elseif ($diff <= $warnDays) { $cls = 'bis-warn'; }
                                        }
                                    ?>
                                    <div>Bis: <span class="bis-badge <?php echo $cls; ?>"><?php echo htmlspecialchars($uebungBis); ?></span></div>
                                </td>
                                <td>
                                    <span class="badge status-badge <?php echo $statusClass; ?>"><?php echo htmlspecialchars($statusText); ?></span>
                                </td>
                                <td>
                                    <div class="btn-group" role="group">
                                        <button class="btn btn-sm btn-outline-primary" data-action="edit" data-id="<?php echo (int)$t['id']; ?>"
                                            data-first_name="<?php echo htmlspecialchars($t['first_name'] ?? ''); ?>"
                                            data-last_name="<?php echo htmlspecialchars($t['last_name'] ?? ''); ?>"
                                            data-email="<?php echo htmlspecialchars($t['email'] ?? ''); ?>"
                                            data-birthdate="<?php echo htmlspecialchars($t['birthdate'] ?? ''); ?>"
                                            data-strecke_am="<?php echo htmlspecialchars($t['strecke_am'] ?? ''); ?>"
                                            data-g263_am="<?php echo htmlspecialchars($t['g263_am'] ?? ''); ?>"
                                            data-uebung_am="<?php echo htmlspecialchars($t['uebung_am'] ?? ''); ?>"
                                        >
                                            <i class="fas fa-pen"></i> Bearbeiten
                                        </button>
                                        <button class="btn btn-sm btn-outline-danger" data-action="delete" data-id="<?php echo (int)$t['id']; ?>">
                                            <i class="fas fa-trash"></i> Löschen
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<!-- Modal: Geräteträger bearbeiten -->
<div class="modal fade" id="editTraegerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-user-pen me-2"></i> Geräteträger bearbeiten</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post" action="atemschutz-liste.php">
                <input type="hidden" name="action" value="update_traeger">
                <input type="hidden" name="id" id="edit_id">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label class="form-label">Vorname *</label>
                            <input type="text" class="form-control" name="first_name" id="edit_first_name" required>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label">Nachname *</label>
                            <input type="text" class="form-control" name="last_name" id="edit_last_name" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">E-Mail (optional)</label>
                            <input type="email" class="form-control" name="email" id="edit_email" placeholder="name@beispiel.de">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label">Geburtsdatum *</label>
                            <input type="date" class="form-control" name="birthdate" id="edit_birthdate" required>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label">Strecke – Am *</label>
                            <input type="date" class="form-control" name="strecke_am" id="edit_strecke_am" required>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label">G26.3 – Am *</label>
                            <input type="date" class="form-control" name="g263_am" id="edit_g263_am" required>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label">Übung/Einsatz – Am *</label>
                            <input type="date" class="form-control" name="uebung_am" id="edit_uebung_am" required>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="submit" class="btn btn-primary">Speichern</button>
                </div>
            </form>
        </div>
    </div>
 </div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function(){
    document.querySelectorAll('button[data-action]')?.forEach(btn => {
        btn.addEventListener('click', function(){
            const action = this.getAttribute('data-action');
            const id = this.getAttribute('data-id');
            if (action === 'edit') {
                // Modal mit Daten füllen
                document.getElementById('edit_id').value = id;
                document.getElementById('edit_first_name').value = this.getAttribute('data-first_name') || '';
                document.getElementById('edit_last_name').value = this.getAttribute('data-last_name') || '';
                document.getElementById('edit_email').value = this.getAttribute('data-email') || '';
                document.getElementById('edit_birthdate').value = this.getAttribute('data-birthdate') || '';
                document.getElementById('edit_strecke_am').value = this.getAttribute('data-strecke_am') || '';
                document.getElementById('edit_g263_am').value = this.getAttribute('data-g263_am') || '';
                document.getElementById('edit_uebung_am').value = this.getAttribute('data-uebung_am') || '';
                const modal = new bootstrap.Modal(document.getElementById('editTraegerModal'));
                modal.show();
            } else if (action === 'delete') {
                if (confirm('Geräteträger wirklich löschen?')) {
                    alert('Löschen: ID ' + id + '\n(Funktion folgt)');
                }
            }
        });
    });
});
</script>
</body>
</html>


