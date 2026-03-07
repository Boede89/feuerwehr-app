<?php
/**
 * Übersicht aller Debug-/Test-Skripte – nur für Superadmin.
 */
require_once __DIR__ . '/../includes/debug-auth.php';

// Alle Debug- und Test-Skripte sammeln (Root + admin/) – relative URLs
$scripts = [];
$root = dirname(__DIR__);

foreach (array_merge(glob($root . '/debug-*.php') ?: [], glob($root . '/test-*.php') ?: []) as $path) {
    $name = basename($path);
    if ($name === 'debug-auth.php') continue;
    $scripts[] = ['name' => $name, 'url' => '../' . $name, 'group' => 'Root'];
}

foreach (array_merge(glob($root . '/admin/debug-*.php') ?: [], glob($root . '/admin/test-*.php') ?: []) as $path) {
    $name = basename($path);
    $scripts[] = ['name' => $name, 'url' => $name, 'group' => 'Admin'];
}

usort($scripts, fn($a, $b) => strcasecmp($a['name'], $b['name']));

// Kategorien für bessere Übersicht
$categories = [
    'E-Mail' => ['debug-email-', 'debug-gmail-', 'debug-smtp-', 'test-email', 'test-gmail', 'test-send-email', 'test-external-smtp', 'test-rfc5322', 'test-beautiful-emails'],
    'Google Calendar' => ['debug-google-calendar-', 'debug-calendar-', 'debug-event-', 'test-google-calendar', 'test-calendar'],
    'Reservierung' => ['debug-reservation', 'debug-reservations-', 'debug-approval', 'debug-create-google', 'test-reservation', 'test-approve'],
    'Dashboard' => ['debug-dashboard-', 'test-dashboard'],
    'Datenbank' => ['debug-database', 'debug-admin.php', 'test-database'],
    'Divera & Admin' => ['debug-divera', 'debug-db', 'debug-room-', 'test-admin', 'test-member'],
    'Sonstige' => []
];

function get_category($name, $categories) {
    foreach ($categories as $cat => $prefixes) {
        if ($cat === 'Sonstige') continue;
        foreach ($prefixes as $p) {
            if (stripos($name, $p) === 0) return $cat;
        }
    }
    return 'Sonstige';
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Debug-Skripte – Feuerwehr App</title>
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
        <h1 class="h3 mb-4"><i class="fas fa-bug text-warning"></i> Debug- & Test-Skripte</h1>
        <p class="text-muted mb-4">Übersicht aller Debug-Skripte. Nur für Superadmins zugänglich.</p>

        <div class="mb-3">
            <a href="settings.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left"></i> Zurück zu Einstellungen</a>
        </div>

        <?php
        $by_cat = [];
        foreach ($scripts as $s) {
            $cat = get_category($s['name'], $categories);
            $by_cat[$cat][] = $s;
        }
        foreach ($categories as $cat => $_) {
            if (empty($by_cat[$cat])) continue;
            ?>
            <div class="card mb-4">
                <div class="card-header bg-light">
                    <h5 class="mb-0"><i class="fas fa-folder-open text-warning"></i> <?php echo htmlspecialchars($cat); ?></h5>
                </div>
                <div class="card-body">
                    <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-2">
                        <?php foreach ($by_cat[$cat] as $s): ?>
                        <div class="col">
                            <a href="<?php echo htmlspecialchars($s['url']); ?>" target="_blank" class="btn btn-outline-warning btn-sm w-100 text-start d-flex align-items-center justify-content-between">
                                <span><i class="fas fa-external-link-alt me-1"></i> <?php echo htmlspecialchars($s['name']); ?></span>
                                <span class="badge bg-secondary"><?php echo htmlspecialchars($s['group']); ?></span>
                            </a>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php
        }
        ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
