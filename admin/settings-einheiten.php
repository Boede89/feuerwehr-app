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

$error = '';

// Tabelle einheiten sicherstellen
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS einheiten (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            sort_order INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) {
    $error = 'Fehler beim Erstellen der Einheiten-Tabelle: ' . $e->getMessage();
}

// Einheiten laden
$einheiten = [];
try {
    $stmt = $db->query("SELECT id, name, sort_order FROM einheiten ORDER BY sort_order ASC, name ASC");
    $einheiten = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error = $error ?: ('Fehler beim Laden der Einheiten: ' . $e->getMessage());
}

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Einheiten Verwaltung</title>
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
        <h1 class="h3 mb-0"><i class="fas fa-sitemap"></i> Einheiten Verwaltung</h1>
        <div class="d-flex gap-2">
            <a href="settings-einheiten-verwalten.php" class="btn btn-primary">
                <i class="fas fa-cog"></i> Einheiten verwalten
            </a>
            <a href="settings.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left"></i> Zurück zu Einstellungen
            </a>
        </div>
    </div>

    <?php if ($error): ?>
        <?php echo show_error($error); ?>
    <?php endif; ?>

    <?php if (empty($einheiten)): ?>
        <div class="card shadow">
            <div class="card-body text-center py-5">
                <i class="fas fa-info-circle fa-3x text-muted mb-3"></i>
                <p class="text-muted mb-4">Noch keine Einheiten angelegt.</p>
                <p class="mb-0">Klicken Sie auf <strong>„Einheiten verwalten“</strong>, um Ihre erste Einheit (z. B. Löschzug) anzulegen.</p>
                <a href="settings-einheiten-verwalten.php" class="btn btn-primary mt-3">
                    <i class="fas fa-plus"></i> Einheiten verwalten
                </a>
            </div>
        </div>
    <?php else: ?>
        <div class="row g-4">
            <?php foreach ($einheiten as $e): ?>
            <div class="col-12 col-md-6 col-lg-4">
                <a href="settings-einheit.php?id=<?php echo (int)$e['id']; ?>" class="text-decoration-none">
                    <div class="card shadow h-100 border-primary">
                        <div class="card-body d-flex flex-column align-items-center justify-content-center py-4">
                            <i class="fas fa-truck fa-2x text-primary mb-2"></i>
                            <h5 class="card-title mb-0 text-dark"><?php echo htmlspecialchars($e['name']); ?></h5>
                            <small class="text-muted mt-1">Einstellungen öffnen</small>
                        </div>
                    </div>
                </a>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
