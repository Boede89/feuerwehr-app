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

$message = '';
$error = '';

// Einstellungen laden
$settings = [];
try {
    $stmt = $db->prepare('SELECT setting_key, setting_value FROM settings');
    $stmt->execute();
    foreach ($stmt->fetchAll() as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Exception $e) {
    $error = 'Fehler beim Laden der Einstellungen: ' . $e->getMessage();
}

// Verfügbare Formulare
$forms = [
    'maengelbericht' => [
        'name' => 'Mängelbericht',
        'icon' => 'fa-exclamation-triangle',
        'description' => 'Einstellungen für den Mängelbericht',
        'enabled' => $settings['form_maengelbericht_enabled'] ?? '1'
    ]
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Ungültiger Sicherheitstoken.';
    } else {
        try {
            $db->beginTransaction();

            // Formular-Einstellungen speichern
            foreach ($forms as $form_key => $form_data) {
                $enabled_key = 'form_' . $form_key . '_enabled';
                $enabled_value = isset($_POST[$enabled_key]) ? '1' : '0';
                
                $stmt = $db->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
                $stmt->execute([$enabled_key, $enabled_value]);
            }

            $db->commit();
            $message = 'Formular-Einstellungen wurden erfolgreich gespeichert.';

            // Einstellungen neu laden
            $stmt = $db->prepare('SELECT setting_key, setting_value FROM settings');
            $stmt->execute();
            $settings = [];
            foreach ($stmt->fetchAll() as $row) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
            
            // Formulare aktualisieren
            foreach ($forms as $form_key => &$form_data) {
                $form_data['enabled'] = $settings['form_' . $form_key . '_enabled'] ?? '1';
            }
        } catch (Exception $e) {
            $db->rollBack();
            $error = 'Fehler beim Speichern: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Formular-Einstellungen - Feuerwehr App</title>
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
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <?php echo get_admin_navigation(); ?>
                </ul>
                <ul class="navbar-nav">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="navbarDropdown" role="button" data-bs-toggle="dropdown">
                            <i class="fas fa-user"></i> <?php echo htmlspecialchars($_SESSION['first_name'] . ' ' . $_SESSION['last_name']); ?>
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user-edit"></i> Profil</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="../logout.php"><i class="fas fa-sign-out-alt"></i> Abmelden</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container-fluid mt-4">
        <div class="row">
            <div class="col-12">
                <h1 class="h3 mb-4">
                    <i class="fas fa-file-alt"></i> Formular-Einstellungen
                </h1>
                
                <?php if ($message): ?>
                    <?php echo show_success($message); ?>
                <?php endif; ?>
                
                <?php if ($error): ?>
                    <?php echo show_error($error); ?>
                <?php endif; ?>
            </div>
        </div>

        <form method="POST" action="">
            <?php echo generate_csrf_token(); ?>
            
            <div class="row g-4">
                <?php foreach ($forms as $form_key => $form_data): ?>
                <div class="col-md-6 col-lg-4">
                    <div class="card h-100">
                        <div class="card-header bg-light">
                            <h5 class="card-title mb-0">
                                <i class="fas <?php echo htmlspecialchars($form_data['icon']); ?> me-2"></i>
                                <?php echo htmlspecialchars($form_data['name']); ?>
                            </h5>
                        </div>
                        <div class="card-body d-flex flex-column">
                            <p class="text-muted"><?php echo htmlspecialchars($form_data['description']); ?></p>
                            
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" 
                                       id="form_<?php echo htmlspecialchars($form_key); ?>_enabled" 
                                       name="form_<?php echo htmlspecialchars($form_key); ?>_enabled"
                                       <?php echo ($form_data['enabled'] == '1') ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="form_<?php echo htmlspecialchars($form_key); ?>_enabled">
                                    Formular aktiviert
                                </label>
                            </div>
                            
                            <div class="mt-auto">
                                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="collapse" 
                                        data-bs-target="#settings_<?php echo htmlspecialchars($form_key); ?>">
                                    <i class="fas fa-cog"></i> Erweiterte Einstellungen
                                </button>
                            </div>
                            
                            <div class="collapse mt-3" id="settings_<?php echo htmlspecialchars($form_key); ?>">
                                <div class="card card-body bg-light">
                                    <p class="text-muted small mb-0">
                                        <i class="fas fa-info-circle"></i> 
                                        Erweiterte Einstellungen für dieses Formular werden hier später hinzugefügt.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="row mt-4">
                <div class="col-12">
                    <div class="d-flex justify-content-between">
                        <a href="settings.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left"></i> Zurück zu Einstellungen
                        </a>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Einstellungen speichern
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

