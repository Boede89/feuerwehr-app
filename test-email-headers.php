<?php
/**
 * Test E-Mail-Header Formatierung
 */

require_once 'config/database.php';
require_once 'includes/functions.php';

echo "📧 E-Mail-Header Test\n";
echo "====================\n\n";

try {
    // SMTP-Einstellungen laden
    $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'smtp_%'");
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
    echo "   Password: " . (!empty($smtp_password) ? 'GESETZT' : 'LEER') . "\n";
    echo "   From Email: $smtp_from_email\n";
    echo "   From Name: $smtp_from_name\n\n";
    
    if (empty($smtp_password)) {
        echo "❌ SMTP-Passwort ist nicht gesetzt!\n";
        echo "   Führen Sie zuerst 'set-smtp-password.php' aus.\n";
        exit;
    }
    
    // Test E-Mail senden
    echo "2. Test E-Mail senden...\n";
    $test_email = "boedefeld1@freenet.de";
    $subject = "Test E-Mail - Header Fix";
    $message = "
    <h2>Test E-Mail - Header Fix</h2>
    <p>Diese E-Mail testet die korrigierten E-Mail-Header.</p>
    <p><strong>Zeitstempel:</strong> " . date('d.m.Y H:i:s') . "</p>
    <p><strong>Header-Problem behoben:</strong> ✅</p>
    <ul>
        <li>MIME-Version: 1.0 hinzugefügt</li>
        <li>Content-Transfer-Encoding: 8bit hinzugefügt</li>
        <li>X-Mailer Header hinzugefügt</li>
        <li>X-Priority Header hinzugefügt</li>
        <li>Header-Formatierung korrigiert</li>
    </ul>
    ";
    
    echo "   An: $test_email\n";
    echo "   Betreff: $subject\n";
    echo "   Sende E-Mail...\n";
    
    $result = send_email($test_email, $subject, $message);
    
    if ($result) {
        echo "   ✅ E-Mail erfolgreich gesendet!\n";
        echo "   📧 Prüfen Sie Ihr Postfach (auch Spam-Ordner)\n";
    } else {
        echo "   ❌ E-Mail konnte nicht gesendet werden\n";
    }
    
    echo "\n3. Header-Details:\n";
    echo "   From: $smtp_from_name <$smtp_from_email>\n";
    echo "   To: $test_email\n";
    echo "   Subject: $subject\n";
    echo "   MIME-Version: 1.0\n";
    echo "   Content-Type: text/html; charset=UTF-8\n";
    echo "   Content-Transfer-Encoding: 8bit\n";
    echo "   X-Mailer: PHP/" . phpversion() . "\n";
    echo "   X-Priority: 3\n";
    
} catch (Exception $e) {
    echo "❌ Fehler: " . $e->getMessage() . "\n";
}

echo "\n🎯 E-Mail-Header Test abgeschlossen!\n";
?>
