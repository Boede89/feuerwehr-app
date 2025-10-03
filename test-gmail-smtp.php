<?php
/**
 * Gmail SMTP direkter Test
 */

require_once 'config/database.php';
require_once 'includes/functions.php';
require_once 'includes/smtp.php';

echo "📧 Gmail SMTP direkter Test\n";
echo "==========================\n\n";

try {
    // 1. SMTP-Einstellungen aus der Datenbank laden
    echo "1. SMTP-Einstellungen laden:\n";
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
    echo "   From Name: " . ($smtp_from_name ?: 'NICHT GESETZT') . "\n\n";

    // 2. Prüfen ob alle Einstellungen gesetzt sind
    if (empty($smtp_host) || empty($smtp_username) || empty($smtp_password)) {
        echo "❌ SMTP-Einstellungen unvollständig!\n";
        echo "   Gehen Sie zu: http://192.168.10.150/admin/settings.php\n";
        echo "   Setzen Sie alle SMTP-Einstellungen (besonders das Gmail App-Passwort)\n";
        exit;
    }

    // 3. Gmail SMTP direkt testen
    echo "2. Gmail SMTP direkt testen:\n";
    $test_email = 'dleuchtenberg89@gmail.com';
    $test_subject = "🚒 Gmail SMTP Test - " . date('H:i:s');
    $test_message = "
    <h2>🎉 Gmail SMTP funktioniert!</h2>
    <p>Hallo!</p>
    <p>Diese E-Mail wurde um <strong>" . date('d.m.Y H:i:s') . "</strong> über Gmail SMTP gesendet.</p>
    <p>Das E-Mail-System der Feuerwehr App funktioniert jetzt korrekt!</p>
    
    <h3>✅ Gmail SMTP-Konfiguration:</h3>
    <ul>
        <li><strong>Host:</strong> $smtp_host</li>
        <li><strong>Port:</strong> $smtp_port</li>
        <li><strong>Username:</strong> $smtp_username</li>
        <li><strong>Encryption:</strong> $smtp_encryption</li>
        <li><strong>From Name:</strong> $smtp_from_name</li>
    </ul>
    
    <h3>📧 E-Mail-Details:</h3>
    <ul>
        <li><strong>Empfänger:</strong> $test_email</li>
        <li><strong>Zeitstempel:</strong> " . date('d.m.Y H:i:s') . "</li>
        <li><strong>Test-ID:</strong> " . uniqid() . "</li>
        <li><strong>Status:</strong> GESENDET ÜBER GMAIL SMTP</li>
    </ul>
    
    <p>Mit freundlichen Grüßen,<br>
    <strong>$smtp_from_name</strong></p>
    ";

    echo "   Empfänger: $test_email\n";
    echo "   Betreff: $test_subject\n";
    echo "   Sende über Gmail SMTP...\n";

    // Gmail SMTP verwenden
    $smtp = new SimpleSMTP($smtp_host, $smtp_port, $smtp_username, $smtp_password, $smtp_encryption, $smtp_from_email, $smtp_from_name);
    $result = $smtp->send($test_email, $test_subject, $test_message);

    if ($result) {
        echo "   ✅ ERFOLGREICH - E-Mail über Gmail SMTP gesendet\n";
        echo "   📧 Die E-Mail sollte in Ihrem Gmail-Postfach ankommen\n";
        echo "   🔍 Prüfen Sie auch den Spam-Ordner!\n";
    } else {
        echo "   ❌ FEHLGESCHLAGEN - Gmail SMTP konnte E-Mail nicht senden\n";
    }

    echo "\n3. Teste auch die Web-Oberfläche:\n";
    echo "   - Gehen Sie zu: http://192.168.10.150/admin/settings.php\n";
    echo "   - Scrollen Sie zu 'Test E-Mail senden'\n";
    echo "   - Geben Sie Ihre E-Mail-Adresse ein: $test_email\n";
    echo "   - Klicken Sie 'Test E-Mail senden'\n";
    echo "   - Sollte jetzt über Gmail SMTP funktionieren!\n";

} catch (Exception $e) {
    echo "❌ Fehler: " . $e->getMessage() . "\n";
    echo "   Mögliche Ursachen:\n";
    echo "   - Gmail App-Passwort ist falsch\n";
    echo "   - 2-Faktor-Authentifizierung nicht aktiviert\n";
    echo "   - Firewall blockiert Port 587\n";
    echo "   - Gmail blockiert die Verbindung\n";
}

echo "\n🎯 Gmail SMTP Test abgeschlossen!\n";
echo "📧 Prüfen Sie jetzt Ihr Gmail-Postfach!\n";
?>
