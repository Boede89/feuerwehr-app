<?php
/**
 * Einheiten-Auswahl – neue Startseite
 * Benutzer wählt hier die Einheit, bevor er zur App weitergeleitet wird.
 */
session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';

// Nicht eingeloggt: zur Startseite (ohne Einheit) oder Login
if (!is_logged_in()) {
    header('Location: index.php');
    exit;
}

// Systembenutzer: direkt zu Formularen (ohne Einheiten-Auswahl)
if (is_system_user()) {
    // Systembenutzer sind einer Einheit zugeordnet – Standard: Einheit 1
    $_SESSION['current_unit_id'] = 1;
    header('Location: formulare.php');
    exit;
}

// Einheit per POST/GET ausgewählt?
$selected_unit = isset($_POST['unit_id']) ? (int)$_POST['unit_id'] : (isset($_GET['unit_id']) ? (int)$_GET['unit_id'] : 0);

if ($selected_unit > 0) {
    if (can_access_unit($selected_unit)) {
        $_SESSION['current_unit_id'] = $selected_unit;
        if (isset($_GET['redirect'])) {
            $redirect = basename($_GET['redirect']);
            if (in_array($redirect, ['index.php', 'admin/dashboard.php', 'formulare.php'])) {
                header('Location: ' . $redirect);
                exit;
            }
        }
        header('Location: index.php');
        exit;
    }
}

$units = get_accessible_units();

// Nur eine Einheit verfügbar: automatisch auswählen
if (count($units) === 1) {
    $_SESSION['current_unit_id'] = (int)$units[0]['id'];
    header('Location: index.php');
    exit;
}

// Keine Einheit zugewiesen (z.B. vor Migration)
if (empty($units)) {
    header('Location: login.php?error=no_units');
    exit;
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Einheit wählen – Feuerwehr App</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <style>
        .unit-card {
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            cursor: pointer;
        }
        .unit-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 16px rgba(0,0,0,0.15) !important;
        }
        .unit-card a { color: inherit; text-decoration: none; }
    </style>
</head>
<body class="bg-light">
    <nav class="navbar navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="index.php"><i class="fas fa-fire"></i> Feuerwehr App</a>
            <div class="d-flex">
                <a class="btn btn-outline-light btn-sm" href="logout.php"><i class="fas fa-sign-out-alt"></i> Abmelden</a>
            </div>
        </div>
    </nav>

    <main class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-12 col-md-10 col-lg-8">
                <div class="text-center mb-5">
                    <h1 class="display-5 text-primary">
                        <i class="fas fa-building"></i> Einheit wählen
                    </h1>
                    <p class="text-muted">Wählen Sie die Einheit, mit der Sie arbeiten möchten.</p>
                </div>

                <div class="row g-4">
                    <?php foreach ($units as $unit): ?>
                    <div class="col-12 col-sm-6">
                        <div class="card h-100 shadow-sm unit-card">
                            <a href="unit-select.php?unit_id=<?php echo (int)$unit['id']; ?>" class="d-block p-4 text-center">
                                <div class="mb-3">
                                    <i class="fas fa-fire-extinguisher text-primary" style="font-size: 3rem;"></i>
                                </div>
                                <h5 class="card-title"><?php echo htmlspecialchars($unit['name']); ?></h5>
                                <p class="text-muted small mb-0">Klicken zum Auswählen</p>
                            </a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="text-center mt-4">
                    <a href="index.php" class="text-muted text-decoration-none">
                        <i class="fas fa-arrow-left"></i> Zurück
                    </a>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
