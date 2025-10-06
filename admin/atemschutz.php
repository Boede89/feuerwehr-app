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
    });
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>


