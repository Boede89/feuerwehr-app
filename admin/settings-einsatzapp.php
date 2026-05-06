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
if ($einheit_id <= 0) {
    header("Location: settings-einheiten.php");
    exit;
}

if (!user_has_einheit_access($_SESSION['user_id'], $einheit_id)) {
    header("Location: settings-einheiten.php?error=access_denied");
    exit;
}

$einheit = null;
try {
    $stmt = $db->prepare("SELECT * FROM einheiten WHERE id = ?");
    $stmt->execute([$einheit_id]);
    $einheit = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $einheit = null;
}

if (!$einheit) {
    header("Location: settings-einheiten.php?error=not_found");
    exit;
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Einsatzapp – <?php echo htmlspecialchars($einheit['name']); ?></title>
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
                <li class="breadcrumb-item"><a href="settings-einheit.php?id=<?php echo (int)$einheit_id; ?>"><?php echo htmlspecialchars($einheit['name']); ?></a></li>
                <li class="breadcrumb-item active">Einsatzapp</li>
            </ol>
        </nav>

        <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-4">
            <h1 class="h3 mb-0">
                <i class="fas fa-mobile-screen-button text-primary"></i>
                Einsatzapp – <?php echo htmlspecialchars($einheit['name']); ?>
            </h1>
            <a href="settings-einheit.php?id=<?php echo (int)$einheit_id; ?>" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left"></i> Zurück zu Einheitseinstellungen
            </a>
        </div>

        <div class="alert alert-light border">
            <i class="fas fa-circle-info text-primary me-2"></i>
            Hier findest du alle app-bezogenen Bereiche dieser Einheit gebündelt.
        </div>

        <div class="row g-4">
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-body d-flex flex-column">
                        <h5 class="card-title"><i class="fas fa-sliders me-2 text-primary"></i>Einsatzapp Einstellungen</h5>
                        <p class="text-muted">
                            API-Tokens, IMAP-Konfiguration für Alarmdepeschen und weitere app-spezifische Einheitseinstellungen.
                        </p>
                        <div class="mt-auto">
                            <a class="btn btn-primary" href="settings-global.php?einheit_id=<?php echo (int)$einheit_id; ?>&tab=einsatzapp">
                                <i class="fas fa-wrench"></i> Einstellungen öffnen
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-body d-flex flex-column">
                        <h5 class="card-title"><i class="fas fa-file-pdf me-2 text-danger"></i>Alarmdepeschen</h5>
                        <p class="text-muted">
                            Importierte Alarmdepeschen ansehen und bei Bedarf verwalten.
                        </p>
                        <div class="mt-auto">
                            <a class="btn btn-primary" href="alarmdepeschen.php?einheit_id=<?php echo (int)$einheit_id; ?>">
                                <i class="fas fa-eye"></i> Alarmdepeschen öffnen
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-body d-flex flex-column">
                        <h5 class="card-title"><i class="fas fa-database me-2 text-primary"></i>Divera Beispieldaten</h5>
                        <p class="text-muted">
                            Gespeicherte Divera-Rohdaten für Testzwecke je Einheit einsehen und löschen.
                        </p>
                        <div class="mt-auto">
                            <a class="btn btn-primary" href="divera-samples.php?einheit_id=<?php echo (int)$einheit_id; ?>">
                                <i class="fas fa-table"></i> Beispieldaten verwalten
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

