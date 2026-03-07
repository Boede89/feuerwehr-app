<?php
require_once __DIR__ . '/includes/debug-auth.php';
/**
 * Einfaches E-Mail-Debug-Skript
 */

require_once 'config/database.php';
require_once 'includes/functions.php';

echo "<h1>🔍 E-Mail-Debug</h1>";

try {
    // 1. SMTP-Einstellungen laden
    echo "<h2>1. SMTP-Einstellungen:</h2>";
    $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'smtp_%'");
    $stmt->execute();
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    foreach ($settings as $key => $value) {
        if ($key === 'smtp_password') {
            echo "<p><strong>$key:</strong> " . (empty($value) ? 'LEER' : 'GESETZT (' . strlen($value) . ' Zeichen)') . "</p>";
        } else {
            echo "<p><strong>$key:</strong> " . ($value ?: 'LEER') . "</p>";
        }
    }
    
    // 2. Teste E-Mail-Versand
    echo "<h2>2. E-Mail-Test:</h2>";
    $test_email = 'dleuchtenberg89@gmail.com';
    $subject = 'Test E-Mail - ' . date('H:i:s');
    $message = 'Dies ist eine Test-E-Mail von der Feuerwehr-App.';
    
    echo "<p>Teste E-Mail-Versand an: $test_email</p>";
    
    $result = send_email($test_email, $subject, $message);
    
    if ($result) {
        echo "<p style='color: green;'>✅ E-Mail erfolgreich gesendet!</p>";
    } else {
        echo "<p style='color: red;'>❌ E-Mail-Versand fehlgeschlagen!</p>";
        
        // 3. Detaillierte Fehleranalyse
        echo "<h2>3. Fehleranalyse:</h2>";
        
        $smtp_host = $settings['smtp_host'] ?? '';
        $smtp_username = $settings['smtp_username'] ?? '';
        $smtp_password = $settings['smtp_password'] ?? '';
        
        if (empty($smtp_password)) {
            echo "<p style='color: red;'>❌ SMTP-Passwort ist leer!</p>";
            echo "<p>Gehen Sie zu: <a href='admin/settings.php'>Einstellungen</a> und setzen Sie das Gmail App-Passwort.</p>";
        }
        
        if (empty($smtp_host)) {
            echo "<p style='color: red;'>❌ SMTP-Host ist leer!</p>";
        }
        
        if (empty($smtp_username)) {
            echo "<p style='color: red;'>❌ SMTP-Benutzername ist leer!</p>";
        }
        
        // 4. PHP mail() Test
        echo "<h2>4. PHP mail() Test:</h2>";
        $headers = "From: Löschzug Amern <loeschzug.amern@gmail.com>\r\n";
        $headers .= "Reply-To: loeschzug.amern@gmail.com\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        
        $mail_result = mail($test_email, 'PHP mail() Test - ' . date('H:i:s'), 'Test von PHP mail() Funktion', $headers);
        
        if ($mail_result) {
            echo "<p style='color: green;'>✅ PHP mail() funktioniert!</p>";
        } else {
            echo "<p style='color: red;'>❌ PHP mail() funktioniert nicht!</p>";
            echo "<p>Mögliche Ursachen:</p>";
            echo "<ul>";
            echo "<li>sendmail ist nicht installiert</li>";
            echo "<li>SMTP-Server ist nicht erreichbar</li>";
            echo "<li>Firewall blockiert ausgehende E-Mails</li>";
            echo "</ul>";
        }
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<h2>5. Lösungsvorschläge:</h2>";
echo "<ol>";
echo "<li>Gehen Sie zu <a href='admin/settings.php'>Einstellungen</a></li>";
echo "<li>Setzen Sie das Gmail App-Passwort</li>";
echo "<li>Überprüfen Sie die SMTP-Einstellungen</li>";
echo "<li>Testen Sie den E-Mail-Versand erneut</li>";
echo "</ol>";

echo "<p><a href='admin/dashboard.php'>Zurück zum Dashboard</a></p>";
?>


