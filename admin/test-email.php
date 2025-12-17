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

// Test E-Mail senden
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["test_email"])) {
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
    <title>Test E-Mail - Feuerwehr App</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Test E-Mail senden</h5>
                    </div>
                    <div class="card-body">
                        <?php if ($message): ?>
                            <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
                        <?php endif; ?>
                        
                        <?php if ($error): ?>
                            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                        <?php endif; ?>
                        
                        <form method="POST">
                            <div class="mb-3">
                                <label for="test_email" class="form-label">E-Mail-Adresse</label>
                                <input type="email" class="form-control" id="test_email" name="test_email" 
                                       value="dleuchtenberg89@gmail.com" required>
                            </div>
                            <button type="submit" class="btn btn-primary">Test E-Mail senden</button>
                            <a href="settings.php" class="btn btn-secondary">Zurück zu Einstellungen</a>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>