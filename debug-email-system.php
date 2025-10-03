<?php
/**
 * Umfassende E-Mail-System-Diagnose
 */

session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';
require_once 'includes/smtp.php';

echo "📧 E-Mail-System Diagnose\n";
echo "========================\n\n";

$test_email = 'dleuchtenberg89@gmail.com'; // Ihre E-Mail-Adresse

try {
    // 1. SMTP-Einstellungen aus der Datenbank laden
    echo "1. SMTP-Einstellungen aus der Datenbank:\n";
    $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_encryption', 'smtp_from_email', 'smtp_from_name')");
    $stmt->execute();
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $smtp_host = $settings['smtp_host'] ?? '';
    $smtp_port = $settings['smtp_port'] ?? '587';
    $smtp_username = $settings['smtp_username'] ?? '';
    $smtp_password = $settings['smtp_password'] ?? '';
    $smtp_encryption = $settings['smtp_encryption'] ?? 'tls';
    $smtp_from_email = $settings['smtp_from_email'] ?? 'noreply@feuerwehr-app.local';
    $smtp_from_name = $settings['smtp_from_name'] ?? 'Feuerwehr App';

    echo "   Host: " . ($smtp_host ?: 'NICHT GESETZT') . "\n";
    echo "   Port: " . ($smtp_port ?: 'NICHT GESETZT') . "\n";
    echo "   Username: " . ($smtp_username ?: 'NICHT GESETZT') . "\n";
    echo "   Password: " . (!empty($smtp_password) ? 'GESETZT (' . strlen($smtp_password) . ' Zeichen)' : 'LEER') . "\n";
    echo "   Encryption: " . ($smtp_encryption ?: 'NICHT GESETZT') . "\n";
    echo "   From Email: " . ($smtp_from_email ?: 'NICHT GESETZT') . "\n";
    echo "   From Name: " . ($smtp_from_name ?: 'NICHT GESETZT') . "\n";
    echo "   ✅ Einstellungen geladen\n\n";

    // 2. PHP mail() Konfiguration prüfen
    echo "2. PHP mail() Konfiguration:\n";
    echo "   mail() verfügbar: " . (function_exists('mail') ? 'JA' : 'NEIN') . "\n";
    echo "   sendmail_path: " . ini_get('sendmail_path') . "\n";
    echo "   SMTP: " . ini_get('SMTP') . "\n";
    echo "   smtp_port: " . ini_get('smtp_port') . "\n";
    echo "   error_log: " . ini_get('error_log') . "\n";
    echo "   log_errors: " . (ini_get('log_errors') ? 'AN' : 'AUS') . "\n";
    echo "   ✅ PHP mail() Konfiguration geprüft\n\n";

    // 3. Sendmail-Status prüfen
    echo "3. Sendmail-Status:\n";
    $sendmail_status = shell_exec('service sendmail status 2>&1');
    echo "   Status: " . (strpos($sendmail_status, 'active (running)') !== false ? 'Aktiv' : 'Inaktiv') . "\n";
    
    $sendmail_path = shell_exec('which sendmail');
    echo "   Pfad: " . (empty($sendmail_path) ? 'NICHT GEFUNDEN' : trim($sendmail_path)) . "\n";
    
    $mail_queue = shell_exec('mailq 2>&1');
    echo "   Mail-Queue: " . (strpos($mail_queue, 'Mail queue is empty') !== false ? 'Leer' : 'Hat Einträge') . "\n";
    echo "   ✅ Sendmail-Status geprüft\n\n";

    // 4. Netzwerk-Verbindung testen
    echo "4. Netzwerk-Verbindung zu Gmail:\n";
    $connection = @fsockopen('smtp.gmail.com', 587, $errno, $errstr, 10);
    if ($connection) {
        echo "   ✅ Verbindung zu smtp.gmail.com:587 erfolgreich\n";
        fclose($connection);
    } else {
        echo "   ❌ Verbindung zu smtp.gmail.com:587 fehlgeschlagen: $errstr ($errno)\n";
    }
    echo "   ✅ Netzwerk-Test abgeschlossen\n\n";

    // 5. SimpleSMTP Klasse testen
    echo "5. SimpleSMTP Klasse testen:\n";
    if (!empty($smtp_host) && !empty($smtp_username) && !empty($smtp_password)) {
        try {
            $smtp = new SimpleSMTP($smtp_host, $smtp_port, $smtp_username, $smtp_password, $smtp_encryption, $smtp_from_email, $smtp_from_name);
            
            $test_subject = "🔧 E-Mail-System Test - " . date('H:i:s');
            $test_message = "
            <h2>E-Mail-System Test</h2>
            <p>Diese E-Mail wurde über die SimpleSMTP-Klasse gesendet.</p>
            <p><strong>Zeitstempel:</strong> " . date('d.m.Y H:i:s') . "</p>
            <p><strong>Absender:</strong> $smtp_from_name &lt;$smtp_from_email&gt;</p>
            <p><strong>Empfänger:</strong> $test_email</p>
            <p>Wenn Sie diese E-Mail erhalten, funktioniert das E-Mail-System korrekt.</p>
            ";
            
            $result = $smtp->send($test_email, $test_subject, $test_message);
            
            if ($result) {
                echo "   ✅ SimpleSMTP-E-Mail erfolgreich gesendet an: $test_email\n";
            } else {
                echo "   ❌ SimpleSMTP-E-Mail fehlgeschlagen an: $test_email\n";
            }
        } catch (Exception $e) {
            echo "   ❌ Fehler bei SimpleSMTP: " . $e->getMessage() . "\n";
        }
    } else {
        echo "   ⚠️ SMTP-Einstellungen unvollständig für SimpleSMTP-Test\n";
    }
    echo "   ✅ SimpleSMTP-Test abgeschlossen\n\n";

    // 6. send_email() Funktion testen
    echo "6. send_email() Funktion testen:\n";
    $test_subject_func = "🔧 send_email() Test - " . date('H:i:s');
    $test_message_func = "
    <h2>send_email() Funktion Test</h2>
    <p>Diese E-Mail wurde über die send_email() Funktion gesendet.</p>
    <p><strong>Zeitstempel:</strong> " . date('d.m.Y H:i:s') . "</p>
    <p><strong>Absender:</strong> $smtp_from_name &lt;$smtp_from_email&gt;</p>
    <p><strong>Empfänger:</strong> $test_email</p>
    <p>Wenn Sie diese E-Mail erhalten, funktioniert die send_email() Funktion korrekt.</p>
    ";

    if (send_email($test_email, $test_subject_func, $test_message_func)) {
        echo "   ✅ send_email() Funktion erfolgreich gesendet an: $test_email\n";
    } else {
        echo "   ❌ send_email() Funktion fehlgeschlagen an: $test_email\n";
    }
    echo "   ✅ send_email() Test abgeschlossen\n\n";

    // 7. PHP mail() direkt testen
    echo "7. PHP mail() direkt testen:\n";
    $test_subject_mail = "🔧 PHP mail() Test - " . date('H:i:s');
    $test_message_mail = "Diese E-Mail wurde direkt über PHP mail() gesendet.\n\nZeitstempel: " . date('d.m.Y H:i:s') . "\nEmpfänger: $test_email";
    $headers_mail = "From: $smtp_from_name <$smtp_from_email>\r\n";
    $headers_mail .= "Reply-To: $smtp_from_email\r\n";
    $headers_mail .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $headers_mail .= "X-Mailer: PHP/" . phpversion() . "\r\n";

    $mail_result = mail($test_email, $test_subject_mail, $test_message_mail, $headers_mail);
    
    if ($mail_result) {
        echo "   ✅ PHP mail() erfolgreich gesendet an: $test_email\n";
    } else {
        echo "   ❌ PHP mail() fehlgeschlagen an: $test_email\n";
    }
    echo "   ✅ PHP mail() Test abgeschlossen\n\n";

    // 8. Log-Dateien prüfen
    echo "8. Log-Dateien prüfen:\n";
    $error_log = ini_get('error_log');
    if ($error_log && file_exists($error_log)) {
        $log_content = file_get_contents($error_log);
        $recent_errors = array_slice(explode("\n", $log_content), -10);
        echo "   Letzte 10 Log-Einträge:\n";
        foreach ($recent_errors as $log_entry) {
            if (!empty(trim($log_entry))) {
                echo "     " . trim($log_entry) . "\n";
            }
        }
    } else {
        echo "   ⚠️ Keine Log-Datei gefunden oder nicht konfiguriert\n";
    }
    echo "   ✅ Log-Prüfung abgeschlossen\n\n";

    // 9. Zusammenfassung und Empfehlungen
    echo "9. Zusammenfassung und Empfehlungen:\n";
    echo "=====================================\n";
    
    if (empty($smtp_host) || empty($smtp_username) || empty($smtp_password)) {
        echo "❌ SMTP-Einstellungen unvollständig!\n";
        echo "   → Gehen Sie zu: http://192.168.10.150/admin/settings.php\n";
        echo "   → Füllen Sie alle SMTP-Felder aus\n";
        echo "   → Verwenden Sie ein Gmail App-Passwort\n";
    } else {
        echo "✅ SMTP-Einstellungen sind konfiguriert\n";
    }
    
    if (!function_exists('mail')) {
        echo "❌ PHP mail() Funktion nicht verfügbar\n";
        echo "   → Installieren Sie sendmail oder postfix\n";
    } else {
        echo "✅ PHP mail() Funktion verfügbar\n";
    }
    
    echo "\n📧 Nächste Schritte:\n";
    echo "1. Prüfen Sie Ihr Gmail-Postfach (auch Spam-Ordner!)\n";
    echo "2. Warten Sie 1-5 Minuten auf die E-Mails\n";
    echo "3. Falls keine E-Mails ankommen, prüfen Sie:\n";
    echo "   - Gmail App-Passwort korrekt?\n";
    echo "   - 2-Faktor-Authentifizierung aktiviert?\n";
    echo "   - Firewall blockiert SMTP-Verbindungen?\n";
    echo "   - Gmail-Konto gesperrt?\n";

} catch (Exception $e) {
    echo "❌ Kritischer Fehler: " . $e->getMessage() . "\n";
}

echo "\n🎯 E-Mail-System-Diagnose abgeschlossen!\n";
?>
