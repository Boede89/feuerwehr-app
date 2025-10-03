<?php
/**
 * Test-Skript für E-Mail-Zustellung
 */

require_once 'config/database.php';
require_once 'includes/functions.php';

echo "📧 E-Mail-Zustellung Test\n";
echo "========================\n\n";

try {
    // Test mit verschiedenen E-Mail-Adressen
    $test_emails = [
        'test@example.com',
        'test@gmail.com',
        'test@outlook.com'
    ];
    
    foreach ($test_emails as $email) {
        echo "1. Teste E-Mail an: $email\n";
        
        $subject = "E-Mail-Zustellung Test - " . date('H:i:s');
        $message = "
        <h2>E-Mail-Zustellung Test</h2>
        <p>Diese E-Mail wurde um " . date('d.m.Y H:i:s') . " gesendet.</p>
        <p>Falls Sie diese E-Mail erhalten haben, funktioniert die Zustellung korrekt.</p>
        <p><strong>Test-ID:</strong> " . uniqid() . "</p>
        ";
        
        $result = send_email($email, $subject, $message);
        
        echo "   Ergebnis: " . ($result ? 'ERFOLGREICH' : 'FEHLGESCHLAGEN') . "\n";
        
        if ($result) {
            echo "   ✅ E-Mail wurde an Gmail-Server gesendet\n";
            echo "   📧 Prüfen Sie Ihr E-Mail-Postfach (auch Spam-Ordner!)\n";
        } else {
            echo "   ❌ E-Mail-Versand fehlgeschlagen\n";
        }
        
        echo "\n";
        
        // Kurze Pause zwischen E-Mails
        sleep(2);
    }
    
    echo "2. Mögliche Gründe warum Sie keine E-Mail erhalten:\n";
    echo "   - E-Mail ist im Spam-Ordner gelandet\n";
    echo "   - Gmail blockiert E-Mails von unbekannten Absendern\n";
    echo "   - E-Mail-Adresse ist nicht korrekt\n";
    echo "   - Gmail hat die E-Mail als Spam markiert\n";
    echo "   - Firewall blockiert ausgehende E-Mails\n";
    
    echo "\n3. Lösungsvorschläge:\n";
    echo "   - Prüfen Sie den Spam-Ordner\n";
    echo "   - Verwenden Sie eine echte E-Mail-Adresse\n";
    echo "   - Fügen Sie loeschzug.amern@gmail.com zu Ihren Kontakten hinzu\n";
    echo "   - Prüfen Sie die Gmail-Sicherheitseinstellungen\n";
    
} catch (Exception $e) {
    echo "❌ Fehler: " . $e->getMessage() . "\n";
}

echo "\n🎯 Test abgeschlossen!\n";
?>
