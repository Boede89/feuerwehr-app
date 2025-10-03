<?php
/**
 * Debug der Admin-Settings-Seite
 */

session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';

echo "🔍 Admin-Settings-Seite Debug\n";
echo "=============================\n\n";

// Simuliere die Admin-Settings-Seite genau
try {
    // 1. Einstellungen laden (wie in settings.php)
    echo "1. Einstellungen laden (wie in settings.php):\n";
    $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings");
    $stmt->execute();
    $settings_data = $stmt->fetchAll();
    
    $settings = [];
    foreach ($settings_data as $setting) {
        $settings[$setting['setting_key']] = $setting['setting_value'];
    }
    
    echo "   Anzahl Einstellungen: " . count($settings) . "\n";
    echo "   SMTP-Einstellungen:\n";
    foreach ($settings as $key => $value) {
        if (strpos($key, 'smtp_') === 0) {
            if ($key === 'smtp_password') {
                echo "     $key: " . (!empty($value) ? 'GESETZT (' . strlen($value) . ' Zeichen)' : 'LEER') . "\n";
            } else {
                echo "     $key: " . ($value ?: 'LEER') . "\n";
            }
        }
    }
    echo "\n";

    // 2. Test E-Mail senden (exakt wie in settings.php)
    echo "2. Test E-Mail senden (exakt wie in settings.php):\n";
    $test_email = 'dleuchtenberg89@gmail.com';
    
    if (empty($test_email) || !validate_email($test_email)) {
        echo "   ❌ E-Mail-Validierung fehlgeschlagen\n";
    } else {
        echo "   ✅ E-Mail-Validierung erfolgreich\n";
        
        $subject = "Test E-Mail - Feuerwehr App";
        $message_content = "
        <h2>Test E-Mail</h2>
        <p>Diese E-Mail wurde als Test von der Feuerwehr App gesendet.</p>
        <p>Falls Sie diese E-Mail erhalten haben, funktioniert die E-Mail-Konfiguration korrekt.</p>
        <p><strong>Zeitstempel:</strong> " . date('d.m.Y H:i:s') . "</p>
        ";
        
        echo "   Betreff: $subject\n";
        echo "   Empfänger: $test_email\n";
        echo "   Sende E-Mail...\n";
        
        // Teste send_email() mit detailliertem Debug
        $result = send_email($test_email, $subject, $message_content);
        
        if ($result) {
            echo "   ✅ Test E-Mail erfolgreich gesendet!\n";
        } else {
            echo "   ❌ Test E-Mail fehlgeschlagen!\n";
        }
    }
    echo "\n";

    // 3. send_email() Funktion detailliert debuggen
    echo "3. send_email() Funktion detailliert debuggen:\n";
    
    // Simuliere die send_email() Funktion aus includes/functions.php
    $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_encryption', 'smtp_from_email', 'smtp_from_name')");
    $stmt->execute();
    $smtp_settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    $smtp_host = $smtp_settings['smtp_host'] ?? '';
    $smtp_port = $smtp_settings['smtp_port'] ?? '587';
    $smtp_username = $smtp_settings['smtp_username'] ?? '';
    $smtp_password = $smtp_settings['smtp_password'] ?? '';
    $smtp_encryption = $smtp_settings['smtp_encryption'] ?? 'tls';
    $smtp_from_email = $smtp_settings['smtp_from_email'] ?? 'noreply@feuerwehr-app.local';
    $smtp_from_name = $smtp_settings['smtp_from_name'] ?? 'Feuerwehr App';
    
    echo "   SMTP-Host: $smtp_host\n";
    echo "   SMTP-Port: $smtp_port\n";
    echo "   SMTP-Username: $smtp_username\n";
    echo "   SMTP-Password: " . (!empty($smtp_password) ? 'GESETZT (' . strlen($smtp_password) . ' Zeichen)' : 'LEER') . "\n";
    echo "   SMTP-Encryption: $smtp_encryption\n";
    echo "   From Email: $smtp_from_email\n";
    echo "   From Name: $smtp_from_name\n";
    
    if (!empty($smtp_host) && !empty($smtp_username) && !empty($smtp_password)) {
        echo "   ✅ SMTP-Einstellungen vollständig - verwende SimpleSMTP\n";
        
        // Teste SimpleSMTP direkt
        require_once 'includes/smtp.php';
        $smtp = new SimpleSMTP($smtp_host, $smtp_port, $smtp_username, $smtp_password, $smtp_encryption, $smtp_from_email, $smtp_from_name);
        $result = $smtp->send($test_email, "Debug Settings Test - " . date('H:i:s'), "Debug Test E-Mail von Settings-Seite");
        
        if ($result) {
            echo "   ✅ SimpleSMTP direkt erfolgreich\n";
        } else {
            echo "   ❌ SimpleSMTP direkt fehlgeschlagen\n";
        }
    } else {
        echo "   ⚠️ SMTP-Einstellungen unvollständig - verwende mail()\n";
    }
    echo "\n";

    // 4. Fehlerbehandlung und Logs
    echo "4. Fehlerbehandlung und Logs:\n";
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    
    // Teste mit try-catch
    try {
        $result = send_email($test_email, "Try-Catch Test - " . date('H:i:s'), "Try-Catch Test E-Mail");
        echo "   send_email() mit try-catch: " . ($result ? 'ERFOLG' : 'FEHLER') . "\n";
    } catch (Exception $e) {
        echo "   send_email() Exception: " . $e->getMessage() . "\n";
    }
    
    // Prüfe Log-Dateien
    $error_log = ini_get('error_log');
    if ($error_log && file_exists($error_log)) {
        $log_content = file_get_contents($error_log);
        $recent_errors = array_slice(explode("\n", $log_content), -5);
        echo "   Letzte 5 Log-Einträge:\n";
        foreach ($recent_errors as $log_entry) {
            if (!empty(trim($log_entry))) {
                echo "     " . trim($log_entry) . "\n";
            }
        }
    }
    echo "\n";

    // 5. Mögliche Ursachen
    echo "5. Mögliche Ursachen für das Problem:\n";
    echo "=====================================\n";
    echo "   - Session-Problem in settings.php\n";
    echo "   - CSRF-Token-Problem\n";
    echo "   - Formular-Validierung blockiert\n";
    echo "   - PHP-Fehler in settings.php\n";
    echo "   - Einstellungen werden nicht korrekt geladen\n";
    echo "   - send_email() Funktion hat einen Bug\n";
    echo "\n";

} catch (Exception $e) {
    echo "❌ Kritischer Fehler: " . $e->getMessage() . "\n";
}

echo "🎯 Admin-Settings-Seite Debug abgeschlossen!\n";
?>
