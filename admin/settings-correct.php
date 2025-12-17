<?php
session_start();
require_once "../config/database.php";
require_once "../includes/functions.php";

// Nur für eingeloggte Benutzer mit Admin-Zugriff
if (!has_admin_access()) {
    redirect("../login.php");
}

$message = "";
$error = "";

// Einstellungen laden
$stmt = $db->prepare("SELECT setting_key, setting_value FROM settings");
$stmt->execute();
$settings_data = $stmt->fetchAll();

$settings = [];
foreach ($settings_data as $setting) {
    $settings[$setting["setting_key"]] = $setting["setting_value"];
}

// Einstellungen speichern
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["save_settings"])) {
    try {
        $db->beginTransaction();
        
        // SMTP-Einstellungen
        $smtp_settings = [
            "smtp_host" => sanitize_input($_POST["smtp_host"] ?? ""),
            "smtp_port" => sanitize_input($_POST["smtp_port"] ?? "587"),
            "smtp_username" => sanitize_input($_POST["smtp_username"] ?? ""),
            "smtp_encryption" => sanitize_input($_POST["smtp_encryption"] ?? "tls"),
            "smtp_from_email" => sanitize_input($_POST["smtp_from_email"] ?? ""),
            "smtp_from_name" => sanitize_input($_POST["smtp_from_name"] ?? ""),
        ];
        
        // WICHTIG: Passwort nur speichern wenn es eingegeben wurde
        if (!empty($_POST["smtp_password"])) {
            $smtp_settings["smtp_password"] = $_POST["smtp_password"];
            echo "<!-- Passwort wird geändert -->";
        } else {
            // Passwort NICHT ändern - aktuelles aus der Datenbank behalten
            $smtp_settings["smtp_password"] = $settings["smtp_password"] ?? "";
            echo "<!-- Passwort bleibt unverändert -->";
        }
        
        // Alle Einstellungen speichern
        foreach ($smtp_settings as $key => $value) {
            $stmt = $db->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?");
            $stmt->execute([$value, $key]);
        }
        
        $db->commit();
        $message = "Einstellungen wurden erfolgreich gespeichert.";
        
        // Einstellungen neu laden
        $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings");
        $stmt->execute();
        $settings_data = $stmt->fetchAll();
        
        foreach ($settings_data as $setting) {
            $settings[$setting["setting_key"]] = $setting["setting_value"];
        }
        
    } catch(PDOException $e) {
        $db->rollBack();
        $error = "Fehler beim Speichern der Einstellungen: " . $e->getMessage();
    }
}

// Test E-Mail senden
if (isset($_POST["test_email"])) {
    $test_email = sanitize_input($_POST["test_email"] ?? "");
    
    if (empty($test_email) || !validate_email($test_email)) {
        $error = "Bitte geben Sie eine gültige E-Mail-Adresse ein.";
    } else {
        $subject = "Test E-Mail - Feuerwehr App";
        $message_content = "
        <h2>Test E-Mail</h2>
        <p>Diese E-Mail wurde als Test von der Feuerwehr App gesendet.</p>
        <p>Falls Sie diese E-Mail erhalten haben, funktioniert die E-Mail-Konfiguration korrekt.</p>
        <p><strong>Zeitstempel:</strong> " . date("d.m.Y H:i:s") . "</p>
        <p><strong>Passwort-Status:</strong> " . (!empty($settings["smtp_password"]) ? "GESETZT" : "NICHT GESETZT") . "</p>
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
                            <i class="fas fa-user"></i> <?php echo htmlspecialchars($_SESSION["first_name"] . " " . $_SESSION["last_name"]); ?>
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
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <form method="POST">
            <div class="row">
                <!-- SMTP-Einstellungen -->
                <div class="col-lg-6 mb-4">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-envelope"></i> SMTP-Einstellungen
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label for="smtp_host" class="form-label">SMTP-Host</label>
                                <input type="text" class="form-control" id="smtp_host" name="smtp_host" 
                                       value="<?php echo htmlspecialchars($settings["smtp_host"] ?? ""); ?>">
                            </div>
                            
                            <div class="mb-3">
                                <label for="smtp_port" class="form-label">SMTP-Port</label>
                                <input type="number" class="form-control" id="smtp_port" name="smtp_port" 
                                       value="<?php echo htmlspecialchars($settings["smtp_port"] ?? "587"); ?>">
                            </div>
                            
                            <div class="mb-3">
                                <label for="smtp_username" class="form-label">SMTP-Benutzername</label>
                                <input type="text" class="form-control" id="smtp_username" name="smtp_username" 
                                       value="<?php echo htmlspecialchars($settings["smtp_username"] ?? ""); ?>">
                            </div>
                            
                            <div class="mb-3">
                                <label for="smtp_password" class="form-label">SMTP-Passwort</label>
                                <input type="password" class="form-control" id="smtp_password" name="smtp_password" 
                                       placeholder="Gmail App-Passwort eingeben">
                                <div class="form-text">
                                    <strong>Status:</strong> 
                                    <?php if (!empty($settings["smtp_password"])): ?>
                                        <span class="text-success">✅ GESETZT (<?php echo strlen($settings["smtp_password"]); ?> Zeichen)</span>
                                    <?php else: ?>
                                        <span class="text-danger">❌ NICHT GESETZT</span>
                                    <?php endif; ?><br>
                                    <strong>Hinweis:</strong> Leer lassen, um das aktuelle Passwort beizubehalten.
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="smtp_encryption" class="form-label">Verschlüsselung</label>
                                <select class="form-select" id="smtp_encryption" name="smtp_encryption">
                                    <option value="tls" <?php echo ($settings["smtp_encryption"] ?? "") == "tls" ? "selected" : ""; ?>>TLS</option>
                                    <option value="ssl" <?php echo ($settings["smtp_encryption"] ?? "") == "ssl" ? "selected" : ""; ?>>SSL</option>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label for="smtp_from_email" class="form-label">Absender-E-Mail</label>
                                <input type="email" class="form-control" id="smtp_from_email" name="smtp_from_email" 
                                       value="<?php echo htmlspecialchars($settings["smtp_from_email"] ?? ""); ?>">
                            </div>
                            
                            <div class="mb-3">
                                <label for="smtp_from_name" class="form-label">Absender-Name</label>
                                <input type="text" class="form-control" id="smtp_from_name" name="smtp_from_name" 
                                       value="<?php echo htmlspecialchars($settings["smtp_from_name"] ?? ""); ?>">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Test E-Mail -->
                <div class="col-lg-6 mb-4">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-paper-plane"></i> Test E-Mail senden
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label for="test_email" class="form-label">E-Mail-Adresse</label>
                                <input type="email" class="form-control" id="test_email" name="test_email" 
                                       value="dleuchtenberg89@gmail.com" required>
                            </div>
                            <button type="submit" name="test_email" class="btn btn-primary">
                                <i class="fas fa-paper-plane"></i> Test E-Mail senden
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="row">
                <div class="col-12">
                    <button type="submit" name="save_settings" class="btn btn-success">
                        <i class="fas fa-save"></i> Einstellungen speichern
                    </button>
                </div>
            </div>
        </form>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>