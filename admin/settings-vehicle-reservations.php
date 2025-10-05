<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('Location: ../login.php');
    exit;
}
if (!has_admin_access()) {
    header('Location: ../login.php?error=access_denied');
    exit;
}

$message = '';
$error = '';

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Ungültiger Sicherheitstoken.';
    } else {
        try {
            $db->beginTransaction();

            // Fahrzeugspezifische Settings (erweiterbar)
            $veh = [
                'vehicle_sort_mode' => sanitize_input($_POST['vehicle_sort_mode'] ?? 'manual'),
                'vehicle_transfer_url' => trim((string)($_POST['vehicle_transfer_url'] ?? '')),
                'vehicle_transfer_text' => trim((string)($_POST['vehicle_transfer_text'] ?? '')),
            ];

            // Persistieren: Upsert je Einstellung
            $stmtUpsert = $db->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
            foreach ($veh as $k => $v) {
                $stmtUpsert->execute([$k, $v]);
            }

            $db->commit();
            $message = 'Fahrzeugreservierungs-Einstellungen gespeichert.';
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
    <title>Einstellungen – Fahrzeugreservierungen</title>
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
                <li class="nav-item"><a class="nav-link" href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                <li class="nav-item"><a class="nav-link" href="reservations.php"><i class="fas fa-calendar-check"></i> Reservierungen</a></li>
                <li class="nav-item"><a class="nav-link" href="vehicles.php"><i class="fas fa-truck"></i> Fahrzeuge</a></li>
                <li class="nav-item"><a class="nav-link" href="users.php"><i class="fas fa-users"></i> Benutzer</a></li>
                <li class="nav-item"><a class="nav-link active" href="settings.php"><i class="fas fa-cog"></i> Einstellungen</a></li>
            </ul>
        </div>
    </div>
    </nav>

<div class="container-fluid mt-4">
    <h1 class="h3 mb-4"><i class="fas fa-truck"></i> Einstellungen – Fahrzeugreservierungen</h1>
    <?php if ($message) echo show_success($message); ?>
    <?php if ($error) echo show_error($error); ?>

    <form method="POST">
        <div class="card mb-4">
            <div class="card-header"><i class="fas fa-list-ol"></i> Anzeige und Sortierung</div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label">Sortier-Modus</label>
                    <select class="form-select" name="vehicle_sort_mode">
                        <option value="manual" <?php echo (($settings['vehicle_sort_mode'] ?? 'manual')==='manual')?'selected':''; ?>>Manuelle Reihenfolge</option>
                        <option value="name" <?php echo (($settings['vehicle_sort_mode'] ?? '')==='name')?'selected':''; ?>>Alphabetisch nach Name</option>
                        <option value="created" <?php echo (($settings['vehicle_sort_mode'] ?? '')==='created')?'selected':''; ?>>Nach Erstellungsdatum</option>
                    </select>
                </div>
                <div class="form-text">
                    Reihenfolge kann in der <a href="vehicles.php" target="_blank">Fahrzeugverwaltung</a> angepasst werden.
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header"><i class="fas fa-right-left"></i> Termine übertragen</div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label">Ziel-URL (Weiterleitung)</label>
                    <input class="form-control" name="vehicle_transfer_url" placeholder="https://ziel.example.com/import" value="<?php echo htmlspecialchars($settings['vehicle_transfer_url'] ?? ''); ?>">
                    <div class="form-text">Auf diese URL wird nach dem Kopieren weitergeleitet.</div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Text für Zwischenablage</label>
                    <textarea class="form-control" rows="4" name="vehicle_transfer_text" placeholder="Hier den Standardtext eintragen..."><?php echo htmlspecialchars($settings['vehicle_transfer_text'] ?? ''); ?></textarea>
                    <div class="form-text">Dieser Text erscheint im Fenster und kann per Button kopiert werden.</div>
                </div>
            </div>
        </div>

        <div class="d-flex justify-content-end">
            <button class="btn btn-primary" type="submit"><i class="fas fa-save"></i> Speichern</button>
        </div>
        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
    </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>


