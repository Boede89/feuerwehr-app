<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Nur für eingeloggte Benutzer
if (!is_logged_in()) {
    redirect('../login.php');
}

$message = '';
$error = '';

// Einstellungen laden
$settings = [];
try {
    $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings");
    $stmt->execute();
    $settings_data = $stmt->fetchAll();
    
    foreach ($settings_data as $setting) {
        $settings[$setting['setting_key']] = $setting['setting_value'];
    }
} catch(PDOException $e) {
    $error = "Fehler beim Laden der Einstellungen: " . $e->getMessage();
}

// Einstellungen speichern
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Ungültiger Sicherheitstoken.";
    } else {
        try {
            $db->beginTransaction();
            
            // SMTP Einstellungen
            $smtp_settings = [
                'smtp_host' => sanitize_input($_POST['smtp_host'] ?? ''),
                'smtp_port' => sanitize_input($_POST['smtp_port'] ?? ''),
                'smtp_username' => sanitize_input($_POST['smtp_username'] ?? ''),
                'smtp_password' => trim($_POST['smtp_password'] ?? ''),
                'smtp_encryption' => sanitize_input($_POST['smtp_encryption'] ?? ''),
                'smtp_from_email' => sanitize_input($_POST['smtp_from_email'] ?? ''),
                'smtp_from_name' => sanitize_input($_POST['smtp_from_name'] ?? ''),
            ];
            
            // Google Calendar Einstellungen
            $google_settings = [
                'google_calendar_api_key' => sanitize_input($_POST['google_calendar_api_key'] ?? ''),
                'google_calendar_id' => sanitize_input($_POST['google_calendar_id'] ?? ''),
            ];
            
            // App Einstellungen
            $app_settings = [
                'app_name' => sanitize_input($_POST['app_name'] ?? ''),
                'app_url' => sanitize_input($_POST['app_url'] ?? ''),
            ];
            
            // Alle Einstellungen speichern
            $all_settings = array_merge($smtp_settings, $google_settings, $app_settings);
            
            foreach ($all_settings as $key => $value) {
                $stmt = $db->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?");
                $stmt->execute([$value, $key]);
            }
            
            $db->commit();
            $message = "Einstellungen wurden erfolgreich gespeichert.";
            log_activity($_SESSION['user_id'], 'settings_updated', 'Einstellungen aktualisiert');
            
            // Einstellungen neu laden
            $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings");
            $stmt->execute();
            $settings_data = $stmt->fetchAll();
            
            foreach ($settings_data as $setting) {
                $settings[$setting['setting_key']] = $setting['setting_value'];
            }
            
        } catch(PDOException $e) {
            $db->rollBack();
            $error = "Fehler beim Speichern der Einstellungen: " . $e->getMessage();
        }
    }
}

// Test E-Mail senden
if (isset($_POST['test_email'])) {
    $test_email = sanitize_input($_POST['test_email'] ?? '');
    
    if (empty($test_email) || !validate_email($test_email)) {
        $error = "Bitte geben Sie eine gültige E-Mail-Adresse ein.";
    } else {
        $subject = "Test E-Mail - Feuerwehr App";
        $message_content = "
        <h2>Test E-Mail</h2>
        <p>Diese E-Mail wurde als Test von der Feuerwehr App gesendet.</p>
        <p>Falls Sie diese E-Mail erhalten haben, funktioniert die E-Mail-Konfiguration korrekt.</p>
        <p><strong>Zeitstempel:</strong> " . date('d.m.Y H:i:s') . "</p>
        ";
        
        if (send_email($test_email, $subject, $message_content)) {
            $message = "Test E-Mail wurde erfolgreich gesendet.";
        } else {
            $error = "Fehler beim Senden der Test E-Mail. Bitte überprüfen Sie die SMTP-Einstellungen.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Einstellungen - Feuerwehr App</title>
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
                    <li class="nav-item">
                        <a class="nav-link" href="dashboard.php">
                            <i class="fas fa-tachometer-alt"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="reservations.php">
                            <i class="fas fa-calendar-check"></i> Reservierungen
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="vehicles.php">
                            <i class="fas fa-truck"></i> Fahrzeuge
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="users.php">
                            <i class="fas fa-users"></i> Benutzer
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="settings.php">
                            <i class="fas fa-cog"></i> Einstellungen
                        </a>
                    </li>
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
                    <i class="fas fa-cog"></i> Einstellungen
                </h1>
                
                <?php if ($message): ?>
                    <?php echo show_success($message); ?>
                <?php endif; ?>
                
                <?php if ($error): ?>
                    <?php echo show_error($error); ?>
                <?php endif; ?>
            </div>
        </div>

        <form method="POST">
            <div class="row">
                <!-- SMTP Einstellungen -->
                <div class="col-lg-6 mb-4">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-envelope"></i> SMTP Einstellungen
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="smtp_host" class="form-label">SMTP Host</label>
                                    <input type="text" class="form-control" id="smtp_host" name="smtp_host" 
                                           value="<?php echo htmlspecialchars($settings['smtp_host'] ?? ''); ?>">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="smtp_port" class="form-label">SMTP Port</label>
                                    <input type="number" class="form-control" id="smtp_port" name="smtp_port" 
                                           value="<?php echo htmlspecialchars($settings['smtp_port'] ?? ''); ?>">
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="smtp_username" class="form-label">Benutzername</label>
                                    <input type="text" class="form-control" id="smtp_username" name="smtp_username" 
                                           value="<?php echo htmlspecialchars($settings['smtp_username'] ?? ''); ?>">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="smtp_password" class="form-label">Passwort</label>
                                    <input type="password" class="form-control" id="smtp_password" name="smtp_password" 
                                           placeholder="Leer lassen um nicht zu ändern">
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="smtp_encryption" class="form-label">Verschlüsselung</label>
                                    <select class="form-select" id="smtp_encryption" name="smtp_encryption">
                                        <option value="none" <?php echo ($settings['smtp_encryption'] ?? '') == 'none' ? 'selected' : ''; ?>>Keine</option>
                                        <option value="tls" <?php echo ($settings['smtp_encryption'] ?? '') == 'tls' ? 'selected' : ''; ?>>TLS</option>
                                        <option value="ssl" <?php echo ($settings['smtp_encryption'] ?? '') == 'ssl' ? 'selected' : ''; ?>>SSL</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="smtp_from_email" class="form-label">Absender E-Mail</label>
                                    <input type="email" class="form-control" id="smtp_from_email" name="smtp_from_email" 
                                           value="<?php echo htmlspecialchars($settings['smtp_from_email'] ?? ''); ?>">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="smtp_from_name" class="form-label">Absender Name</label>
                                    <input type="text" class="form-control" id="smtp_from_name" name="smtp_from_name" 
                                           value="<?php echo htmlspecialchars($settings['smtp_from_name'] ?? ''); ?>">
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="test_email" class="form-label">Test E-Mail senden</label>
                                <div class="input-group">
                                    <input type="email" class="form-control" id="test_email" name="test_email" 
                                           placeholder="test@example.com">
                                    <button type="submit" class="btn btn-outline-primary" name="test_email_btn">
                                        <i class="fas fa-paper-plane"></i> Senden
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Google Calendar Einstellungen -->
                <div class="col-lg-6 mb-4">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-calendar"></i> Google Calendar Einstellungen
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label for="google_calendar_api_key" class="form-label">API Schlüssel</label>
                                <input type="text" class="form-control" id="google_calendar_api_key" name="google_calendar_api_key" 
                                       value="<?php echo htmlspecialchars($settings['google_calendar_api_key'] ?? ''); ?>">
                                <div class="form-text">
                                    <a href="https://console.developers.google.com/" target="_blank">Google Cloud Console</a> öffnen
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="google_calendar_id" class="form-label">Kalender ID</label>
                                <input type="text" class="form-control" id="google_calendar_id" name="google_calendar_id" 
                                       value="<?php echo htmlspecialchars($settings['google_calendar_id'] ?? ''); ?>">
                                <div class="form-text">
                                    Standard: primary (für Hauptkalender)
                                </div>
                            </div>
                            
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i>
                                <strong>Hinweis:</strong> Für die Google Calendar Integration müssen Sie:
                                <ol class="mb-0 mt-2">
                                    <li>Ein Google Cloud Projekt erstellen</li>
                                    <li>Die Calendar API aktivieren</li>
                                    <li>Einen API-Schlüssel erstellen</li>
                                    <li>Den Kalender freigeben (falls nötig)</li>
                                </ol>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- App Einstellungen -->
            <div class="row">
                <div class="col-lg-6 mb-4">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-cog"></i> App Einstellungen
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label for="app_name" class="form-label">App Name</label>
                                <input type="text" class="form-control" id="app_name" name="app_name" 
                                       value="<?php echo htmlspecialchars($settings['app_name'] ?? ''); ?>">
                            </div>
                            
                            <div class="mb-3">
                                <label for="app_url" class="form-label">App URL</label>
                                <input type="url" class="form-control" id="app_url" name="app_url" 
                                       value="<?php echo htmlspecialchars($settings['app_url'] ?? ''); ?>">
                                <div class="form-text">
                                    Vollständige URL der Anwendung (z.B. https://feuerwehr-app.example.com)
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-12">
                    <div class="d-flex justify-content-end">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Einstellungen speichern
                        </button>
                    </div>
                </div>
            </div>

            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
        </form>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
