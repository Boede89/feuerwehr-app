<?php
/**
 * Finaler E-Mail-Test
 */

require_once 'config/database.php';
require_once 'includes/functions.php';

echo "📧 Finaler E-Mail-Test\n";
echo "=====================\n\n";

try {
    $your_email = 'dleuchtenberg89@gmail.com';
    
    echo "1. Sende finale Test-E-Mail an: $your_email\n";
    
    $subject = "🚒 Feuerwehr App - Finaler Test " . date('H:i:s');
    $message = "
    <h2>🎉 Feuerwehr App - E-Mail funktioniert!</h2>
    <p>Hallo!</p>
    <p>Diese E-Mail wurde um <strong>" . date('d.m.Y H:i:s') . "</strong> erfolgreich gesendet.</p>
    <p>Das E-Mail-System der Feuerwehr App funktioniert jetzt korrekt!</p>
    
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
    
    <h3>📧 E-Mail-System:</h3>
    <ul>
        <li>Absender: Löschzug Amern (loeschzug.amern@gmail.com)</li>
        <li>Empfänger: $your_email</li>
        <li>Zeitstempel: " . date('d.m.Y H:i:s') . "</li>
        <li>Test-ID: " . uniqid() . "</li>
        <li>Status: FUNKTIONIERT!</li>
    </ul>
    
    <p>Mit freundlichen Grüßen,<br>
    <strong>Feuerwehr App System</strong></p>
    ";
    
    $result = send_email($your_email, $subject, $message);
    
    echo "2. E-Mail-Versand Ergebnis:\n";
    if ($result) {
        echo "   ✅ ERFOLGREICH - E-Mail wurde gesendet\n";
        echo "   📧 Die E-Mail sollte in Ihrem Gmail-Postfach ankommen\n";
        echo "   🔍 Prüfen Sie auch den Spam-Ordner!\n";
    } else {
        echo "   ❌ FEHLGESCHLAGEN - E-Mail konnte nicht gesendet werden\n";
    }
    
    echo "\n3. Teste auch die Web-Oberfläche:\n";
    echo "   - Gehen Sie zu: http://192.168.10.150/admin/settings.php\n";
    echo "   - Scrollen Sie zu 'Test E-Mail senden'\n";
    echo "   - Geben Sie Ihre E-Mail-Adresse ein\n";
    echo "   - Klicken Sie 'Test E-Mail senden'\n";
    
} catch (Exception $e) {
    echo "❌ Fehler: " . $e->getMessage() . "\n";
}

echo "\n🎯 Test abgeschlossen!\n";
echo "📧 Prüfen Sie jetzt Ihr Gmail-Postfach!\n";
?>
