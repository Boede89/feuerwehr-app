<?php
/**
 * Test der Admin-E-Mail-Funktionalität
 */

session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';

echo "📧 Admin E-Mail-Test\n";
echo "===================\n\n";

$test_email = 'dleuchtenberg89@gmail.com';

try {
    // 1. Aktuelle SMTP-Einstellungen laden (wie in settings.php)
    echo "1. SMTP-Einstellungen aus der Datenbank laden:\n";
    $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'smtp_%'");
    $stmt->execute();
    $settings_data = $stmt->fetchAll();
    
    $settings = [];
    foreach ($settings_data as $setting) {
        $settings[$setting['setting_key']] = $setting['setting_value'];
    }
    
    echo "   Host: " . ($settings['smtp_host'] ?? 'NICHT GESETZT') . "\n";
    echo "   Port: " . ($settings['smtp_port'] ?? 'NICHT GESETZT') . "\n";
    echo "   Username: " . ($settings['smtp_username'] ?? 'NICHT GESETZT') . "\n";
    echo "   Password: " . (!empty($settings['smtp_password']) ? 'GESETZT (' . strlen($settings['smtp_password']) . ' Zeichen)' : 'LEER') . "\n";
    echo "   Encryption: " . ($settings['smtp_encryption'] ?? 'NICHT GESETZT') . "\n";
    echo "   From Email: " . ($settings['smtp_from_email'] ?? 'NICHT GESETZT') . "\n";
    echo "   From Name: " . ($settings['smtp_from_name'] ?? 'NICHT GESETZT') . "\n";
    echo "   ✅ Einstellungen geladen\n\n";

    // 2. Test-E-Mail wie in settings.php
    echo "2. Test-E-Mail senden (wie in Admin-Einstellungen):\n";
    $subject = "Test E-Mail - Feuerwehr App";
    $message_content = "
    <h2>Test E-Mail</h2>
    <p>Diese E-Mail wurde als Test von der Feuerwehr App gesendet.</p>
    <p>Falls Sie diese E-Mail erhalten haben, funktioniert die E-Mail-Konfiguration korrekt.</p>
    <p><strong>Zeitstempel:</strong> " . date('d.m.Y H:i:s') . "</p>
    ";
    
    echo "   Empfänger: $test_email\n";
    echo "   Betreff: $subject\n";
    echo "   Sende E-Mail...\n";
    
    if (send_email($test_email, $subject, $message_content)) {
        echo "   ✅ Test-E-Mail erfolgreich gesendet!\n";
    } else {
        echo "   ❌ Test-E-Mail fehlgeschlagen!\n";
    }
    echo "\n";

    // 3. send_email() Funktion debuggen
    echo "3. send_email() Funktion debuggen:\n";
    
    // Simuliere die send_email() Funktion
    $smtp_host = $settings['smtp_host'] ?? '';
    $smtp_port = $settings['smtp_port'] ?? '587';
    $smtp_username = $settings['smtp_username'] ?? '';
    $smtp_password = $settings['smtp_password'] ?? '';
    $smtp_encryption = $settings['smtp_encryption'] ?? 'tls';
    $smtp_from_email = $settings['smtp_from_email'] ?? 'noreply@feuerwehr-app.local';
    $smtp_from_name = $settings['smtp_from_name'] ?? 'Feuerwehr App';
    
    echo "   SMTP-Host: $smtp_host\n";
    echo "   SMTP-Port: $smtp_port\n";
    echo "   SMTP-Username: $smtp_username\n";
    echo "   SMTP-Password: " . (!empty($smtp_password) ? 'GESETZT' : 'LEER') . "\n";
    echo "   SMTP-Encryption: $smtp_encryption\n";
    echo "   From Email: $smtp_from_email\n";
    echo "   From Name: $smtp_from_name\n";
    
    if (!empty($smtp_host) && !empty($smtp_username) && !empty($smtp_password)) {
        echo "   ✅ SMTP-Einstellungen vollständig - verwende SimpleSMTP\n";
        
        // Teste SimpleSMTP direkt
        require_once 'includes/smtp.php';
        $smtp = new SimpleSMTP($smtp_host, $smtp_port, $smtp_username, $smtp_password, $smtp_encryption, $smtp_from_email, $smtp_from_name);
        $result = $smtp->send($test_email, "Debug Test - " . date('H:i:s'), "Debug Test E-Mail");
        
        if ($result) {
            echo "   ✅ SimpleSMTP direkt erfolgreich\n";
        } else {
            echo "   ❌ SimpleSMTP direkt fehlgeschlagen\n";
        }
    } else {
        echo "   ⚠️ SMTP-Einstellungen unvollständig - verwende mail()\n";
    }
    echo "\n";

    // 4. Fehlerbehandlung prüfen
    echo "4. Fehlerbehandlung prüfen:\n";
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    
    // Teste send_email() mit Fehlerbehandlung
    try {
        $result = send_email($test_email, "Error Test - " . date('H:i:s'), "Error Test E-Mail");
        echo "   send_email() Ergebnis: " . ($result ? 'ERFOLG' : 'FEHLER') . "\n";
    } catch (Exception $e) {
        echo "   send_email() Exception: " . $e->getMessage() . "\n";
    }
    echo "\n";

} catch (Exception $e) {
    echo "❌ Kritischer Fehler: " . $e->getMessage() . "\n";
}

echo "🎯 Admin E-Mail-Test abgeschlossen!\n";
?>
