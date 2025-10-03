<?php
/**
 * Test-Skript für Ihre E-Mail-Adresse
 */

require_once 'config/database.php';
require_once 'includes/functions.php';

echo "📧 E-Mail-Test für dleuchtenberg89@gmail.com\n";
echo "=============================================\n\n";

try {
    $your_email = 'dleuchtenberg89@gmail.com';
    
    echo "1. Sende Test-E-Mail an: $your_email\n";
    
    $subject = "Feuerwehr App - E-Mail-Test " . date('H:i:s');
    $message = "
    <h2>🚒 Feuerwehr App - E-Mail-Test</h2>
    <p>Hallo!</p>
    <p>Diese E-Mail wurde um <strong>" . date('d.m.Y H:i:s') . "</strong> von der Feuerwehr App gesendet.</p>
    <p>Falls Sie diese E-Mail erhalten haben, funktioniert die E-Mail-Konfiguration korrekt!</p>
    
    <h3>📋 Test-Details:</h3>
    <ul>
        <li><strong>Absender:</strong> Löschzug Amern (loeschzug.amern@gmail.com)</li>
        <li><strong>Empfänger:</strong> $your_email</li>
        <li><strong>Zeitstempel:</strong> " . date('d.m.Y H:i:s') . "</li>
        <li><strong>Test-ID:</strong> " . uniqid() . "</li>
        <li><strong>Server:</strong> Gmail SMTP (smtp.gmail.com:587)</li>
    </ul>
    
    <h3>✅ Funktionen der Feuerwehr App:</h3>
    <ul>
        <li>Fahrzeug-Reservierung</li>
        <li>Admin-Dashboard</li>
        <li>Fahrzeug-Verwaltung</li>
        <li>Benutzer-Verwaltung</li>
        <li>Reservierungs-Verwaltung</li>
        <li>E-Mail-Benachrichtigungen</li>
        <li>Google Calendar Integration</li>
    </ul>
    
    <p>Mit freundlichen Grüßen,<br>
    <strong>Feuerwehr App System</strong></p>
    ";
    
    echo "   Betreff: $subject\n";
    echo "   Inhalt: HTML-E-Mail mit Test-Details\n";
    
    $result = send_email($your_email, $subject, $message);
    
    echo "\n2. E-Mail-Versand Ergebnis:\n";
    if ($result) {
        echo "   ✅ ERFOLGREICH - E-Mail wurde an Gmail-Server gesendet\n";
        echo "   📧 Die E-Mail sollte in Ihrem Gmail-Postfach ankommen\n";
        echo "   🔍 Prüfen Sie auch den Spam-Ordner!\n";
    } else {
        echo "   ❌ FEHLGESCHLAGEN - E-Mail konnte nicht gesendet werden\n";
        echo "   🔧 Prüfen Sie die SMTP-Konfiguration\n";
    }
    
    echo "\n3. Nächste Schritte:\n";
    echo "   - Prüfen Sie Ihr Gmail-Postfach (dleuchtenberg89@gmail.com)\n";
    echo "   - Schauen Sie auch in den Spam-Ordner\n";
    echo "   - Falls die E-Mail ankommt: E-Mail-System funktioniert perfekt!\n";
    echo "   - Falls keine E-Mail: Prüfen Sie Gmail-Sicherheitseinstellungen\n";
    
    echo "\n4. Gmail-Sicherheitseinstellungen prüfen:\n";
    echo "   - Gehen Sie zu: https://myaccount.google.com/security\n";
    echo "   - Prüfen Sie 'App-Passwörter' (falls 2FA aktiviert)\n";
    echo "   - Fügen Sie 'loeschzug.amern@gmail.com' zu Kontakten hinzu\n";
    echo "   - Prüfen Sie Spam-Filter-Einstellungen\n";
    
} catch (Exception $e) {
    echo "❌ Fehler: " . $e->getMessage() . "\n";
}

echo "\n🎯 Test abgeschlossen!\n";
echo "📧 Prüfen Sie jetzt Ihr Gmail-Postfach!\n";
?>
