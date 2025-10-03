<?php
/**
 * Gmail E-Mail-Delivery Test mit verbesserten Headers
 */

require_once 'config/database.php';
require_once 'includes/functions.php';
require_once 'includes/smtp.php';

echo "📧 Gmail E-Mail-Delivery Test\n";
echo "=============================\n\n";

try {
    // 1. SMTP-Einstellungen laden
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

    echo "1. Sende E-Mail mit verbesserten Headers:\n";
    
    $test_email = 'dleuchtenberg89@gmail.com';
    $test_subject = "🚒 Feuerwehr App - Delivery Test " . date('H:i:s');
    $test_message = "
    <html>
    <head>
        <title>Feuerwehr App - Delivery Test</title>
        <meta charset='UTF-8'>
    </head>
    <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
        <div style='max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 10px;'>
            <h2 style='color: #d32f2f; text-align: center;'>🚒 Feuerwehr App - E-Mail funktioniert!</h2>
            
            <p>Hallo!</p>
            <p>Diese E-Mail wurde um <strong>" . date('d.m.Y H:i:s') . "</strong> erfolgreich über Gmail SMTP gesendet.</p>
            
            <div style='background-color: #f5f5f5; padding: 15px; border-radius: 5px; margin: 20px 0;'>
                <h3 style='color: #1976d2; margin-top: 0;'>✅ E-Mail-System Status:</h3>
                <ul style='margin: 0;'>
                    <li><strong>SMTP-Verbindung:</strong> ✅ Erfolgreich</li>
                    <li><strong>Authentifizierung:</strong> ✅ Erfolgreich</li>
                    <li><strong>E-Mail-Versand:</strong> ✅ Erfolgreich</li>
                    <li><strong>Zeitstempel:</strong> " . date('d.m.Y H:i:s') . "</li>
                    <li><strong>Test-ID:</strong> " . uniqid() . "</li>
                </ul>
            </div>
            
            <div style='background-color: #e8f5e8; padding: 15px; border-radius: 5px; margin: 20px 0;'>
                <h3 style='color: #2e7d32; margin-top: 0;'>🎯 Feuerwehr App Funktionen:</h3>
                <ul style='margin: 0;'>
                    <li>Fahrzeug-Reservierung</li>
                    <li>Admin-Dashboard</li>
                    <li>Fahrzeug-Verwaltung</li>
                    <li>Benutzer-Verwaltung</li>
                    <li>Reservierungs-Verwaltung</li>
                    <li>E-Mail-Benachrichtigungen ✅</li>
                    <li>Google Calendar Integration</li>
                </ul>
            </div>
            
            <div style='text-align: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd;'>
                <p style='color: #666; font-size: 14px;'>
                    Mit freundlichen Grüßen,<br>
                    <strong style='color: #d32f2f;'>$smtp_from_name</strong><br>
                    <em>Feuerwehr App System</em>
                </p>
            </div>
        </div>
    </body>
    </html>
    ";

    echo "   Empfänger: $test_email\n";
    echo "   Betreff: $test_subject\n";
    echo "   Sende über Gmail SMTP...\n\n";

    // Gmail SMTP verwenden
    $smtp = new SimpleSMTP($smtp_host, $smtp_port, $smtp_username, $smtp_password, $smtp_encryption, $smtp_from_email, $smtp_from_name);
    $result = $smtp->send($test_email, $test_subject, $test_message);

    if ($result) {
        echo "2. E-Mail-Versand Ergebnis:\n";
        echo "   ✅ ERFOLGREICH - E-Mail über Gmail SMTP gesendet\n";
        echo "   📧 Die E-Mail wurde an Gmail's Server übermittelt\n";
        echo "   ⏰ Gmail-Verarbeitung kann 1-5 Minuten dauern\n\n";
        
        echo "3. Nächste Schritte:\n";
        echo "   📬 Prüfen Sie Ihr Gmail-Postfach (auch Spam-Ordner!)\n";
        echo "   ⏰ Warten Sie 1-5 Minuten auf die E-Mail\n";
        echo "   🔍 Suchen Sie nach: 'Feuerwehr App - Delivery Test'\n";
        echo "   📧 Absender: $smtp_from_name <$smtp_from_email>\n\n";
        
        echo "4. Falls E-Mail nicht ankommt:\n";
        echo "   🔍 Prüfen Sie den Spam-Ordner\n";
        echo "   ⏰ Warten Sie länger (bis zu 10 Minuten)\n";
        echo "   📧 Prüfen Sie andere E-Mail-Ordner\n";
        echo "   🔄 Testen Sie mit einer anderen E-Mail-Adresse\n\n";
        
        echo "5. Web-Oberfläche testen:\n";
        echo "   🌐 Gehen Sie zu: http://192.168.10.150/admin/settings.php\n";
        echo "   📧 Scrollen Sie zu 'Test E-Mail senden'\n";
        echo "   ✉️ Geben Sie Ihre E-Mail-Adresse ein: $test_email\n";
        echo "   🚀 Klicken Sie 'Test E-Mail senden'\n";
        echo "   ✅ Sollte jetzt funktionieren!\n";
        
    } else {
        echo "   ❌ FEHLGESCHLAGEN - Gmail SMTP konnte E-Mail nicht senden\n";
    }

} catch (Exception $e) {
    echo "❌ Fehler: " . $e->getMessage() . "\n";
}

echo "\n🎯 Gmail Delivery Test abgeschlossen!\n";
echo "📧 Prüfen Sie jetzt Ihr Gmail-Postfach!\n";
?>
