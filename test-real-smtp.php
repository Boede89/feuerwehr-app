<?php
/**
 * Test-Skript für echte SMTP-Implementierung
 */

require_once 'config/database.php';
require_once 'includes/functions.php';

echo "📧 Echte SMTP Test\n";
echo "=================\n\n";

try {
    // SMTP-Einstellungen laden
    $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_encryption', 'smtp_from_email', 'smtp_from_name')");
    $stmt->execute();
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    $smtp_host = $settings['smtp_host'] ?? '';
    $smtp_port = $settings['smtp_port'] ?? '587';
    $smtp_username = $settings['smtp_username'] ?? '';
    $smtp_password = $settings['smtp_password'] ?? '';
    $smtp_encryption = $settings['smtp_encryption'] ?? 'tls';
    $smtp_from_email = $settings['smtp_from_email'] ?? '';
    $smtp_from_name = $settings['smtp_from_name'] ?? '';
    
    echo "1. SMTP-Einstellungen:\n";
    echo "   Host: $smtp_host\n";
    echo "   Port: $smtp_port\n";
    echo "   Username: $smtp_username\n";
    echo "   Password: " . (empty($smtp_password) ? 'NICHT GESETZT' : 'GESETZT (' . strlen($smtp_password) . ' Zeichen)') . "\n";
    echo "   Encryption: $smtp_encryption\n";
    echo "   From Email: $smtp_from_email\n";
    echo "   From Name: $smtp_from_name\n";
    
    if (empty($smtp_password)) {
        echo "\n❌ SMTP-Passwort ist nicht gesetzt!\n";
        echo "   Gehen Sie zu: http://192.168.10.150/admin/settings.php\n";
        echo "   Setzen Sie das Gmail App-Passwort\n";
        exit;
    }
    
    echo "\n2. Echte SMTP-Verbindung testen:\n";
    
    // Test-E-Mail senden
    $to = 'test@example.com';
    $subject = 'Echte SMTP Test E-Mail';
    $message = '<h2>Echte SMTP Test</h2><p>Diese E-Mail wurde über echte SMTP-Verbindung gesendet.</p>';
    
    echo "   Sende E-Mail an: $to\n";
    echo "   Betreff: $subject\n";
    
    $result = send_email($to, $subject, $message);
    
    echo "   Ergebnis: " . ($result ? 'ERFOLGREICH' : 'FEHLGESCHLAGEN') . "\n";
    
    if ($result) {
        echo "\n✅ E-Mail wurde erfolgreich über SMTP gesendet!\n";
        echo "   Prüfen Sie Ihr E-Mail-Postfach.\n";
    } else {
        echo "\n❌ E-Mail-Versand fehlgeschlagen.\n";
        echo "   Mögliche Ursachen:\n";
        echo "   - Gmail App-Passwort ist falsch\n";
        echo "   - 2-Faktor-Authentifizierung nicht aktiviert\n";
        echo "   - Firewall blockiert Port 587\n";
        echo "   - Gmail blockiert die Verbindung\n";
    }
    
    echo "\n3. Log-Dateien prüfen:\n";
    echo "   error_log: " . ini_get('error_log') . "\n";
    echo "   Prüfen Sie die Log-Dateien für detaillierte Fehlermeldungen.\n";
    
} catch (Exception $e) {
    echo "❌ Fehler: " . $e->getMessage() . "\n";
}

echo "\n🎯 Test abgeschlossen!\n";
?>
