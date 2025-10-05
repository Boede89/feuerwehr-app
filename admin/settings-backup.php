<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('Location: ../login.php');
    exit;
}
if (!has_admin_access()) {
    header('Location: ../login.php?error=access_denied');
    exit;
}

$message = '';
$error = '';
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sicherung & Wiederherstellung</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
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
                <li class="nav-item"><a class="nav-link" href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                <li class="nav-item"><a class="nav-link" href="settings.php"><i class="fas fa-cog"></i> Einstellungen</a></li>
                <li class="nav-item"><a class="nav-link active" href="settings-backup.php"><i class="fas fa-shield-halved"></i> Sicherung</a></li>
            </ul>
        </div>
    </div>
</nav>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h3 mb-0"><i class="fas fa-shield-halved"></i> Sicherung & Wiederherstellung</h1>
        <a href="settings-global.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left"></i> Zurück zu Einstellungen</a>
    </div>

    <?php if ($message) echo show_success($message); ?>
    <?php if ($error) echo show_error($error); ?>

    <div class="row g-4">
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header"><i class="fas fa-file-arrow-down"></i> Einstellungen</div>
                <div class="card-body">
                    <p class="text-muted">Exportieren und importieren Sie die globalen App-Einstellungen als JSON-Datei.</p>
                    <div class="d-flex flex-column gap-2">
                        <a class="btn btn-outline-primary" href="export-settings.php">
                            <i class="fas fa-download"></i> Exportieren (JSON)
                        </a>
                        <form method="POST" action="import-settings.php" enctype="multipart/form-data" class="d-inline">
                            <input type="file" name="settings_file" accept="application/json,.json" class="form-control form-control-sm mb-2" required>
                            <button type="submit" class="btn btn-outline-success">
                                <i class="fas fa-upload"></i> Importieren (JSON)
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header"><i class="fas fa-database"></i> Gesamte Datenbank</div>
                <div class="card-body">
                    <p class="text-muted">Erstellen Sie einen kompletten SQL-Dump (Schema und Daten) bzw. importieren Sie eine SQL-Datei.</p>
                    <div class="d-flex flex-column gap-2">
                        <a class="btn btn-outline-primary" href="export-database.php">
                            <i class="fas fa-download"></i> Exportieren (SQL)
                        </a>
                        <form method="POST" action="import-database.php" enctype="multipart/form-data" class="d-inline" onsubmit="return confirm('Achtung: Der Import überschreibt bestehende Daten. Fortfahren?');">
                            <input type="file" name="database_file" accept=".sql,application/sql,text/sql" class="form-control form-control-sm mb-2" required>
                            <button type="submit" class="btn btn-outline-success">
                                <i class="fas fa-upload"></i> Importieren (SQL)
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>


