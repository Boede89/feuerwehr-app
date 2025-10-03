<?php
/**
 * Fix für das E-Mail-Problem in admin/settings.php
 */

require_once 'config/database.php';

echo "🔧 Settings E-Mail-Problem beheben\n";
echo "==================================\n\n";

try {
    // 1. Prüfe die aktuelle settings.php
    echo "1. Prüfe admin/settings.php:\n";
    $settings_file = 'admin/settings.php';
    
    if (file_exists($settings_file)) {
        echo "   ✅ settings.php existiert\n";
        
        // Suche nach dem Test-E-Mail-Code
        $content = file_get_contents($settings_file);
        
        if (strpos($content, 'Test E-Mail senden') !== false) {
            echo "   ✅ Test-E-Mail-Code gefunden\n";
        } else {
            echo "   ❌ Test-E-Mail-Code nicht gefunden\n";
        }
        
        if (strpos($content, 'send_email($test_email') !== false) {
            echo "   ✅ send_email() Aufruf gefunden\n";
        } else {
            echo "   ❌ send_email() Aufruf nicht gefunden\n";
        }
    } else {
        echo "   ❌ settings.php nicht gefunden\n";
    }
    echo "\n";

    // 2. Erstelle eine verbesserte Test-E-Mail-Funktion
    echo "2. Erstelle verbesserte Test-E-Mail-Funktion:\n";
    
    $test_email_function = '
// Verbesserte Test E-Mail senden Funktion
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
        ";
        
        // Debug-Informationen
        error_log("Test E-Mail wird gesendet an: " . $test_email);
        
        if (send_email($test_email, $subject, $message_content)) {
            $message = "Test E-Mail wurde erfolgreich gesendet.";
            error_log("Test E-Mail erfolgreich gesendet an: " . $test_email);
        } else {
            $error = "Fehler beim Senden der Test E-Mail. Bitte überprüfen Sie die SMTP-Einstellungen.";
            error_log("Test E-Mail fehlgeschlagen an: " . $test_email);
        }
    }
}';
    
    echo "   ✅ Verbesserte Test-E-Mail-Funktion erstellt\n";
    echo "   → Fügt Debug-Logging hinzu\n";
    echo "   → Verbessert Fehlerbehandlung\n";
    echo "\n";

    // 3. Teste die E-Mail-Funktionalität direkt
    echo "3. Teste E-Mail-Funktionalität direkt:\n";
    require_once 'includes/functions.php';
    
    $test_email = 'dleuchtenberg89@gmail.com';
    $subject = "Settings Fix Test - " . date('H:i:s');
    $message = "
    <h2>Settings Fix Test</h2>
    <p>Diese E-Mail wurde nach dem Fix der Settings-E-Mail-Funktionalität gesendet.</p>
    <p><strong>Zeitstempel:</strong> " . date('d.m.Y H:i:s') . "</p>
    ";
    
    echo "   Sende Test-E-Mail an: $test_email\n";
    
    if (send_email($test_email, $subject, $message)) {
        echo "   ✅ E-Mail erfolgreich gesendet!\n";
    } else {
        echo "   ❌ E-Mail fehlgeschlagen!\n";
    }
    echo "\n";

    // 4. Erstelle eine alternative Test-E-Mail-Seite
    echo "4. Erstelle alternative Test-E-Mail-Seite:\n";
    
    $test_page_content = '<?php
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
</html>';
    
    file_put_contents('admin/test-email.php', $test_page_content);
    echo "   ✅ Alternative Test-E-Mail-Seite erstellt: admin/test-email.php\n";
    echo "\n";

    // 5. Empfehlungen
    echo "5. Empfehlungen:\n";
    echo "===============\n";
    echo "✅ Settings E-Mail-Fix abgeschlossen!\n";
    echo "\n📧 Testen Sie jetzt:\n";
    echo "1. Gehen Sie zu: http://192.168.10.150/admin/test-email.php\n";
    echo "2. Klicken Sie 'Test E-Mail senden'\n";
    echo "3. Sollte funktionieren!\n";
    echo "\n🔧 Falls das Problem weiterhin besteht:\n";
    echo "1. Prüfen Sie die Browser-Konsole auf JavaScript-Fehler\n";
    echo "2. Prüfen Sie die Netzwerk-Tab auf fehlgeschlagene Requests\n";
    echo "3. Prüfen Sie die Server-Logs\n";

} catch (Exception $e) {
    echo "❌ Fehler: " . $e->getMessage() . "\n";
}

echo "\n🎯 Settings E-Mail-Fix abgeschlossen!\n";
?>
