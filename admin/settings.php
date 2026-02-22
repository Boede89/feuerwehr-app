<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once __DIR__ . '/../includes/einheiten-setup.php';

// Prüfe ob Benutzer eingeloggt ist
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header("Location: ../login.php");
    exit;
}

// Prüfe ob Benutzer Admin-Berechtigung hat
if (!hasAdminPermission()) {
    header("Location: ../login.php?error=access_denied");
    exit;
}

// Einheitsadmins werden zu ihren Einheiten-Einstellungen weitergeleitet
if (is_einheitsadmin()) {
    $eid = get_current_einheit_id();
    if ($eid) {
        header("Location: settings-einheit.php?id=" . (int)$eid);
        exit;
    }
}

$message = '';
$error = '';
if (isset($_GET['error']) && $_GET['error'] === 'superadmin_only') {
    $error = 'Die Benutzerverwaltung (Superadmin/Einheitsadmin anlegen) ist nur für Superadmins zugänglich.';
}

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
                'smtp_encryption' => sanitize_input($_POST['smtp_encryption'] ?? ''),
                'smtp_from_email' => sanitize_input($_POST['smtp_from_email'] ?? ''),
                'smtp_from_name' => sanitize_input($_POST['smtp_from_name'] ?? ''),
            ];
            
            // Passwort nur speichern wenn es eingegeben wurde
            if (!empty(trim($_POST['smtp_password'] ?? ''))) {
                $smtp_settings['smtp_password'] = trim($_POST['smtp_password']);
            } else {
                // Passwort nicht ändern - aktuelles aus der Datenbank behalten
                $smtp_settings['smtp_password'] = $settings['smtp_password'] ?? '';
            }
            
            // Google Calendar Einstellungen
            // JSON-Inhalt nur trimmen, NICHT htmlspecialchars verwenden!
            $json_content = trim($_POST['google_calendar_service_account_json'] ?? '');
            $file_path = sanitize_input($_POST['google_calendar_service_account_file'] ?? '');
            
            // Debug: Logge die POST-Daten
            error_log("JSON Content Length: " . strlen($json_content));
            error_log("JSON Content Preview: " . substr($json_content, 0, 100));
            error_log("File Path: " . $file_path);
            
            $google_settings = [
                'google_calendar_service_account_file' => $file_path,
                'google_calendar_service_account_json' => $json_content, // Nur trimmen, nicht sanitizen!
                'google_calendar_id' => sanitize_input($_POST['google_calendar_id'] ?? ''),
                'google_calendar_auth_type' => sanitize_input($_POST['google_calendar_auth_type'] ?? 'service_account'),
            ];
            
            // App Einstellungen
            $app_settings = [
                'app_name' => sanitize_input($_POST['app_name'] ?? ''),
                'app_url' => sanitize_input($_POST['app_url'] ?? ''),
            ];
            
            // Fahrzeug-Einstellungen
            $vehicle_settings = [
                'vehicle_sort_mode' => sanitize_input($_POST['vehicle_sort_mode'] ?? 'manual'),
            ];
            
            // Alle Einstellungen speichern
            $all_settings = array_merge($smtp_settings, $google_settings, $app_settings, $vehicle_settings);
            
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
if (isset($_POST['test_email_btn'])) {
    $test_email = sanitize_input($_POST['test_email'] ?? '');
    
    // Nur senden wenn E-Mail eingegeben wurde
    if (!empty($test_email)) {
        if (!validate_email($test_email)) {
            $error = "Bitte geben Sie eine gültige E-Mail-Adresse ein.";
        } else {
            $subject = "Test E-Mail - Feuerwehr App";
            $message_content = "
            <h2>Test E-Mail</h2>
            <p>Diese E-Mail wurde als Test von der Feuerwehr App gesendet.</p>
            <p>Falls Sie diese E-Mail erhalten haben, funktioniert die E-Mail-Konfiguration korrekt.</p>
            <p><strong>Zeitstempel:</strong> " . date('d.m.Y H:i:s') . "</p>
            ";
            
            if (send_email($test_email, $subject, $message_content, '', true)) {
                $message = "Test E-Mail wurde erfolgreich gesendet.";
            } else {
                $error = "Fehler beim Senden der Test E-Mail. Bitte überprüfen Sie die SMTP-Einstellungen.";
            }
        }
    }
    // Wenn E-Mail leer ist, wird einfach ignoriert (keine Fehlermeldung)
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Einstellungen – Übersicht</title>
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
            <div class="d-flex ms-auto align-items-center">
                <?php $admin_menu_in_navbar = true; include __DIR__ . '/includes/admin-menu.inc.php'; ?>
            </div>
        </div>
    </nav>

    <div class="container-fluid mt-4">
        <div class="row">
            <div class="col-12">
                <h1 class="h3 mb-4">
                    <i class="fas fa-cog"></i> Einstellungen – Übersicht
                </h1>
                
                <?php if ($message): ?>
                    <?php echo show_success($message); ?>
                <?php endif; ?>
                
                <?php if ($error): ?>
                    <?php echo show_error($error); ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="row g-4">
            <!-- Globale Einstellungen -->
            <div class="col-md-6 col-lg-4">
                <div class="card h-100">
                    <div class="card-body d-flex flex-column">
                        <h5 class="card-title"><i class="fas fa-gear"></i> Globale Einstellungen</h5>
                        <p class="text-muted">SMTP, Google Calendar, App-weite Optionen, Sicherung & Wiederherstellung.</p>
                        <div class="mt-auto">
                            <div class="d-flex gap-2 flex-wrap">
                                <a class="btn btn-primary" href="settings-global.php">
                                    <i class="fas fa-wrench"></i> Öffnen
                                </a>
                                <a class="btn btn-outline-secondary" href="settings-backup.php">
                                    <i class="fas fa-shield-halved"></i> Sicherung & Wiederherstellung
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Benutzerverwaltung (Global) – Superadmin & Einheitsadmin anlegen -->
            <div class="col-md-6 col-lg-4">
                <div class="card h-100">
                    <div class="card-body d-flex flex-column">
                        <h5 class="card-title"><i class="fas fa-users"></i> Benutzerverwaltung (Global)</h5>
                        <p class="text-muted">Superadmins (Zugriff auf alle Einheiten) und Einheitsadmins (nur Einstellungen der gewählten Einheit) anlegen.</p>
                        <div class="mt-auto">
                            <a class="btn btn-primary" href="users.php">
                                <i class="fas fa-users"></i> Benutzer verwalten
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Einheitenverwaltung -->
            <div class="col-md-6 col-lg-4">
                <div class="card h-100">
                    <div class="card-body d-flex flex-column">
                        <h5 class="card-title"><i class="fas fa-sitemap"></i> Einheitenverwaltung</h5>
                        <p class="text-muted">Einheiten und Gruppierungen verwalten.</p>
                        <div class="mt-auto">
                            <a class="btn btn-primary" href="settings-einheiten.php">
                                <i class="fas fa-sliders"></i> Öffnen
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>


    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
