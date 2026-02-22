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

// Einheitenverwaltung nur für Superadmins
if (!is_superadmin()) {
    header("Location: settings.php");
    exit;
}

$message = '';
$error = '';

// Einheiten laden
$einheiten = [];
try {
    $stmt = $db->query("SELECT e.*, 
        (SELECT COUNT(*) FROM members m WHERE m.einheit_id = e.id) AS members_count,
        (SELECT COUNT(*) FROM vehicles v WHERE v.einheit_id = e.id) AS vehicles_count
        FROM einheiten e ORDER BY e.sort_order, e.name");
    $einheiten = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Exception $e) {
    $error = 'Fehler beim Laden der Einheiten: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Einheiten Verwaltung - Feuerwehr App</title>
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
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="settings.php">Einstellungen</a></li>
                        <li class="breadcrumb-item active">Einheiten</li>
                    </ol>
                </nav>
                <h1 class="h3 mb-4">
                    <i class="fas fa-sitemap"></i> Einheiten Verwaltung
                </h1>
                
                <?php if ($message): ?>
                    <?php echo show_success($message); ?>
                <?php endif; ?>
                <?php if ($error): ?>
                    <?php echo show_error($error); ?>
                <?php endif; ?>

                <div class="row g-4">
                    <?php foreach ($einheiten as $e): ?>
                    <div class="col-md-6 col-lg-4">
                        <div class="card h-100 shadow-sm">
                            <div class="card-body">
                                <h5 class="card-title">
                                    <i class="fas fa-building text-primary me-2"></i>
                                    <?php echo htmlspecialchars($e['name']); ?>
                                </h5>
                                <p class="text-muted small mb-2">
                                    <?php echo (int)$e['members_count']; ?> Mitglieder &middot; 
                                    <?php echo (int)$e['vehicles_count']; ?> Fahrzeuge
                                </p>
                                <a href="settings-einheit.php?id=<?php echo (int)$e['id']; ?>" class="btn btn-primary btn-sm">
                                    <i class="fas fa-cog"></i> Einstellungen & Benutzer
                                </a>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <?php if (empty($einheiten)): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> Keine Einheiten vorhanden. Das Einheiten-System wird beim ersten Aufruf initialisiert.
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
