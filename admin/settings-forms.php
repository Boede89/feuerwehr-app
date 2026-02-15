<?php
/**
 * Formularcenter – Formulare: Buttons zu den Einstellungen der einzelnen Formulare.
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

// Verfügbare Formulare mit Einstellungsseiten
$formulare = [
    'anwesenheitsliste' => [
        'name' => 'Anwesenheitsliste',
        'icon' => 'fa-clipboard-list',
        'description' => 'Felder, Reihenfolge und E-Mail-Versand für Anwesenheitslisten',
        'settings_url' => 'settings-anwesenheitsliste.php',
    ],
    'maengelbericht' => [
        'name' => 'Mängelbericht',
        'icon' => 'fa-exclamation-triangle',
        'description' => 'Einstellungen für den Mängelbericht',
        'settings_url' => 'settings-maengelbericht.php',
    ],
    'geraetewartmitteilung' => [
        'name' => 'Gerätewartmitteilung',
        'icon' => 'fa-wrench',
        'description' => 'Einstellungen für Gerätewartmitteilungen',
        'settings_url' => 'settings-geraetewartmitteilung.php',
    ],
];

$return_formularcenter = isset($_GET['return']) && $_GET['return'] === 'formularcenter';
$back_url = $return_formularcenter ? 'settings-formularcenter.php?tab=forms' : 'settings.php';
$back_label = $return_formularcenter ? 'Zurück zu Formularcenter' : 'Zurück zu Einstellungen';
$back_target = $return_formularcenter ? ' target="_parent"' : '';
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Formulare – Einstellungen</title>
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
        </div>
    </div>
</nav>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0"><i class="fas fa-file-alt"></i> Formulare – Einstellungen</h1>
        <a href="<?php echo htmlspecialchars($back_url); ?>" class="btn btn-outline-secondary"<?php echo $back_target; ?>>
            <i class="fas fa-arrow-left"></i> <?php echo htmlspecialchars($back_label); ?>
        </a>
    </div>

    <p class="text-muted mb-4">Wählen Sie ein Formular aus, um dessen Einstellungen zu bearbeiten (Felder, E-Mail-Versand etc.).</p>

    <div class="row g-4">
        <?php foreach ($formulare as $key => $f): ?>
        <div class="col-md-6 col-lg-4">
            <a href="<?php echo htmlspecialchars($f['settings_url']); ?>?return=formularcenter" class="text-decoration-none"<?php echo $return_formularcenter ? ' target="_parent"' : ''; ?>>
                <div class="card h-100 shadow-sm clickable-card" style="transition: transform 0.2s, box-shadow 0.2s;">
                    <div class="card-body text-center p-4 d-flex flex-column">
                        <div class="mb-3" style="height: 60px; display: flex; align-items: center; justify-content: center;">
                            <i class="fas <?php echo htmlspecialchars($f['icon']); ?> text-primary" style="font-size: 2.5rem;"></i>
                        </div>
                        <h5 class="card-title"><?php echo htmlspecialchars($f['name']); ?></h5>
                        <p class="card-text text-muted small flex-grow-1"><?php echo htmlspecialchars($f['description']); ?></p>
                        <span class="btn btn-outline-primary btn-sm mt-2"><i class="fas fa-cog"></i> Einstellungen öffnen</span>
                    </div>
                </div>
            </a>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.querySelectorAll('.clickable-card').forEach(function(card) {
    card.addEventListener('mouseenter', function() {
        this.style.transform = 'translateY(-4px)';
        this.style.boxShadow = '0 8px 20px rgba(0,0,0,0.12)';
    });
    card.addEventListener('mouseleave', function() {
        this.style.transform = '';
        this.style.boxShadow = '';
    });
});
</script>
</body>
</html>
