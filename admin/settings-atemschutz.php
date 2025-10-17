<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['user_id']) || !hasAdminPermission()) {
    header('Location: ../login.php?error=access_denied');
    exit;
}

$message = '';
$error = '';

// Ensure settings table exists
try {
    $db->exec("CREATE TABLE IF NOT EXISTS settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(191) UNIQUE NOT NULL,
        setting_value LONGTEXT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (Exception $e) { /* ignore */ }

// Ensure email templates table exists
try {
    $db->exec("CREATE TABLE IF NOT EXISTS email_templates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        template_key VARCHAR(50) NOT NULL UNIQUE,
        template_name VARCHAR(100) NOT NULL,
        subject VARCHAR(200) NOT NULL,
        body TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    
    // Standard-E-Mail-Vorlagen einfügen, falls noch nicht vorhanden
    $templates = [
        [
            'template_key' => 'strecke_warnung',
            'template_name' => 'Strecke - Erinnerung (Gelb)',
            'subject' => 'Erinnerung: Strecke-Zertifikat läuft bald ab',
            'body' => '<p>Hallo {first_name} {last_name},</p><p>Ihr Strecke-Zertifikat läuft am <strong>{expiry_date}</strong> ab.</p><p>Bitte vereinbaren Sie rechtzeitig einen Termin für die Verlängerung.</p><p>Mit freundlichen Grüßen<br>Ihre Feuerwehr</p>'
        ],
        [
            'template_key' => 'strecke_abgelaufen',
            'template_name' => 'Strecke - Aufforderung (Rot)',
            'subject' => 'ACHTUNG: Strecke-Zertifikat ist abgelaufen',
            'body' => '<p>Hallo {first_name} {last_name},</p><p>Ihr Strecke-Zertifikat ist seit dem <strong style="color: red;">{expiry_date}</strong> abgelaufen!</p><p><strong style="color: red;">Sie dürfen bis zur Verlängerung nicht am Atemschutz teilnehmen.</strong></p><p>Bitte vereinbaren Sie <strong>SOFORT</strong> einen Termin für die Verlängerung.</p><p>Mit freundlichen Grüßen<br>Ihre Feuerwehr</p>'
        ],
        [
            'template_key' => 'g263_warnung',
            'template_name' => 'G26.3 - Erinnerung (Gelb)',
            'subject' => 'Erinnerung: G26.3-Untersuchung läuft bald ab',
            'body' => '<p>Hallo {first_name} {last_name},</p><p>Ihre G26.3-Untersuchung läuft am <strong>{expiry_date}</strong> ab.</p><p>Bitte vereinbaren Sie rechtzeitig einen Termin beim Betriebsarzt.</p><p>Mit freundlichen Grüßen<br>Ihre Feuerwehr</p>'
        ],
        [
            'template_key' => 'g263_abgelaufen',
            'template_name' => 'G26.3 - Aufforderung (Rot)',
            'subject' => 'ACHTUNG: G26.3-Untersuchung ist abgelaufen',
            'body' => '<p>Hallo {first_name} {last_name},</p><p>Ihre G26.3-Untersuchung ist seit dem <strong style="color: red;">{expiry_date}</strong> abgelaufen!</p><p><strong style="color: red;">Sie dürfen bis zur neuen Untersuchung nicht am Atemschutz teilnehmen.</strong></p><p>Bitte vereinbaren Sie <strong>SOFORT</strong> einen Termin beim Betriebsarzt.</p><p>Mit freundlichen Grüßen<br>Ihre Feuerwehr</p>'
        ],
        [
            'template_key' => 'uebung_warnung',
            'template_name' => 'Übung/Einsatz - Erinnerung (Gelb)',
            'subject' => 'Erinnerung: Übung/Einsatz-Zertifikat läuft bald ab',
            'body' => '<p>Hallo {first_name} {last_name},</p><p>Ihr Übung/Einsatz-Zertifikat läuft am <strong>{expiry_date}</strong> ab.</p><p>Bitte nehmen Sie rechtzeitig an einer Übung oder einem Einsatz teil.</p><p>Mit freundlichen Grüßen<br>Ihre Feuerwehr</p>'
        ],
        [
            'template_key' => 'uebung_abgelaufen',
            'template_name' => 'Übung/Einsatz - Aufforderung (Rot)',
            'subject' => 'ACHTUNG: Übung/Einsatz-Zertifikat ist abgelaufen',
            'body' => '<p>Hallo {first_name} {last_name},</p><p>Ihr Übung/Einsatz-Zertifikat ist seit dem <strong style="color: red;">{expiry_date}</strong> abgelaufen!</p><p><strong style="color: red;">Sie dürfen bis zur Teilnahme an einer Übung oder einem Einsatz nicht am Atemschutz teilnehmen.</strong></p><p>Bitte nehmen Sie <strong>SOFORT</strong> an einer Übung oder einem Einsatz teil.</p><p>Mit freundlichen Grüßen<br>Ihre Feuerwehr</p>'
        ]
    ];
    
    $stmt = $db->prepare("INSERT IGNORE INTO email_templates (template_key, template_name, subject, body) VALUES (?, ?, ?, ?)");
    foreach ($templates as $template) {
        $stmt->execute([
            $template['template_key'],
            $template['template_name'],
            $template['subject'],
            $template['body']
        ]);
    }
} catch (Exception $e) { /* ignore */ }

// Load current value
$warnDays = 90;
try {
    $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'atemschutz_warn_days' LIMIT 1");
    $stmt->execute();
    $val = $stmt->fetchColumn();
    if ($val !== false && is_numeric($val)) { $warnDays = (int)$val; }
} catch (Exception $e) {}

// E-Mail-Vorlagen laden
$emailTemplates = [];
try {
    $stmt = $db->prepare("SELECT * FROM email_templates ORDER BY template_key");
    $stmt->execute();
    $emailTemplates = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { /* ignore */ }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Ungültiger Sicherheitstoken.';
    } else {
        // Warnschwelle speichern
        $newWarn = (int)($_POST['warn_days'] ?? 90);
        if ($newWarn < 0) { $newWarn = 0; }
        try {
            $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('atemschutz_warn_days', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            $stmt->execute([$newWarn]);
            $warnDays = $newWarn;
        } catch (Exception $e) {
            $error = 'Speichern der Warnschwelle fehlgeschlagen: ' . htmlspecialchars($e->getMessage());
        }
        
        // Debug: POST-Daten loggen
        error_log("E-Mail-Vorlagen POST-Daten: " . print_r($_POST['email_templates'] ?? 'NICHT GESETZT', true));
        
        // E-Mail-Vorlagen speichern
        if (isset($_POST['email_templates']) && is_array($_POST['email_templates'])) {
            try {
                $stmt = $db->prepare("UPDATE email_templates SET subject = ?, body = ? WHERE template_key = ?");
                $updatedCount = 0;
                
                foreach ($_POST['email_templates'] as $templateKey => $templateData) {
                    if (is_array($templateData) && isset($templateData['subject']) && isset($templateData['body'])) {
                        $subject = trim($templateData['subject']);
                        $body = trim($templateData['body']);
                        
                        error_log("Speichere Template '$templateKey': Subject='$subject', Body='" . substr($body, 0, 50) . "...'");
                        
                        $result = $stmt->execute([$subject, $body, $templateKey]);
                        
                        if ($result) {
                            $updatedCount++;
                            error_log("Template '$templateKey' erfolgreich gespeichert");
                        } else {
                            error_log("Template '$templateKey' Speicherung fehlgeschlagen");
                        }
                    } else {
                        error_log("Template '$templateKey' hat ungültige Daten: " . print_r($templateData, true));
                    }
                }
                
                if ($updatedCount > 0) {
                    $message = "Einstellungen und $updatedCount E-Mail-Vorlagen gespeichert.";
                } else {
                    $error = 'Keine E-Mail-Vorlagen konnten gespeichert werden.';
                }
            } catch (Exception $e) {
                $error = 'Speichern der E-Mail-Vorlagen fehlgeschlagen: ' . htmlspecialchars($e->getMessage());
                error_log("E-Mail-Vorlagen Speicherfehler: " . $e->getMessage());
            }
        } else {
            $message = 'Einstellung gespeichert.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Atemschutz – Einstellungen</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
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
        <h1 class="h3 mb-4"><i class="fas fa-lungs"></i> Atemschutz – Einstellungen</h1>

        <?php if ($message): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <!-- Warnschwelle -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-triangle-exclamation"></i> Warnschwelle</h5>
            </div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                    <div class="row g-3 align-items-end">
                        <div class="col-12 col-md-4">
                            <label class="form-label">Warnschwelle (Tage bis Ablauf)</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-triangle-exclamation"></i></span>
                                <input type="number" min="0" class="form-control" name="warn_days" value="<?php echo (int)$warnDays; ?>">
                            </div>
                            <div class="form-text">Bis-Daten werden innerhalb dieser Schwelle gelb markiert.</div>
                        </div>
                        <div class="col-12 col-md-3">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Speichern</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- E-Mail-Vorlagen -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-envelope"></i> E-Mail-Vorlagen</h5>
            </div>
            <div class="card-body">
                <form method="post" id="emailTemplatesForm">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                    
                    <?php foreach ($emailTemplates as $template): ?>
                    <div class="card mb-3">
                        <div class="card-header">
                            <h6 class="mb-0"><?php echo htmlspecialchars($template['template_name']); ?></h6>
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-12">
                                    <label class="form-label">Betreff</label>
                                    <input type="text" class="form-control" 
                                           name="email_templates[<?php echo htmlspecialchars($template['template_key']); ?>][subject]" 
                                           value="<?php echo htmlspecialchars($template['subject']); ?>">
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Nachricht</label>
                                    <textarea class="form-control" rows="6" 
                                              name="email_templates[<?php echo htmlspecialchars($template['template_key']); ?>][body]"><?php echo htmlspecialchars($template['body']); ?></textarea>
                                    <div class="form-text">
                                        <strong>Verfügbare Platzhalter:</strong><br>
                                        <code>{first_name}</code> - Vorname<br>
                                        <code>{last_name}</code> - Nachname<br>
                                        <code>{expiry_date}</code> - Ablaufdatum
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    
                    <div class="d-flex justify-content-between">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> E-Mail-Vorlagen speichern
                        </button>
                        <a href="settings.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left"></i> Zurück zur Übersicht
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>


