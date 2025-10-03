<?php
/**
 * Detailliertes SMTP-Debug für E-Mail-Problem
 */

require_once 'config/database.php';
require_once 'includes/functions.php';

echo "🔍 Detailliertes SMTP-Debug\n";
echo "===========================\n\n";

try {
    // 1. SMTP-Einstellungen laden
    $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'smtp_%'");
    $stmt->execute();
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    echo "1. SMTP-Einstellungen:\n";
    foreach ($settings as $key => $value) {
        if ($key === 'smtp_password') {
            echo "   $key: " . (empty($value) ? 'LEER' : 'GESETZT (' . strlen($value) . ' Zeichen)') . "\n";
        } else {
            echo "   $key: " . (empty($value) ? 'LEER' : $value) . "\n";
        }
    }
    
    // 2. Teste direkte SMTP-Verbindung
    echo "\n2. Teste direkte SMTP-Verbindung:\n";
    $smtp_host = $settings['smtp_host'] ?? '';
    $smtp_port = $settings['smtp_port'] ?? '587';
    $smtp_username = $settings['smtp_username'] ?? '';
    $smtp_password = $settings['smtp_password'] ?? '';
    
    if (empty($smtp_password)) {
        echo "   ❌ SMTP-Passwort ist leer!\n";
        echo "   Gehen Sie zu: http://192.168.10.150/admin/settings.php\n";
        echo "   Setzen Sie das Gmail App-Passwort!\n";
        exit;
    }
    
    // 3. Teste mit einfacher mail() Funktion
    echo "\n3. Teste mit mail() Funktion:\n";
    $to = 'dleuchtenberg89@gmail.com';
    $subject = 'Mail() Test - ' . date('H:i:s');
    $message = 'Einfacher Text-Test von mail() Funktion';
    $headers = "From: Löschzug Amern <loeschzug.amern@gmail.com>\r\n";
    $headers .= "Reply-To: loeschzug.amern@gmail.com\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    
    $result1 = mail($to, $subject, $message, $headers);
    echo "   mail() Ergebnis: " . ($result1 ? 'ERFOLGREICH' : 'FEHLGESCHLAGEN') . "\n";
    
    // 4. Teste mit send_email Funktion
    echo "\n4. Teste mit send_email Funktion:\n";
    $subject2 = 'Send_Email Test - ' . date('H:i:s');
    $message2 = '<h2>Send_Email Test</h2><p>HTML-Test von send_email Funktion</p>';
    
    $result2 = send_email($to, $subject2, $message2);
    echo "   send_email Ergebnis: " . ($result2 ? 'ERFOLGREICH' : 'FEHLGESCHLAGEN') . "\n";
    
    // 5. Prüfe PHP mail() Konfiguration
    echo "\n5. PHP mail() Konfiguration:\n";
    echo "   mail() verfügbar: " . (function_exists('mail') ? 'JA' : 'NEIN') . "\n";
    echo "   sendmail_path: " . ini_get('sendmail_path') . "\n";
    echo "   SMTP: " . ini_get('SMTP') . "\n";
    echo "   smtp_port: " . ini_get('smtp_port') . "\n";
    
    // 6. Teste verschiedene E-Mail-Adressen
    echo "\n6. Teste verschiedene E-Mail-Adressen:\n";
    $test_emails = [
        'dleuchtenberg89@gmail.com',
        'test@gmail.com',
        'test@outlook.com'
    ];
    
    foreach ($test_emails as $email) {
        $subject3 = 'Multi-Test - ' . date('H:i:s');
        $message3 = 'Test an ' . $email;
        
        $result3 = mail($email, $subject3, $message3, $headers);
        echo "   $email: " . ($result3 ? 'ERFOLGREICH' : 'FEHLGESCHLAGEN') . "\n";
    }
    
    // 7. Mögliche Probleme
    echo "\n7. Mögliche Probleme:\n";
    echo "   - Gmail blockiert E-Mails von Docker-Container\n";
    echo "   - Firewall blockiert ausgehende E-Mails\n";
    echo "   - Gmail App-Passwort ist falsch\n";
    echo "   - Gmail hat die IP-Adresse blockiert\n";
    echo "   - PHP mail() ist nicht korrekt konfiguriert\n";
    
    // 8. Lösungsvorschläge
    echo "\n8. Lösungsvorschläge:\n";
    echo "   - Prüfen Sie Gmail-Sicherheitseinstellungen\n";
    echo "   - Verwenden Sie eine andere E-Mail-Adresse zum Testen\n";
    echo "   - Prüfen Sie die Docker-Container-Logs\n";
    echo "   - Testen Sie mit einem anderen SMTP-Server\n";
    
} catch (Exception $e) {
    echo "❌ Fehler: " . $e->getMessage() . "\n";
}

echo "\n🎯 Debug abgeschlossen!\n";
?>
