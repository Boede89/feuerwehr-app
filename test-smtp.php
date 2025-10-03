<?php
/**
 * SMTP Test-Skript
 */

require_once 'config/database.php';
require_once 'includes/functions.php';

echo "📧 SMTP Test-Skript\n";
echo "==================\n\n";

try {
    // SMTP-Einstellungen aus der Datenbank laden
    $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'smtp_%'");
    $stmt->execute();
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    echo "1. SMTP-Einstellungen aus der Datenbank:\n";
    foreach ($settings as $key => $value) {
        if ($key === 'smtp_password') {
            echo "   $key: " . (empty($value) ? 'NICHT GESETZT' : '***' . substr($value, -3)) . "\n";
        } else {
            echo "   $key: " . (empty($value) ? 'NICHT GESETZT' : $value) . "\n";
        }
    }
    
    echo "\n2. PHP mail() Funktion testen:\n";
    echo "   mail() verfügbar: " . (function_exists('mail') ? 'JA' : 'NEIN') . "\n";
    echo "   sendmail_path: " . ini_get('sendmail_path') . "\n";
    echo "   SMTP: " . ini_get('SMTP') . "\n";
    echo "   smtp_port: " . ini_get('smtp_port') . "\n";
    
    echo "\n3. Test-E-Mail senden:\n";
    $test_email = 'test@example.com';
    $subject = "Test E-Mail - Feuerwehr App";
    $message = "<h2>Test E-Mail</h2><p>Diese E-Mail wurde als Test gesendet.</p>";
    
    echo "   An: $test_email\n";
    echo "   Betreff: $subject\n";
    
    $result = send_email($test_email, $subject, $message);
    echo "   Ergebnis: " . ($result ? 'ERFOLGREICH' : 'FEHLGESCHLAGEN') . "\n";
    
    if (!$result) {
        echo "\n4. Mögliche Lösungen:\n";
        echo "   - Prüfen Sie die PHP mail() Konfiguration\n";
        echo "   - Installieren Sie einen Mail-Server (Postfix, Sendmail)\n";
        echo "   - Konfigurieren Sie SMTP-Einstellungen in der Anwendung\n";
        echo "   - Prüfen Sie die Firewall-Einstellungen\n";
    }
    
    echo "\n5. Log-Dateien prüfen:\n";
    echo "   error_log: " . ini_get('error_log') . "\n";
    echo "   log_errors: " . (ini_get('log_errors') ? 'AN' : 'AUS') . "\n";
    
} catch (Exception $e) {
    echo "❌ Fehler: " . $e->getMessage() . "\n";
}

echo "\n🎯 Test abgeschlossen!\n";
?>
