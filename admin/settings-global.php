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

// Import-Erfolgsmeldungen prüfen
if (isset($_GET['import']) && $_GET['import'] === 'success') {
    $message = 'Einstellungen wurden erfolgreich importiert!';
}
if (isset($_GET['dbimport']) && $_GET['dbimport'] === 'success') {
    $message = 'Datenbank wurde erfolgreich importiert!';
}

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

            // Drucker (lokal oder IPP/Cloud)
            $printer = [
                'printer_type' => in_array($_POST['printer_type'] ?? '', ['local', 'ipp']) ? $_POST['printer_type'] : 'local',
                'printer_destination' => sanitize_input($_POST['printer_destination'] ?? ''),
                'printer_cups_server' => trim(sanitize_input($_POST['printer_cups_server'] ?? '')),
                'printer_ipp_url' => sanitize_input($_POST['printer_ipp_url'] ?? ''),
                'printer_username' => sanitize_input($_POST['printer_username'] ?? ''),
            ];
            if (!empty(trim($_POST['printer_password'] ?? ''))) {
                $printer['printer_password'] = trim($_POST['printer_password']);
            } else {
                $printer['printer_password'] = $settings['printer_password'] ?? '';
            }

            $all = array_merge($smtp, $google, $app, $printer);
            foreach ($all as $k => $v) {
                $stmt = $db->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
                $stmt->execute([$k, $v]);
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
                <?php echo get_admin_navigation(); ?>
            </ul>
        </div>
    </div>
    </nav>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h3 mb-0"><i class="fas fa-gear"></i> Globale Einstellungen</h1>
        <a href="settings-backup.php" class="btn btn-outline-primary"><i class="fas fa-shield-halved"></i> Sicherung & Wiederherstellung</a>
    </div>
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
        <div class="row g-4 mt-1">
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header"><i class="fas fa-print"></i> Drucker</div>
                    <div class="card-body">
                        <p class="text-muted small mb-3">Anwesenheitslisten werden als PDF erzeugt und per CUPS (lp) gesendet. <strong>Wichtig:</strong> Im Docker-Container muss <code>CUPS_SERVER</code> in docker-compose gesetzt sein (z.B. <code>host.docker.internal</code> oder Host-IP), damit der Container den CUPS-Server des Hosts nutzt.</p>
                        <div class="mb-3">
                            <label class="form-label">Druckertyp</label>
                            <select class="form-select" name="printer_type" id="printer_type">
                                <option value="local" <?php echo ($settings['printer_type'] ?? 'local') === 'local' ? 'selected' : ''; ?>>Lokaler Drucker (CUPS)</option>
                                <option value="ipp" <?php echo ($settings['printer_type'] ?? '') === 'ipp' ? 'selected' : ''; ?>>IPP / Cloud-Drucker</option>
                            </select>
                        </div>
                        <div class="mb-3" id="printer_local_wrap" style="display: <?php echo ($settings['printer_type'] ?? 'local') === 'ipp' ? 'none' : 'block'; ?>;">
                            <label class="form-label">CUPS-Server (Docker)</label>
                            <input class="form-control" name="printer_cups_server" placeholder="z.B. 172.17.0.1 oder host.docker.internal (leer = CUPS_SERVER aus docker-compose)" value="<?php echo htmlspecialchars($settings['printer_cups_server'] ?? ''); ?>">
                            <small class="text-muted">Nur bei Docker: Host-Adresse, damit der Container den CUPS-Server des Hosts nutzt.</small>
                        </div>
                        <div class="mb-3" id="printer_local_wrap2" style="display: <?php echo ($settings['printer_type'] ?? 'local') === 'ipp' ? 'none' : 'block'; ?>;">
                            <label class="form-label">Druckername (lokal)</label>
                            <div class="input-group">
                                <input class="form-control" name="printer_destination" id="printer_destination" placeholder="z.B. HP_LaserJet oder leer für Standarddrucker" value="<?php echo htmlspecialchars($settings['printer_destination'] ?? ''); ?>">
                                <button type="button" class="btn btn-outline-secondary" id="btn_list_printers" title="Verfügbare Drucker anzeigen"><i class="fas fa-list"></i> Verfügbare Drucker</button>
                            </div>
                            <div id="printers_list" class="mt-2 small text-muted" style="display:none;"></div>
                        </div>
                        <div id="printer_ipp_wrap" style="display: <?php echo ($settings['printer_type'] ?? 'local') === 'ipp' ? 'block' : 'none'; ?>;">
                            <p class="text-muted small mb-2">Für IPP: Drucker muss zuerst in CUPS angelegt werden (z.B. auf dem Host: <code>lpadmin -p feuerwehr_ipp -E -v ipp://host/ipp/print -m everywhere</code>). Dann „Lokaler Drucker“ wählen und den Namen (z.B. feuerwehr_ipp) eintragen.</p>
                            <div class="mb-3">
                                <label class="form-label">IPP-URL / Freigabelink</label>
                                <input class="form-control" name="printer_ipp_url" placeholder="z.B. ipp://drucker.example.com/ipp/print" value="<?php echo htmlspecialchars($settings['printer_ipp_url'] ?? ''); ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Benutzername</label>
                                <input class="form-control" name="printer_username" value="<?php echo htmlspecialchars($settings['printer_username'] ?? ''); ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Passwort</label>
                                <input class="form-control" type="password" name="printer_password" placeholder="Leer lassen zum Beibehalten">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="d-flex justify-content-end mt-3">
            <button class="btn btn-primary" type="submit"><i class="fas fa-save"></i> Speichern</button>
        </div>
        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
    </form>

    
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.getElementById('printer_type')?.addEventListener('change', function() {
    var isIpp = this.value === 'ipp';
    document.getElementById('printer_local_wrap').style.display = isIpp ? 'none' : 'block';
    document.getElementById('printer_local_wrap2').style.display = isIpp ? 'none' : 'block';
    document.getElementById('printer_ipp_wrap').style.display = isIpp ? 'block' : 'none';
});
document.getElementById('btn_list_printers')?.addEventListener('click', function() {
    var btn = this;
    var out = document.getElementById('printers_list');
    out.style.display = 'block';
    out.innerHTML = '<span class="text-muted"><i class="fas fa-spinner fa-spin"></i> Lade Drucker...</span>';
    btn.disabled = true;
    fetch('../api/list-printers.php')
        .then(function(r) { return r.json(); })
        .then(function(data) {
            btn.disabled = false;
            if (data.success && data.printers && data.printers.length > 0) {
                var html = '<strong>Verfügbare Drucker:</strong> ';
                data.printers.forEach(function(p) {
                    var def = (data.default_printer === p.name) ? ' (Standard)' : '';
                    var safe = p.name.replace(/"/g, '&quot;');
                    html += '<span class="badge bg-secondary me-1" style="cursor:pointer" data-name="' + safe + '" role="button">' + p.name + def + '</span> ';
                });
                out.innerHTML = html;
                out.querySelectorAll('[data-name]').forEach(function(el) {
                    el.addEventListener('click', function() {
                        document.getElementById('printer_destination').value = this.getAttribute('data-name');
                    });
                });
            } else {
                out.innerHTML = '<span class="text-warning">' + (data.message || 'Keine Drucker gefunden. CUPS_SERVER in docker-compose setzen?') + '</span>';
            }
        })
        .catch(function() {
            btn.disabled = false;
            out.innerHTML = '<span class="text-danger">Fehler beim Laden.</span>';
        });
});
</script>
</body>
</html>


