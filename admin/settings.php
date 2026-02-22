<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Prüfe ob Benutzer eingeloggt ist
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header("Location: ../login.php");
    exit;
}

// Prüfe ob Benutzer Admin-Berechtigung hat
if (!hasAdminPermission()) {
    header("Location: ../login.php?error=access_denied");
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
<title>Einstellungen – Übersicht</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container-fluid">
            <a class="navbar-brand" href="../index.php">
                <i class="fas fa-fire"></i> Feuerwehr App
            </a>
            <div class="d-flex ms-auto align-items-center">
                <?php $admin_menu_in_navbar = true; include __DIR__ . '/includes/admin-menu.inc.php'; ?>
            </div>
        </div>
    </nav>

    <div class="container-fluid mt-4">
        <div class="row">
            <div class="col-12">
                <h1 class="h3 mb-4">
                    <i class="fas fa-cog"></i> Einstellungen – Übersicht
                </h1>
                
                <?php if ($message): ?>
                    <?php echo show_success($message); ?>
                <?php endif; ?>
                
                <?php if ($error): ?>
                    <?php echo show_error($error); ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="row g-4">
            <!-- Globale Einstellungen -->
            <div class="col-md-6 col-lg-4">
                <div class="card h-100">
                    <div class="card-body d-flex flex-column">
                        <h5 class="card-title"><i class="fas fa-gear"></i> Globale Einstellungen</h5>
                        <p class="text-muted">SMTP, Google Calendar, App-weite Optionen, Sicherung & Wiederherstellung.</p>
                        <div class="mt-auto">
                            <a class="btn btn-primary" href="settings-global.php">
                                <i class="fas fa-wrench"></i> Öffnen
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Benutzerverwaltung -->
            <div class="col-md-6 col-lg-4">
                <div class="card h-100">
                    <div class="card-body d-flex flex-column">
                        <h5 class="card-title"><i class="fas fa-users"></i> Benutzerverwaltung</h5>
                        <p class="text-muted">Benutzer hinzufügen, bearbeiten und Berechtigungen verwalten.</p>
                        <div class="mt-auto">
                            <a class="btn btn-primary" href="users.php">
                                <i class="fas fa-users"></i> Benutzer verwalten
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Einheiten Verwaltung -->
            <div class="col-md-6 col-lg-4">
                <div class="card h-100">
                    <div class="card-body d-flex flex-column">
                        <h5 class="card-title"><i class="fas fa-sitemap"></i> Einheiten Verwaltung</h5>
                        <p class="text-muted">Einheiten verwalten. Alle einheitsspezifischen Einstellungen (Fahrzeuge, Atemschutz, Reservierungen, Formulare, Divera, RIC, Lehrgänge, Mitglieder) finden Sie unter jeder Einheit.</p>
                        <div class="mt-auto">
                            <a class="btn btn-primary" href="settings-einheiten.php">
                                <i class="fas fa-sliders"></i> Öffnen
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>


    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
