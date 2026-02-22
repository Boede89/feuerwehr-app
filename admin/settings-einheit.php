<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('Location: ../login.php');
    exit;
}
if (!hasAdminPermission()) {
    header('Location: ../login.php?error=access_denied');
    exit;
}

$einheit_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$einheit = null;

if ($einheit_id > 0) {
    try {
        $stmt = $db->prepare("SELECT id, name, sort_order FROM einheiten WHERE id = ?");
        $stmt->execute([$einheit_id]);
        $einheit = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // Tabelle kann fehlen
    }
}

if (!$einheit) {
    header('Location: settings-einheiten.php');
    exit;
}

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Einstellungen – <?php echo htmlspecialchars($einheit['name']); ?></title>
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
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0"><i class="fas fa-truck"></i> <?php echo htmlspecialchars($einheit['name']); ?></h1>
        <a href="settings-einheiten.php" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left"></i> Zurück zu Einheiten
        </a>
    </div>

    <div class="row g-4">
        <!-- Reservierungen -->
        <div class="col-md-6">
            <div class="card h-100 shadow">
                <div class="card-body d-flex flex-column">
                    <h5 class="card-title"><i class="fas fa-calendar-check"></i> Reservierungen</h5>
                    <p class="text-muted">Reservierungen verwalten und konfigurieren.</p>
                    <div class="mt-auto">
                        <a class="btn btn-primary" href="settings-reservations.php?einheit_id=<?php echo (int)$einheit['id']; ?>">
                            <i class="fas fa-sliders"></i> Öffnen
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <!-- Atemschutz -->
        <div class="col-md-6">
            <div class="card h-100 shadow">
                <div class="card-body d-flex flex-column">
                    <h5 class="card-title"><i class="fas fa-user-shield"></i> Atemschutz</h5>
                    <p class="text-muted">Schwellwert für Ablaufwarnungen festlegen.</p>
                    <div class="mt-auto">
                        <a class="btn btn-primary" href="settings-atemschutz.php?einheit_id=<?php echo (int)$einheit['id']; ?>">
                            <i class="fas fa-sliders"></i> Öffnen
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <!-- Mitgliederverwaltung -->
        <div class="col-md-6">
            <div class="card h-100 shadow">
                <div class="card-body d-flex flex-column">
                    <h5 class="card-title"><i class="fas fa-users-cog"></i> Mitgliederverwaltung</h5>
                    <p class="text-muted">Qualifikationen für Mitglieder anlegen und verwalten.</p>
                    <div class="mt-auto">
                        <a class="btn btn-primary" href="settings-members.php?einheit_id=<?php echo (int)$einheit['id']; ?>">
                            <i class="fas fa-certificate"></i> Qualifikationen verwalten
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <!-- Formularcenter -->
        <div class="col-md-6">
            <div class="card h-100 shadow">
                <div class="card-body d-flex flex-column">
                    <h5 class="card-title"><i class="fas fa-file-alt"></i> Formularcenter</h5>
                    <p class="text-muted">Formulare, Dienstplan und Anwesenheitsliste konfigurieren.</p>
                    <div class="mt-auto">
                        <a class="btn btn-primary" href="settings-formularcenter.php?einheit_id=<?php echo (int)$einheit['id']; ?>">
                            <i class="fas fa-sliders"></i> Öffnen
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <!-- Fahrzeugverwaltung -->
        <div class="col-md-6">
            <div class="card h-100 shadow">
                <div class="card-body d-flex flex-column">
                    <h5 class="card-title"><i class="fas fa-truck"></i> Fahrzeugverwaltung</h5>
                    <p class="text-muted">Fahrzeuge hinzufügen, bearbeiten und verwalten.</p>
                    <div class="mt-auto">
                        <a class="btn btn-primary" href="vehicles.php?einheit_id=<?php echo (int)$einheit['id']; ?>">
                            <i class="fas fa-truck"></i> Fahrzeuge verwalten
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <!-- Divera 24/7 -->
        <div class="col-md-6">
            <div class="card h-100 shadow">
                <div class="card-body d-flex flex-column">
                    <h5 class="card-title"><i class="fas fa-calendar-plus"></i> Divera 24/7</h5>
                    <p class="text-muted">Termin-Übermittlung genehmigter Reservierungen an Divera.</p>
                    <div class="mt-auto">
                        <a class="btn btn-primary" href="settings-divera.php?einheit_id=<?php echo (int)$einheit['id']; ?>">
                            <i class="fas fa-cog"></i> Divera Einstellungen
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <!-- RIC Verwaltung -->
        <div class="col-md-6">
            <div class="card h-100 shadow">
                <div class="card-body d-flex flex-column">
                    <h5 class="card-title"><i class="fas fa-broadcast-tower"></i> RIC Verwaltung</h5>
                    <p class="text-muted">RIC-Codes verwalten (Kurztext und Beschreibung).</p>
                    <div class="mt-auto">
                        <a class="btn btn-primary" href="settings-ric.php?einheit_id=<?php echo (int)$einheit['id']; ?>">
                            <i class="fas fa-cog"></i> RIC-Codes verwalten
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <!-- Lehrgangsverwaltung -->
        <div class="col-md-6">
            <div class="card h-100 shadow">
                <div class="card-body d-flex flex-column">
                    <h5 class="card-title"><i class="fas fa-graduation-cap"></i> Lehrgangsverwaltung</h5>
                    <p class="text-muted">Lehrgänge definieren und Anforderungen festlegen.</p>
                    <div class="mt-auto">
                        <a class="btn btn-primary" href="settings-courses.php?einheit_id=<?php echo (int)$einheit['id']; ?>">
                            <i class="fas fa-cog"></i> Lehrgänge verwalten
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
