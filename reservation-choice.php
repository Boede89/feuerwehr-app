<?php
/**
 * Auswahl: Fahrzeug oder Raum reservieren
 */
session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';
require_once __DIR__ . '/includes/einheiten-setup.php';

$einheit_id_url = isset($_GET['einheit_id']) ? (int)$_GET['einheit_id'] : 0;
if ($einheit_id_url > 0) {
    if (is_logged_in() && !is_system_user()) {
        if (function_exists('user_has_einheit_access') && user_has_einheit_access($_SESSION['user_id'], $einheit_id_url)) {
            $_SESSION['current_einheit_id'] = $einheit_id_url;
        }
    } else {
        try {
            $stmt = $db->prepare("SELECT id FROM einheiten WHERE id = ? AND is_active = 1");
            $stmt->execute([$einheit_id_url]);
            if ($stmt->fetch()) $_SESSION['current_einheit_id'] = $einheit_id_url;
        } catch (Exception $e) {}
    }
}
$einheit_filter = $einheit_id_url > 0 ? $einheit_id_url : (isset($_SESSION['current_einheit_id']) ? (int)$_SESSION['current_einheit_id'] : null);
$einheit_param = $einheit_filter > 0 ? '?einheit_id=' . (int)$einheit_filter : '';
$new_tool_url = 'https://manager.lz-amern.de/';

// Eingeloggte Benutzer (inkl. Systembenutzer) brauchen Reservierungs-Berechtigung
if (is_logged_in() && !has_permission('reservations')) {
    header('Location: index.php' . $einheit_param);
    exit;
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fahrzeug oder Raum reservieren - Feuerwehr App</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="index.php<?php echo $einheit_param; ?>">
                <i class="fas fa-fire"></i> Feuerwehr App
            </a>
            <?php if (isset($_SESSION['user_id']) && !is_system_user()): ?>
                <div class="d-flex ms-auto">
                <?php
                $admin_menu_in_navbar = true;
                $admin_menu_base = 'admin/';
                $admin_menu_logout = 'logout.php';
                $admin_menu_index = 'index.php' . $einheit_param;
                include __DIR__ . '/admin/includes/admin-menu.inc.php';
                ?>
                </div>
            <?php else: ?>
                <?php if (!isset($_SESSION['user_id'])): ?>
                <div class="d-flex ms-auto align-items-center">
                    <a class="btn btn-outline-light btn-sm px-3 py-2 d-flex align-items-center gap-2" href="login.php">
                        <i class="fas fa-sign-in-alt"></i>
                        <span class="fw-semibold">Anmelden</span>
                    </a>
                </div>
                <?php else: ?>
                <?php include __DIR__ . '/includes/system-user-nav.inc.php'; ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </nav>

    <main class="container mt-4">
        <div class="row justify-content-center">
            <div class="col-lg-10">
                <div class="card shadow">
                    <div class="card-header">
                        <h3 class="mb-0">
                            <i class="fas fa-calendar-check"></i> Fahrzeug oder Raum reservieren
                        </h3>
                        <p class="text-muted mb-0">Reservierungen werden ab sofort im neuen Feuerwehr-Manager durchgeführt</p>
                    </div>
                    <div class="card-body p-4">
                        <div class="alert alert-info mb-4" role="alert">
                            <h4 class="alert-heading mb-3">
                                <i class="fas fa-info-circle"></i> Reservierungen im neuen Tool
                            </h4>
                            <p class="mb-0">
                                Diese Funktion steht ab sofort in unserem neuen Tool unter
                                <a href="<?php echo htmlspecialchars($new_tool_url, ENT_QUOTES, 'UTF-8'); ?>" class="alert-link fw-semibold">
                                    <?php echo htmlspecialchars($new_tool_url, ENT_QUOTES, 'UTF-8'); ?>
                                </a>
                                zur Verfügung.
                            </p>
                        </div>
                        <div class="text-center">
                            <a href="<?php echo htmlspecialchars($new_tool_url, ENT_QUOTES, 'UTF-8'); ?>"
                               class="btn btn-primary btn-lg">
                                <i class="fas fa-external-link-alt"></i> Zum neuen Reservierungstool
                            </a>
                        </div>
                    </div>
                    <div class="card-footer text-center">
                        <a href="index.php<?php echo $einheit_param; ?>" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left"></i> Zurück zur Startseite
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
