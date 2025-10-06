<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Zugriff nur für Benutzer mit Atemschutz-Recht
if (!isset($_SESSION['user_id']) || !has_permission('atemschutz')) {
    header('Location: ../login.php?error=access_denied');
    exit;
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Atemschutz – Feuerwehr App</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container-fluid">
            <a class="navbar-brand" href="../index.php"><i class="fas fa-fire"></i> Feuerwehr App</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <?php echo get_admin_navigation(); ?>
                </ul>
                <ul class="navbar-nav">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="navbarDropdown" role="button" data-bs-toggle="dropdown">
                            <i class="fas fa-user"></i> <?php echo htmlspecialchars($_SESSION['first_name'] . ' ' . $_SESSION['last_name']); ?>
                        </a>
                        <ul class="dropdown-menu">
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
            <h1 class="h3 mb-0"><i class="fas fa-lungs"></i> Atemschutz</h1>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-12 col-md-6">
                <button class="btn btn-primary w-100 py-4" id="btnAddTraeger">
                    <i class="fas fa-user-plus fa-2x mb-2 d-block"></i>
                    <span class="fs-5">Geräteträger hinzufügen</span>
                </button>
            </div>
            <div class="col-12 col-md-6">
                <button class="btn btn-outline-primary w-100 py-4" id="btnShowList">
                    <i class="fas fa-list fa-2x mb-2 d-block"></i>
                    <span class="fs-5">Aktuelle Liste anzeigen</span>
                </button>
            </div>
            <div class="col-12 col-md-6">
                <button class="btn btn-outline-success w-100 py-4" id="btnPlanTraining">
                    <i class="fas fa-calendar-plus fa-2x mb-2 d-block"></i>
                    <span class="fs-5">Übung planen</span>
                </button>
            </div>
            <div class="col-12 col-md-6">
                <button class="btn btn-outline-secondary w-100 py-4" id="btnRecordData">
                    <i class="fas fa-pen-to-square fa-2x mb-2 d-block"></i>
                    <span class="fs-5">Daten hinterlegen</span>
                </button>
            </div>
        </div>

        <div class="alert alert-info">
            Funktionen werden als nächstes implementiert. Wählen Sie einen Button, um fortzufahren.
        </div>

        <?php
        // Liste der Geräteträger laden (reine Auflistung, keine Benutzer der App)
        $traeger = [];
        try {
            $stmt = $db->prepare("SELECT id, first_name, last_name, birthdate, strecke_am, g263_am, uebung_am FROM atemschutz_traeger ORDER BY last_name, first_name");
            $stmt->execute();
            $traeger = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            echo '<div class="alert alert-danger">Fehler beim Laden der Geräteträger: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }

        // Hilfsfunktionen für Anzeige
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

        <div class="card">
            <div class="card-header d-flex align-items-center justify-content-between">
                <span class="fw-semibold">Aktuelle Liste der Atemschutzgeräteträger</span>
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
                                    $name = trim(($t['last_name'] ?? '') . ', ' . ($t['first_name'] ?? ''));
                                    $age = $calcAge($t['birthdate'] ?? null);
                                    $streckeAm = $fmtDate($t['strecke_am'] ?? null);
                                    $streckeBis = $bisStrecke($t['strecke_am'] ?? null);
                                    $g263Am = $fmtDate($t['g263_am'] ?? null);
                                    $g263Bis = $bisG263($t['g263_am'] ?? null, $age);
                                    $uebungAm = $fmtDate($t['uebung_am'] ?? null);
                                    $uebungBis = $bisUebung($t['uebung_am'] ?? null);
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
                                        <span class="badge bg-success">Aktiv</span>
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

    <script>
    // Platzhalter-Handler – werden später mit Logik hinterlegt
    document.addEventListener('DOMContentLoaded', function(){
        const onClickInfo = (msg) => () => alert(msg + "\n(Funktion folgt)");
        const q = (id) => document.getElementById(id);
        const map = {
            btnAddTraeger: 'Geräteträger hinzufügen',
            btnShowList: 'Aktuelle Liste anzeigen',
            btnPlanTraining: 'Übung planen',
            btnRecordData: 'Daten hinterlegen'
        };
        Object.entries(map).forEach(([id,label])=>{ const el=q(id); if(el) el.addEventListener('click', onClickInfo(label)); });

        // Aktionen in der Liste (Platzhalter)
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>


