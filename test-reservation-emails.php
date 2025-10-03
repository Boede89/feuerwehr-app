<?php
/**
 * Test E-Mail-Benachrichtigungen für Reservierungen
 */

require_once 'config/database.php';
require_once 'includes/functions.php';

echo "📧 Reservierungs-E-Mail Test\n";
echo "============================\n\n";

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
    
    $test_email = "boedefeld1@freenet.de";
    
    // Test 1: Genehmigungs-E-Mail
    echo "2. Test 1: Genehmigungs-E-Mail\n";
    $subject = "Fahrzeugreservierung genehmigt";
    $message_content = "
    <h2>Ihre Fahrzeugreservierung wurde genehmigt</h2>
    <p>Ihr Antrag für die Reservierung wurde genehmigt.</p>
    <p><strong>Details:</strong></p>
    <ul>
        <li>Fahrzeug: LF 20/1</li>
        <li>Von: 15.10.2025 14:00</li>
        <li>Bis: 15.10.2025 16:00</li>
    </ul>
    ";
    
    echo "   An: $test_email\n";
    echo "   Betreff: $subject\n";
    echo "   Sende E-Mail...\n";
    
    $result1 = send_email($test_email, $subject, $message_content);
    
    if ($result1) {
        echo "   ✅ Genehmigungs-E-Mail erfolgreich gesendet!\n";
    } else {
        echo "   ❌ Genehmigungs-E-Mail fehlgeschlagen\n";
    }
    
    // Warten 2 Sekunden
    sleep(2);
    
    // Test 2: Ablehnungs-E-Mail
    echo "\n3. Test 2: Ablehnungs-E-Mail\n";
    $subject = "Fahrzeugreservierung abgelehnt";
    $message_content = "
    <h2>Ihre Fahrzeugreservierung wurde abgelehnt</h2>
    <p>Ihr Antrag für die Reservierung wurde leider abgelehnt.</p>
    <p><strong>Grund:</strong> Fahrzeug bereits vergeben</p>
    ";
    
    echo "   An: $test_email\n";
    echo "   Betreff: $subject\n";
    echo "   Sende E-Mail...\n";
    
    $result2 = send_email($test_email, $subject, $message_content);
    
    if ($result2) {
        echo "   ✅ Ablehnungs-E-Mail erfolgreich gesendet!\n";
    } else {
        echo "   ❌ Ablehnungs-E-Mail fehlgeschlagen\n";
    }
    
    // Test 3: Header-Validierung
    echo "\n4. Header-Validierung:\n";
    echo "   From: $smtp_from_name <$smtp_from_email>\n";
    echo "   To: $test_email\n";
    echo "   Subject: Test E-Mail\n";
    echo "   MIME-Version: 1.0\n";
    echo "   Content-Type: text/html; charset=UTF-8\n";
    echo "   Content-Transfer-Encoding: 8bit\n";
    echo "   X-Mailer: PHP/" . phpversion() . "\n";
    echo "   X-Priority: 3\n";
    
    // Test 4: Zeichen-Validierung
    echo "\n5. Zeichen-Validierung:\n";
    $problematic_chars = ['ä', 'ö', 'ü', 'ß', '€', '°', '§'];
    $has_problematic = false;
    
    foreach ($problematic_chars as $char) {
        if (strpos($smtp_from_name, $char) !== false) {
            echo "   ⚠️ Problematisches Zeichen '$char' in From Name gefunden\n";
            $has_problematic = true;
        }
    }
    
    if (!$has_problematic) {
        echo "   ✅ Keine problematischen Zeichen gefunden\n";
    }
    
    echo "\n6. Zusammenfassung:\n";
    echo "   Genehmigungs-E-Mail: " . ($result1 ? "✅ Erfolgreich" : "❌ Fehlgeschlagen") . "\n";
    echo "   Ablehnungs-E-Mail: " . ($result2 ? "✅ Erfolgreich" : "❌ Fehlgeschlagen") . "\n";
    
    if ($result1 && $result2) {
        echo "\n🎉 Alle E-Mail-Tests erfolgreich!\n";
        echo "📧 Prüfen Sie Ihr Postfach (auch Spam-Ordner)\n";
    } else {
        echo "\n❌ Einige E-Mail-Tests fehlgeschlagen!\n";
        echo "🔍 Prüfen Sie die Log-Dateien für weitere Details\n";
    }
    
} catch (Exception $e) {
    echo "❌ Fehler: " . $e->getMessage() . "\n";
}

echo "\n🎯 Reservierungs-E-Mail Test abgeschlossen!\n";
?>
