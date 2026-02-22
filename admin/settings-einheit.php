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

    <div class="card shadow">
        <div class="card-body text-center py-5">
            <i class="fas fa-cog fa-3x text-muted mb-3"></i>
            <p class="text-muted mb-0">Einstellungen für diese Einheit – In Kürze verfügbar.</p>
            <p class="text-muted small mt-2">Hier werden zukünftig Mitglieder, Fahrzeuge und weitere einheitsspezifische Einstellungen verwaltet.</p>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
