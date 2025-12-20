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
require_once __DIR__ . '/../includes/functions.php';
$isAdmin = hasAdminPermission();
$canAtemschutz = has_permission('atemschutz') || hasAdminPermission();
$permissionWarning = null;

// Berechtigungen werden jetzt über hasAdminPermission() und has_permission() geprüft
if (!$isAdmin && !$canAtemschutz) {
    header('Location: ../login.php?error=access_denied');
    exit;
}

?><!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Atemschutz – Aktuelle Liste</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3E%3Ctext y='14' font-size='14'%3E%F0%9F%94%A5%3C/text%3E%3C/svg%3E">
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
    // Delete-Handler (Löschen)
    $deleteMsg = '';
    $deleteErr = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_traeger') {
        $traeger_id = (int)($_POST['traeger_id'] ?? 0);
        
        if ($traeger_id <= 0) {
            $deleteErr = 'Ungültige Geräteträger-ID.';
        } else {
            try {
                $db->beginTransaction();
                
                // Lade member_id vor dem Löschen
                $stmt = $db->prepare("SELECT member_id FROM atemschutz_traeger WHERE id = ?");
                $stmt->execute([$traeger_id]);
                $traeger = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$traeger) {
                    $deleteErr = 'Geräteträger nicht gefunden.';
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                } else {
                    // Lösche Geräteträger
                    $stmt = $db->prepare("DELETE FROM atemschutz_traeger WHERE id = ?");
                    $stmt->execute([$traeger_id]);
                    
                    // Deaktiviere PA-Träger Status im Mitglied (falls member_id vorhanden)
                    if (!empty($traeger['member_id'])) {
                        $stmt = $db->prepare("UPDATE members SET is_pa_traeger = 0 WHERE id = ?");
                        $stmt->execute([$traeger['member_id']]);
                    }
                    
                    if ($db->inTransaction()) {
                        $db->commit();
                    }
                    $deleteMsg = 'Geräteträger wurde erfolgreich gelöscht. Das Mitglied bleibt erhalten, aber der PA-Träger Status wurde deaktiviert.';
                    // Seite neu laden um Änderungen anzuzeigen
                    header("Location: atemschutz-liste.php?delete_success=1");
                    exit();
                }
            } catch (Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $deleteErr = 'Fehler beim Löschen: ' . htmlspecialchars($e->getMessage());
                error_log("Fehler beim Löschen von Geräteträger: " . $e->getMessage());
            }
        }
    }
    
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
        'name','age','strecke_bis','g263_bis','uebung_bis','status'
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
            'id','first_name','last_name','email','birthdate','strecke_am','g263_am','uebung_am','status'
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
        // Verwende IFNULL um NULL-Werte zu behandeln
        $select = implode(", ", $selectParts)
            . ", CONCAT(IFNULL(last_name,''), ', ', IFNULL(first_name,'')) AS name_full"
            . ", IFNULL(DATE_ADD(strecke_am, INTERVAL 1 YEAR), NULL) AS strecke_bis"
            . ", CASE WHEN birthdate IS NOT NULL AND TIMESTAMPDIFF(YEAR, birthdate, CURDATE()) < 50 THEN IFNULL(DATE_ADD(g263_am, INTERVAL 3 YEAR), NULL) ELSE IFNULL(DATE_ADD(g263_am, INTERVAL 1 YEAR), NULL) END AS g263_bis"
            . ", IFNULL(DATE_ADD(uebung_am, INTERVAL 1 YEAR), NULL) AS uebung_bis";

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
            'strecke_bis' => 'strecke_bis',
            'g263_bis' => 'g263_bis',
            'uebung_bis' => 'uebung_bis',
            'status' => 'name_full', // Status wird in PHP sortiert
        ];
        $orderExpr = $orderMap[$sort] ?? 'name_full';
        $order = "ORDER BY $orderExpr $dir";

        $sql = "SELECT $select FROM atemschutz_traeger $where $order";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $traeger = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Status für alle Einträge berechnen (vor Sortierung)
        $now = new DateTime('today');
        foreach ($traeger as &$tRow) {
            $streckeExpired = false; 
            $g263Expired = false; 
            $uebungExpired = false;
            
            if (!empty($tRow['strecke_bis'])) {
                $diff = (int)$now->diff(new DateTime($tRow['strecke_bis']))->format('%r%a');
                if ($diff < 0) { $streckeExpired = true; }
            }
            if (!empty($tRow['g263_bis'])) {
                $diff = (int)$now->diff(new DateTime($tRow['g263_bis']))->format('%r%a');
                if ($diff < 0) { $g263Expired = true; }
            }
            if (!empty($tRow['uebung_bis'])) {
                $diff = (int)$now->diff(new DateTime($tRow['uebung_bis']))->format('%r%a');
                if ($diff < 0) { $uebungExpired = true; }
            }
            
            // Status berechnen
            if ($streckeExpired || $g263Expired || $uebungExpired) {
                if ($uebungExpired) {
                    $tRow['status'] = 'Übung abgelaufen';
                } else {
                    $tRow['status'] = 'Abgelaufen';
                }
            } else {
                // Prüfe auf Warnung (innerhalb der konfigurierten Warnschwelle)
                // $warnDays wurde bereits aus den Einstellungen geladen
                $streckeWarn = false; $g263Warn = false; $uebungWarn = false;
                
                if (!empty($tRow['strecke_bis'])) {
                    $diff = (int)$now->diff(new DateTime($tRow['strecke_bis']))->format('%r%a');
                    if ($diff >= 0 && $diff <= $warnDays) { $streckeWarn = true; }
                }
                if (!empty($tRow['g263_bis'])) {
                    $diff = (int)$now->diff(new DateTime($tRow['g263_bis']))->format('%r%a');
                    if ($diff >= 0 && $diff <= $warnDays) { $g263Warn = true; }
                }
                if (!empty($tRow['uebung_bis'])) {
                    $diff = (int)$now->diff(new DateTime($tRow['uebung_bis']))->format('%r%a');
                    if ($diff >= 0 && $diff <= $warnDays) { $uebungWarn = true; }
                }
                
                if ($streckeWarn || $g263Warn || $uebungWarn) {
                    $tRow['status'] = 'Warnung';
                } else {
                    $tRow['status'] = 'Tauglich';
                }
            }
        }
        unset($tRow); // Referenz löschen
        
        // PHP-basierte Sortierung für Status
        if ($sort === 'status') {
            $statusOrder = [
                'Tauglich' => 1,
                'Warnung' => 2,
                'Abgelaufen' => 3,
                'Übung abgelaufen' => 4
            ];
            
            usort($traeger, function($a, $b) use ($statusOrder, $dir) {
                $aOrder = $statusOrder[$a['status']] ?? 999;
                $bOrder = $statusOrder[$b['status']] ?? 999;
                
                if ($aOrder === $bOrder) {
                    // Bei gleichem Status alphabetisch nach Namen sortieren
                    return strcmp($a['name_full'], $b['name_full']);
                }
                
                if ($dir === 'ASC') {
                    return $aOrder <=> $bOrder;
                } else {
                    return $bOrder <=> $aOrder;
                }
            });
        }

        // Zählwerte vorbereiten: Gesamt und Tauglich/Warnung (ohne abgelaufen / "Übung abgelaufen")
        $totalCount = count($traeger);
        $okOrWarnCount = 0;
        foreach ($traeger as $tRow) {
            if ($tRow['status'] === 'Tauglich' || $tRow['status'] === 'Warnung') {
                $okOrWarnCount++;
            }
        }
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

    <?php
    // Optional: Edit-Autoload via GET edit_id
    $autoloadEdit = isset($_GET['edit_id']) ? (int)$_GET['edit_id'] : 0;
    $autoloadData = null;
    if ($autoloadEdit > 0) {
        try {
            $st = $db->prepare("SELECT id, first_name, last_name, email, birthdate, strecke_am, g263_am, uebung_am FROM atemschutz_traeger WHERE id=? LIMIT 1");
            $st->execute([$autoloadEdit]);
            $autoloadData = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Exception $e) { /* ignore */ }
    }
    ?>

    <?php if ($error): ?>
        <div class="alert alert-danger">Fehler beim Laden der Geräteträger: <?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <?php if (!empty($updateMsg)): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($updateMsg); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($updateErr)): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($updateErr); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if (isset($_GET['delete_success'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle"></i> Geräteträger wurde erfolgreich gelöscht. Das Mitglied bleibt erhalten, aber der PA-Träger Status wurde deaktiviert.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($deleteErr) && !isset($_GET['delete_success'])): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($deleteErr); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
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
                    <option value="strecke_bis" <?php echo $sort==='strecke_bis'?'selected':''; ?>>Strecke Bis</option>
                    <option value="g263_bis" <?php echo $sort==='g263_bis'?'selected':''; ?>>G26.3 Bis</option>
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
            <div class="d-flex flex-column align-items-end">
                <span class="badge bg-secondary mb-1">Gesamt: <?php echo (int)($totalCount ?? count($traeger)); ?></span>
                <span class="badge bg-success">Tauglich: <?php echo (int)($okOrWarnCount ?? 0); ?></span>
            </div>
        </div>
        <!-- Mobile-optimierte Karten-Ansicht -->
        <div class="d-md-none">
            <?php if (empty($traeger)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-info-circle fa-3x text-muted mb-3"></i>
                    <h5 class="text-muted">Keine Geräteträger gefunden</h5>
                </div>
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
                        
                        // Status anhand Bis-Daten bestimmen (inkl. Sonderfall: Übung abgelaufen)
                        $now = new DateTime('today');
                        $streckeExpired = false; $g263Expired = false; $uebungExpired = false;
                        $anyWarn = false;
                        if (!empty($t['strecke_bis'])) {
                            $diff = (int)$now->diff(new DateTime($t['strecke_bis']))->format('%r%a');
                            if ($diff < 0) { $streckeExpired = true; }
                            elseif ($diff <= $warnDays) { $anyWarn = true; }
                        }
                        if (!empty($t['g263_bis'])) {
                            $diff = (int)$now->diff(new DateTime($t['g263_bis']))->format('%r%a');
                            if ($diff < 0) { $g263Expired = true; }
                            elseif ($diff <= $warnDays) { $anyWarn = true; }
                        }
                        if (!empty($t['uebung_bis'])) {
                            $diff = (int)$now->diff(new DateTime($t['uebung_bis']))->format('%r%a');
                            if ($diff < 0) { $uebungExpired = true; }
                            elseif ($diff <= $warnDays) { $anyWarn = true; }
                        }

                        if ($streckeExpired || $g263Expired) {
                            $statusText = 'Abgelaufen';
                            $statusClass = 'bg-danger';
                        } elseif ($uebungExpired && !$streckeExpired && !$g263Expired) {
                            $statusText = 'Übung abgelaufen';
                            $statusClass = 'bg-danger';
                        } elseif ($anyWarn) {
                            $statusText = 'Warnung';
                            $statusClass = 'bg-warning text-dark';
                        } else {
                            $statusText = 'Tauglich';
                            $statusClass = 'bg-success';
                        }
                    ?>
                    <div class="card mb-3">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h6 class="mb-0"><?php echo htmlspecialchars($name); ?></h6>
                            <span class="badge <?php echo $statusClass; ?>"><?php echo htmlspecialchars($statusText); ?></span>
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-6">
                                    <div class="text-muted small">Alter</div>
                                    <div><?php echo htmlspecialchars($age); ?> Jahre</div>
                                </div>
                                <div class="col-6">
                                    <div class="text-muted small">Status</div>
                                    <div><span class="badge <?php echo $statusClass; ?>"><?php echo htmlspecialchars($statusText); ?></span></div>
                                </div>
                                <?php if (!empty($t['email'])): ?>
                                <div class="col-12">
                                    <div class="text-muted small">E-Mail</div>
                                    <div><i class="fas fa-envelope me-1"></i><?php echo htmlspecialchars($t['email']); ?></div>
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <hr class="my-3">
                            
                            <!-- Strecke -->
                            <div class="mb-3">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <strong class="text-primary">Strecke</strong>
                                </div>
                                <div class="row">
                                    <div class="col-6">
                                        <div class="text-muted small">Am:</div>
                                        <div><?php echo htmlspecialchars($streckeAm); ?></div>
                                    </div>
                                    <div class="col-6">
                                        <div class="text-muted small">Bis:</div>
                                        <?php
                                            $streckeBisDate = $t['strecke_bis'] ?? null;
                                            $cls = '';
                                            if ($streckeBisDate) {
                                                $now = new DateTime('today');
                                                $bis = new DateTime($streckeBisDate);
                                                $diff = (int)$now->diff($bis)->format('%r%a');
                                                if ($diff < 0) { $cls = 'bis-expired'; }
                                                elseif ($diff <= $warnDays && $diff >= 0) { $cls = 'bis-warn'; }
                                            }
                                        ?>
                                        <div><span class="bis-badge <?php echo $cls; ?>"><?php echo htmlspecialchars($streckeBisDate); ?></span></div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- G26.3 -->
                            <div class="mb-3">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <strong class="text-info">G26.3</strong>
                                </div>
                                <div class="row">
                                    <div class="col-6">
                                        <div class="text-muted small">Am:</div>
                                        <div><?php echo htmlspecialchars($g263Am); ?></div>
                                    </div>
                                    <div class="col-6">
                                        <div class="text-muted small">Bis:</div>
                                        <?php
                                            $g263BisDate = $t['g263_bis'] ?? null;
                                            $cls = '';
                                            if ($g263BisDate) {
                                                $now = new DateTime('today');
                                                $bis = new DateTime($g263BisDate);
                                                $diff = (int)$now->diff($bis)->format('%r%a');
                                                if ($diff < 0) { $cls = 'bis-expired'; }
                                                elseif ($diff <= $warnDays && $diff >= 0) { $cls = 'bis-warn'; }
                                            }
                                        ?>
                                        <div><span class="bis-badge <?php echo $cls; ?>"><?php echo htmlspecialchars($g263BisDate); ?></span></div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Übung -->
                            <div class="mb-3">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <strong class="text-warning">Übung/Einsatz</strong>
                                </div>
                                <div class="row">
                                    <div class="col-6">
                                        <div class="text-muted small">Am:</div>
                                        <div><?php echo htmlspecialchars($uebungAm); ?></div>
                                    </div>
                                    <div class="col-6">
                                        <div class="text-muted small">Bis:</div>
                                        <?php
                                            $uebungBisDate = $t['uebung_bis'] ?? null;
                                            $cls = '';
                                            if ($uebungBisDate) {
                                                $now = new DateTime('today');
                                                $bis = new DateTime($uebungBisDate);
                                                $diff = (int)$now->diff($bis)->format('%r%a');
                                                if ($diff < 0) { $cls = 'bis-expired'; }
                                                elseif ($diff <= $warnDays && $diff >= 0) { $cls = 'bis-warn'; }
                                            }
                                        ?>
                                        <div><span class="bis-badge <?php echo $cls; ?>"><?php echo htmlspecialchars($uebungBisDate); ?></span></div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Aktionen -->
                            <div class="d-grid gap-2">
                                <button class="btn btn-outline-primary" data-action="edit" data-id="<?php echo (int)$t['id']; ?>"
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
                                <button class="btn btn-outline-danger" data-action="delete" data-id="<?php echo (int)$t['id']; ?>">
                                    <i class="fas fa-trash"></i> Löschen
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <!-- Desktop-Tabellen-Ansicht -->
        <div class="d-none d-md-block">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th><a href="?<?php echo http_build_query(array_merge($_GET,['sort'=>'name','dir'=>$sort==='name' && strtolower($dir)==='asc'?'desc':'asc'])); ?>" class="text-decoration-none">Name</a></th>
                            <th><a href="?<?php echo http_build_query(array_merge($_GET,['sort'=>'age','dir'=>$sort==='age' && strtolower($dir)==='asc'?'desc':'asc'])); ?>" class="text-decoration-none">Alter</a></th>
                            <th>E-Mail</th>
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
                                <td colspan="8" class="text-center text-muted py-4">Noch keine Geräteträger erfasst.</td>
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
                                        // Status anhand Bis-Daten bestimmen (inkl. Sonderfall: Übung abgelaufen)
                                        $now = new DateTime('today');
                                        $streckeExpired = false; $g263Expired = false; $uebungExpired = false;
                                        $anyWarn = false;
                                        if (!empty($t['strecke_bis'])) {
                                            $diff = (int)$now->diff(new DateTime($t['strecke_bis']))->format('%r%a');
                                            if ($diff < 0) { $streckeExpired = true; }
                                            elseif ($diff <= $warnDays) { $anyWarn = true; }
                                        }
                                        if (!empty($t['g263_bis'])) {
                                            $diff = (int)$now->diff(new DateTime($t['g263_bis']))->format('%r%a');
                                            if ($diff < 0) { $g263Expired = true; }
                                            elseif ($diff <= $warnDays) { $anyWarn = true; }
                                        }
                                        if (!empty($t['uebung_bis'])) {
                                            $diff = (int)$now->diff(new DateTime($t['uebung_bis']))->format('%r%a');
                                            if ($diff < 0) { $uebungExpired = true; }
                                            elseif ($diff <= $warnDays) { $anyWarn = true; }
                                        }

                                        if ($streckeExpired || $g263Expired) {
                                            $statusText = 'Abgelaufen';
                                            $statusClass = 'bg-danger';
                                        } elseif ($uebungExpired && !$streckeExpired && !$g263Expired) {
                                            $statusText = 'Übung abgelaufen';
                                            $statusClass = 'bg-danger';
                                        } elseif ($anyWarn) {
                                            $statusText = 'Warnung';
                                            $statusClass = 'bg-warning text-dark';
                                        } else {
                                            $statusText = 'Tauglich';
                                            $statusClass = 'bg-success';
                                        }
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($name); ?></td>
                                    <td><?php echo htmlspecialchars($age); ?></td>
                                    <td>
                                        <?php if (!empty($t['email'])): ?>
                                            <i class="fas fa-envelope me-1"></i><?php echo htmlspecialchars($t['email']); ?>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
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
                                                elseif ($diff <= $warnDays && $diff >= 0) { $cls = 'bis-warn'; }
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
                                                elseif ($diff <= $warnDays && $diff >= 0) { $cls = 'bis-warn'; }
                                            }
                                        ?>
                                        <div>Bis: <span class="bis-badge <?php echo $cls; ?>"><?php echo htmlspecialchars($g263BisDate); ?></span></div>
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
                                                elseif ($diff <= $warnDays && $diff >= 0) { $cls = 'bis-warn'; }
                                            }
                                        ?>
                                        <div>Bis: <span class="bis-badge <?php echo $cls; ?>"><?php echo htmlspecialchars($uebungBisDate); ?></span></div>
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
                        <div class="col-12">
                            <div class="border rounded p-3 bg-light">
                                <div class="row g-3">
                                    <div class="col-12 col-md-6">
                                        <label class="form-label">Vorname <span class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="fas fa-user"></i></span>
                                            <input type="text" class="form-control" name="first_name" id="edit_first_name" placeholder="Max" required>
                                        </div>
                                    </div>
                                    <div class="col-12 col-md-6">
                                        <label class="form-label">Nachname <span class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="fas fa-user"></i></span>
                                            <input type="text" class="form-control" name="last_name" id="edit_last_name" placeholder="Mustermann" required>
                                        </div>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">E-Mail (optional)</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                                            <input type="email" class="form-control" name="email" id="edit_email" placeholder="name@beispiel.de">
                                        </div>
                                    </div>
                                    <div class="col-12 col-md-6">
                                        <label class="form-label">Geburtsdatum <span class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="fas fa-cake-candles"></i></span>
                                            <input type="date" class="form-control" name="birthdate" id="edit_birthdate" required>
                                        </div>
                                        <div class="form-text">Alter wird automatisch berechnet.</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-12">
                            <div class="border rounded p-3">
                                <h6 class="mb-3"><i class="fas fa-road me-2"></i> Strecke</h6>
                                <div class="row g-3">
                                    <div class="col-12 col-md-6">
                                        <label class="form-label">Am <span class="text-danger">*</span></label>
                                        <input type="date" class="form-control" name="strecke_am" id="edit_strecke_am" required>
                                        <div class="form-text">Bis-Datum wird automatisch auf +1 Jahr gesetzt.</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-12">
                            <div class="border rounded p-3">
                                <h6 class="mb-3"><i class="fas fa-stethoscope me-2"></i> G26.3</h6>
                                <div class="row g-3">
                                    <div class="col-12 col-md-6">
                                        <label class="form-label">Am <span class="text-danger">*</span></label>
                                        <input type="date" class="form-control" name="g263_am" id="edit_g263_am" required>
                                        <div class="form-text">Bis-Datum: unter 50 Jahre +3 Jahre, ab 50 +1 Jahr.</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-12">
                            <div class="border rounded p-3">
                                <h6 class="mb-3"><i class="fas fa-dumbbell me-2"></i> Übung/Einsatz</h6>
                                <div class="row g-3">
                                    <div class="col-12 col-md-6">
                                        <label class="form-label">Am <span class="text-danger">*</span></label>
                                        <input type="date" class="form-control" name="uebung_am" id="edit_uebung_am" required>
                                        <div class="form-text">Bis-Datum wird automatisch auf +1 Jahr gesetzt.</div>
                                    </div>
                                </div>
                            </div>
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
                if (confirm('Möchten Sie diesen Geräteträger wirklich löschen?\n\nDas Mitglied bleibt erhalten, aber der PA-Träger Status wird deaktiviert.')) {
                    // Formular erstellen und absenden
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = '';
                    
                    const actionInput = document.createElement('input');
                    actionInput.type = 'hidden';
                    actionInput.name = 'action';
                    actionInput.value = 'delete_traeger';
                    form.appendChild(actionInput);
                    
                    const idInput = document.createElement('input');
                    idInput.type = 'hidden';
                    idInput.name = 'traeger_id';
                    idInput.value = id;
                    form.appendChild(idInput);
                    
                    document.body.appendChild(form);
                    form.submit();
                }
            }
        });
    });
    // Auto-open edit modal via GET
    <?php if (!empty($autoloadData)): ?>
    try {
        document.getElementById('edit_id').value = '<?php echo (int)$autoloadData['id']; ?>';
        document.getElementById('edit_first_name').value = '<?php echo htmlspecialchars($autoloadData['first_name'], ENT_QUOTES); ?>';
        document.getElementById('edit_last_name').value = '<?php echo htmlspecialchars($autoloadData['last_name'], ENT_QUOTES); ?>';
        document.getElementById('edit_email').value = '<?php echo htmlspecialchars($autoloadData['email'] ?? '', ENT_QUOTES); ?>';
        document.getElementById('edit_birthdate').value = '<?php echo htmlspecialchars($autoloadData['birthdate'], ENT_QUOTES); ?>';
        document.getElementById('edit_strecke_am').value = '<?php echo htmlspecialchars($autoloadData['strecke_am'], ENT_QUOTES); ?>';
        document.getElementById('edit_g263_am').value = '<?php echo htmlspecialchars($autoloadData['g263_am'], ENT_QUOTES); ?>';
        document.getElementById('edit_uebung_am').value = '<?php echo htmlspecialchars($autoloadData['uebung_am'], ENT_QUOTES); ?>';
        const m = bootstrap.Modal.getOrCreateInstance(document.getElementById('editTraegerModal'));
        m.show();
    } catch(e) {}
    <?php endif; ?>
});
</script>
</body>
</html>


