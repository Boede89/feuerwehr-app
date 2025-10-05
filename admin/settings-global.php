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

// Laden
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

// Speichern
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Ungültiger Sicherheitstoken.';
    } else {
        try {
            $db->beginTransaction();

            // SMTP
            $smtp = [
                'smtp_host' => sanitize_input($_POST['smtp_host'] ?? ''),
                'smtp_port' => sanitize_input($_POST['smtp_port'] ?? ''),
                'smtp_username' => sanitize_input($_POST['smtp_username'] ?? ''),
                'smtp_encryption' => sanitize_input($_POST['smtp_encryption'] ?? ''),
                'smtp_from_email' => sanitize_input($_POST['smtp_from_email'] ?? ''),
                'smtp_from_name' => sanitize_input($_POST['smtp_from_name'] ?? ''),
            ];
            if (!empty(trim($_POST['smtp_password'] ?? ''))) {
                $smtp['smtp_password'] = trim($_POST['smtp_password']);
            } else {
                $smtp['smtp_password'] = $settings['smtp_password'] ?? '';
            }

            // Google
            $json_content = trim($_POST['google_calendar_service_account_json'] ?? '');
            $file_path = sanitize_input($_POST['google_calendar_service_account_file'] ?? '');
            $google = [
                'google_calendar_service_account_file' => $file_path,
                'google_calendar_service_account_json' => $json_content,
                'google_calendar_id' => sanitize_input($_POST['google_calendar_id'] ?? ''),
                'google_calendar_auth_type' => sanitize_input($_POST['google_calendar_auth_type'] ?? 'service_account'),
            ];

            // App
            $app = [
                'app_name' => sanitize_input($_POST['app_name'] ?? ''),
                'app_url' => sanitize_input($_POST['app_url'] ?? ''),
            ];

            // Benutzer – hier nur Hinweis; Verwaltung ggf. in users.php

            $all = array_merge($smtp, $google, $app);
            foreach ($all as $k => $v) {
                $stmt = $db->prepare('UPDATE settings SET setting_value = ? WHERE setting_key = ?');
                $stmt->execute([$v, $k]);
            }

            $db->commit();
            $message = 'Globale Einstellungen gespeichert.';
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
    <title>Globale Einstellungen</title>
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
    <h1 class="h3 mb-4"><i class="fas fa-gear"></i> Globale Einstellungen</h1>
    <?php if ($message) echo show_success($message); ?>
    <?php if ($error) echo show_error($error); ?>

    <form method="POST">
        <div class="row g-4">
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header"><i class="fas fa-envelope"></i> SMTP</div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">SMTP Host</label>
                                <input class="form-control" name="smtp_host" value="<?php echo htmlspecialchars($settings['smtp_host'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">SMTP Port</label>
                                <input class="form-control" type="number" name="smtp_port" value="<?php echo htmlspecialchars($settings['smtp_port'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Benutzername</label>
                                <input class="form-control" name="smtp_username" value="<?php echo htmlspecialchars($settings['smtp_username'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Passwort</label>
                                <input class="form-control" type="password" name="smtp_password" placeholder="Leer lassen zum Beibehalten">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Verschlüsselung</label>
                                <select class="form-select" name="smtp_encryption">
                                    <option value="none" <?php echo (($settings['smtp_encryption'] ?? '')==='none')?'selected':''; ?>>Keine</option>
                                    <option value="tls" <?php echo (($settings['smtp_encryption'] ?? '')==='tls')?'selected':''; ?>>TLS</option>
                                    <option value="ssl" <?php echo (($settings['smtp_encryption'] ?? '')==='ssl')?'selected':''; ?>>SSL</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Absender E-Mail</label>
                                <input class="form-control" type="email" name="smtp_from_email" value="<?php echo htmlspecialchars($settings['smtp_from_email'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Absender Name</label>
                            <input class="form-control" name="smtp_from_name" value="<?php echo htmlspecialchars($settings['smtp_from_name'] ?? ''); ?>">
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header"><i class="fas fa-calendar"></i> Google Calendar</div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label">Authentifizierung</label>
                            <select class="form-select" name="google_calendar_auth_type">
                                <option value="service_account" <?php echo (($settings['google_calendar_auth_type'] ?? 'service_account')==='service_account')?'selected':''; ?>>Service Account</option>
                                <option value="api_key" <?php echo (($settings['google_calendar_auth_type'] ?? '')==='api_key')?'selected':''; ?>>API Key</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Service Account JSON-Datei</label>
                            <input class="form-control" name="google_calendar_service_account_file" value="<?php echo htmlspecialchars($settings['google_calendar_service_account_file'] ?? ''); ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Service Account JSON-Inhalt</label>
                            <textarea class="form-control" rows="6" name="google_calendar_service_account_json"><?php echo htmlspecialchars($settings['google_calendar_service_account_json'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Kalender ID</label>
                            <input class="form-control" name="google_calendar_id" value="<?php echo htmlspecialchars($settings['google_calendar_id'] ?? ''); ?>">
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="row g-4 mt-1">
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header"><i class="fas fa-cog"></i> App</div>
                    <div class="card-body">
                        <div class="mb-3"><label class="form-label">App Name</label><input class="form-control" name="app_name" value="<?php echo htmlspecialchars($settings['app_name'] ?? ''); ?>"></div>
                        <div class="mb-3"><label class="form-label">App URL</label><input class="form-control" name="app_url" value="<?php echo htmlspecialchars($settings['app_url'] ?? ''); ?>"></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="d-flex justify-content-end mt-3">
            <button class="btn btn-primary" type="submit"><i class="fas fa-save"></i> Speichern</button>
        </div>
        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
    </form>

    <hr class="my-5">
    <h2 class="h5"><i class="fas fa-shield-halved"></i> Sicherung & Wiederherstellung</h2>
    <div class="row g-4">
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-body">
                    <h5 class="card-title"><i class="fas fa-file-arrow-down"></i> Einstellungen</h5>
                    <p class="text-muted">Exportieren und importieren Sie die globalen Einstellungen als JSON.</p>
                    <div class="d-flex flex-column gap-2">
                        <a class="btn btn-outline-primary" href="export-settings.php">
                            <i class="fas fa-download"></i> Exportieren (JSON)
                        </a>
                        <form method="POST" action="import-settings.php" enctype="multipart/form-data" class="d-inline">
                            <input type="file" name="settings_file" accept="application/json,.json" class="form-control form-control-sm mb-2" required>
                            <button type="submit" class="btn btn-outline-success">
                                <i class="fas fa-upload"></i> Importieren (JSON)
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-body">
                    <h5 class="card-title"><i class="fas fa-database"></i> Gesamte Datenbank</h5>
                    <p class="text-muted">Kompletter SQL-Dump (Schema und Daten) der App-Datenbank.</p>
                    <div class="d-flex flex-column gap-2">
                        <a class="btn btn-outline-primary" href="export-database.php">
                            <i class="fas fa-download"></i> Exportieren (SQL)
                        </a>
                        <form method="POST" action="import-database.php" enctype="multipart/form-data" class="d-inline">
                            <input type="file" name="database_file" accept=".sql,application/sql,text/sql" class="form-control form-control-sm mb-2" required>
                            <button type="submit" class="btn btn-outline-success" onclick="return confirm('Achtung: Der Datenbank-Import überschreibt bestehende Daten. Fortfahren?');">
                                <i class="fas fa-upload"></i> Importieren (SQL)
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>


