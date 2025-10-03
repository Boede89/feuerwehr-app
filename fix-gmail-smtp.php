<?php
/**
 * Gmail SMTP Fix-Skript
 */

require_once 'config/database.php';

echo "🔧 Gmail SMTP Fix\n";
echo "================\n\n";

try {
    // 1. Prüfe ob SMTP-Passwort gesetzt ist
    $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'smtp_password'");
    $stmt->execute();
    $password = $stmt->fetchColumn();
    
    if (empty($password)) {
        echo "❌ SMTP-Passwort ist nicht gesetzt!\n";
        echo "   Gehen Sie zu: http://192.168.10.150/admin/settings.php\n";
        echo "   Füllen Sie das SMTP-Passwort aus (Gmail App-Passwort)\n";
        echo "   Klicken Sie 'Einstellungen speichern'\n\n";
    } else {
        echo "✅ SMTP-Passwort ist gesetzt\n";
    }
    
    // 2. Teste PHP mail() Funktion
    echo "\n2. PHP mail() Funktion testen:\n";
    $test_result = mail('test@example.com', 'Test', 'Test E-Mail', 'From: test@feuerwehr-app.local');
    echo "   mail() Ergebnis: " . ($test_result ? 'ERFOLGREICH' : 'FEHLGESCHLAGEN') . "\n";
    
    // 3. Prüfe sendmail Konfiguration
    echo "\n3. Sendmail Konfiguration:\n";
    echo "   sendmail_path: " . ini_get('sendmail_path') . "\n";
    echo "   sendmail verfügbar: " . (file_exists(ini_get('sendmail_path')) ? 'JA' : 'NEIN') . "\n";
    
    // 4. Installiere sendmail falls nötig
    if (!file_exists(ini_get('sendmail_path'))) {
        echo "\n4. Sendmail wird installiert...\n";
        exec('apt-get update && apt-get install -y sendmail', $output, $return_code);
        if ($return_code === 0) {
            echo "   ✅ Sendmail installiert\n";
        } else {
            echo "   ❌ Sendmail Installation fehlgeschlagen\n";
        }
    }
    
    // 5. Teste E-Mail-Versand
    echo "\n5. E-Mail-Versand testen:\n";
    $to = 'test@example.com';
    $subject = 'Test E-Mail - Feuerwehr App';
    $message = '<h2>Test E-Mail</h2><p>Diese E-Mail wurde als Test gesendet.</p>';
    $headers = "From: Löschzug Amern <loeschzug.amern@gmail.com>\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    
    $result = mail($to, $subject, $message, $headers);
    echo "   E-Mail-Versand: " . ($result ? 'ERFOLGREICH' : 'FEHLGESCHLAGEN') . "\n";
    
    if (!$result) {
        echo "\n6. Mögliche Lösungen:\n";
        echo "   - Gmail App-Passwort in den Einstellungen setzen\n";
        echo "   - 2-Faktor-Authentifizierung in Gmail aktivieren\n";
        echo "   - App-Passwort für 'Mail' erstellen\n";
        echo "   - Firewall-Einstellungen prüfen (Port 587)\n";
    }
    
} catch (Exception $e) {
    echo "❌ Fehler: " . $e->getMessage() . "\n";
}

echo "\n🎯 Fix abgeschlossen!\n";
?>
