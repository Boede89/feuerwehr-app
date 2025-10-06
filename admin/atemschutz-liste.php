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

// Berechtigung aus Session (wurde beim Login gesetzt)
$canAtemschutz = !empty($_SESSION['can_atemschutz']) && (int)$_SESSION['can_atemschutz'] === 1;
if (!$canAtemschutz) {
    http_response_code(403);
    echo 'Zugriff verweigert';
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

    <?php
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
        $select = implode(", ", $selectParts);
        $order = in_array('last_name', $columns, true) && in_array('first_name', $columns, true)
            ? 'ORDER BY last_name, first_name'
            : 'ORDER BY id DESC';
        $stmt = $db->prepare("SELECT $select FROM atemschutz_traeger $order");
        $stmt->execute();
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

    <div class="card">
        <div class="card-header d-flex align-items-center justify-content-between">
            <span class="fw-semibold">Aktuelle Liste</span>
            <span class="badge bg-secondary"><?php echo count($traeger); ?> Einträge</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Name</th>
                        <th>Alter</th>
                        <th>Strecke<br><small>Am / Bis</small></th>
                        <th>G26.3<br><small>Am / Bis</small></th>
                        <th>Übung/Einsatz<br><small>Am / Bis</small></th>
                        <th>Status</th>
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
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($name); ?></td>
                                <td><?php echo htmlspecialchars($age); ?></td>
                                <td>
                                    <div>Am: <?php echo htmlspecialchars($streckeAm); ?></div>
                                    <div>Bis: <?php echo htmlspecialchars($streckeBis); ?></div>
                                </td>
                                <td>
                                    <div>Am: <?php echo htmlspecialchars($g263Am); ?></div>
                                    <div>Bis: <?php echo htmlspecialchars($g263Bis); ?></div>
                                </td>
                                <td>
                                    <div>Am: <?php echo htmlspecialchars($uebungAm); ?></div>
                                    <div>Bis: <?php echo htmlspecialchars($uebungBis); ?></div>
                                </td>
                                <td>
                                    <span class="badge bg-success"><?php echo htmlspecialchars($status); ?></span>
                                </td>
                                <td>
                                    <div class="btn-group" role="group">
                                        <button class="btn btn-sm btn-outline-primary" data-action="edit" data-id="<?php echo (int)$t['id']; ?>">
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function(){
    document.querySelectorAll('button[data-action]')?.forEach(btn => {
        btn.addEventListener('click', function(){
            const action = this.getAttribute('data-action');
            const id = this.getAttribute('data-id');
            if (action === 'edit') {
                alert('Bearbeiten: ID ' + id + '\n(Funktion folgt)');
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


