<?php
/**
 * Formularcenter-Einstellungen: Formulare, Dienstplan, Anwesenheitsliste in Tabs.
 */
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('Location: ../login.php');
    exit;
}
if (!hasAdminPermission()) {
    header('Location: ../login.php?error=access_denied');
    exit;
}

$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'forms';
if (!in_array($active_tab, ['forms', 'dienstplan'])) {
    $active_tab = 'forms';
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Formularcenter – Einstellungen</title>
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
                <?php echo get_admin_navigation(); ?>
            </ul>
            <ul class="navbar-nav d-flex align-items-center">
                <li class="nav-item d-flex align-items-center"><?php $admin_menu_in_navbar = true; include __DIR__ . '/includes/admin-menu.inc.php'; ?></li>
            </ul>
        </div>
    </div>
</nav>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h3 mb-0"><i class="fas fa-file-alt"></i> Formularcenter – Einstellungen</h1>
        <a href="settings.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left"></i> Zurück zu Einstellungen</a>
    </div>

    <ul class="nav nav-tabs mb-3" id="formularcenterTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <a class="nav-link <?php echo $active_tab === 'forms' ? 'active' : ''; ?>" href="?tab=forms">
                <i class="fas fa-file-alt"></i> Formulare
            </a>
        </li>
        <li class="nav-item" role="presentation">
            <a class="nav-link <?php echo $active_tab === 'dienstplan' ? 'active' : ''; ?>" href="?tab=dienstplan">
                <i class="fas fa-calendar-alt"></i> Dienstplan
            </a>
        </li>
    </ul>

    <div class="tab-content">
        <div class="tab-pane fade <?php echo $active_tab === 'forms' ? 'show active' : ''; ?>" id="tab-forms">
            <iframe src="settings-forms.php?return=formularcenter" class="border-0 w-100" style="min-height: 700px;" title="Formular-Einstellungen"></iframe>
        </div>
        <div class="tab-pane fade <?php echo $active_tab === 'dienstplan' ? 'show active' : ''; ?>" id="tab-dienstplan">
            <iframe src="settings-dienstplan.php?return=formularcenter" class="border-0 w-100" style="min-height: 500px;" title="Dienstplan-Einstellungen"></iframe>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
