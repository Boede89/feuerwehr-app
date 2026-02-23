<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once __DIR__ . '/../includes/einheiten-setup.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header("Location: ../login.php");
    exit;
}
if (!hasAdminPermission()) {
    header("Location: ../login.php?error=access_denied");
    exit;
}

$einheit_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$einheit_id) {
    header("Location: settings-einheiten.php");
    exit;
}

if (!user_has_einheit_access($_SESSION['user_id'], $einheit_id)) {
    header("Location: settings-einheiten.php?error=access_denied");
    exit;
}

$message = '';
$error = '';

$einheit = null;
try {
    $stmt = $db->prepare("SELECT * FROM einheiten WHERE id = ?");
    $stmt->execute([$einheit_id]);
    $einheit = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {}
if (!$einheit) {
    header("Location: settings-einheiten.php?error=not_found");
    exit;
}

$einheit_base = '?id=' . $einheit_id;
$einheit_param = '&einheit_id=' . $einheit_id;
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($einheit['name']); ?> – Einstellungen</title>
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
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="settings.php">Einstellungen</a></li>
                <li class="breadcrumb-item"><a href="settings-einheiten.php">Einheiten</a></li>
                <li class="breadcrumb-item active"><?php echo htmlspecialchars($einheit['name']); ?></li>
            </ol>
        </nav>

        <div class="row">
            <div class="col-12">
                <h1 class="h3 mb-4">
                    <i class="fas fa-cog"></i> Einstellungen – <?php echo htmlspecialchars($einheit['name']); ?>
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
            <!-- Globale Einstellungen (Einheit) -->
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-body d-flex flex-column">
                        <h5 class="card-title"><i class="fas fa-gear me-2"></i>Globale Einstellungen</h5>
                        <p class="text-muted">SMTP, Google Calendar, App-Optionen für diese Einheit.</p>
                        <div class="mt-auto">
                            <a class="btn btn-secondary" href="settings-global.php?einheit_id=<?php echo $einheit_id; ?>&tab=einheit">
                                <i class="fas fa-wrench"></i> Öffnen
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Formularcenter -->
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-body d-flex flex-column">
                        <h5 class="card-title"><i class="fas fa-file-alt me-2"></i>Formularcenter</h5>
                        <p class="text-muted">Formulare, Dienstplan und Anwesenheitsliste konfigurieren.</p>
                        <div class="mt-auto">
                            <a class="btn btn-primary" href="settings-formularcenter.php?einheit_id=<?php echo $einheit_id; ?>">
                                <i class="fas fa-sliders"></i> Öffnen
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Benutzerverwaltung -->
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-body d-flex flex-column">
                        <h5 class="card-title"><i class="fas fa-users me-2"></i>Benutzerverwaltung</h5>
                        <p class="text-muted">Benutzer dieser Einheit anlegen, Berechtigungen verwalten.</p>
                        <div class="mt-auto">
                            <a class="btn btn-primary" href="settings-einheit-users.php?id=<?php echo $einheit_id; ?>">
                                <i class="fas fa-users-cog"></i> Benutzer verwalten
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Fahrzeugverwaltung -->
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-body d-flex flex-column">
                        <h5 class="card-title"><i class="fas fa-truck me-2"></i>Fahrzeugverwaltung</h5>
                        <p class="text-muted">Fahrzeuge dieser Einheit hinzufügen und verwalten.</p>
                        <div class="mt-auto">
                            <a class="btn btn-primary" href="settings-global.php?einheit_id=<?php echo $einheit_id; ?>&tab=fahrzeuge">
                                <i class="fas fa-truck"></i> Fahrzeuge verwalten
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Atemschutz -->
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-body d-flex flex-column">
                        <h5 class="card-title"><i class="fas fa-user-shield me-2"></i>Atemschutz – Einstellungen</h5>
                        <p class="text-muted">Schwellwert für Ablaufwarnungen (z.B. 90 Tage) festlegen.</p>
                        <div class="mt-auto">
                            <a class="btn btn-primary" href="settings-atemschutz.php?einheit_id=<?php echo $einheit_id; ?>">
                                <i class="fas fa-sliders"></i> Öffnen
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Lehrgangsverwaltung -->
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-body d-flex flex-column">
                        <h5 class="card-title"><i class="fas fa-graduation-cap me-2"></i>Lehrgangsverwaltung</h5>
                        <p class="text-muted">Lehrgänge definieren und Anforderungen festlegen.</p>
                        <div class="mt-auto">
                            <a class="btn btn-primary" href="settings-courses.php?einheit_id=<?php echo $einheit_id; ?>">
                                <i class="fas fa-cog"></i> Lehrgänge verwalten
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Mitgliederverwaltung -->
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-body d-flex flex-column">
                        <h5 class="card-title"><i class="fas fa-users-cog me-2"></i>Mitgliederverwaltung</h5>
                        <p class="text-muted">Qualifikationen für Mitglieder anlegen.</p>
                        <div class="mt-auto">
                            <a class="btn btn-primary" href="settings-members.php?einheit_id=<?php echo $einheit_id; ?>">
                                <i class="fas fa-certificate"></i> Qualifikationen verwalten
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Reservierungen -->
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-body d-flex flex-column">
                        <h5 class="card-title"><i class="fas fa-calendar-check me-2"></i>Reservierungen</h5>
                        <p class="text-muted">Reservierungen verwalten und konfigurieren.</p>
                        <div class="mt-auto">
                            <a class="btn btn-primary" href="settings-reservations.php?einheit_id=<?php echo $einheit_id; ?>">
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
