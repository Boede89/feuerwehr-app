<?php
/**
 * Detailliertes SMTP-Debug-Skript
 */

require_once 'config/database.php';

echo "🔍 Detailliertes SMTP-Debug\n";
echo "===========================\n\n";

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
    
    echo "1. SMTP-Verbindung testen:\n";
    echo "   Host: $smtp_host\n";
    echo "   Port: $smtp_port\n";
    
    // Teste Socket-Verbindung
    $connection = @fsockopen($smtp_host, $smtp_port, $errno, $errstr, 10);
    
    if (!$connection) {
        echo "   ❌ Verbindung fehlgeschlagen: $errstr ($errno)\n";
        echo "   Mögliche Ursachen:\n";
        echo "   - Firewall blockiert Port $smtp_port\n";
        echo "   - Host $smtp_host ist nicht erreichbar\n";
        echo "   - Netzwerk-Problem\n";
        exit;
    } else {
        echo "   ✅ Socket-Verbindung erfolgreich\n";
    }
    
    echo "\n2. SMTP-Handshake testen:\n";
    
    // EHLO senden
    fwrite($connection, "EHLO localhost\r\n");
    $response = fgets($connection, 512);
    echo "   EHLO: " . trim($response) . "\n";
    
    // Gmail sendet 220 als erste Antwort, dann 250 für EHLO
    if (strpos($response, '220') !== false) {
        // Warte auf die 250-Antwort
        $response = fgets($connection, 512);
        echo "   EHLO (2): " . trim($response) . "\n";
    }
    
    if (strpos($response, '250') === false) {
        echo "   ❌ EHLO fehlgeschlagen\n";
        fclose($connection);
        exit;
    }
    
    // STARTTLS senden
    fwrite($connection, "STARTTLS\r\n");
    $response = fgets($connection, 512);
    echo "   STARTTLS: " . trim($response) . "\n";
    
    if (strpos($response, '220') === false) {
        echo "   ❌ STARTTLS fehlgeschlagen\n";
        fclose($connection);
        exit;
    }
    
    // TLS-Verbindung herstellen
    if (!stream_socket_enable_crypto($connection, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
        echo "   ❌ TLS-Verschlüsselung fehlgeschlagen\n";
        fclose($connection);
        exit;
    }
    echo "   ✅ TLS-Verschlüsselung aktiviert\n";
    
    // EHLO nach TLS
    fwrite($connection, "EHLO localhost\r\n");
    $response = fgets($connection, 512);
    echo "   EHLO (TLS): " . trim($response) . "\n";
    
    // AUTH LOGIN
    fwrite($connection, "AUTH LOGIN\r\n");
    $response = fgets($connection, 512);
    echo "   AUTH LOGIN: " . trim($response) . "\n";
    
    if (strpos($response, '334') === false) {
        echo "   ❌ AUTH LOGIN fehlgeschlagen\n";
        fclose($connection);
        exit;
    }
    
    // Username senden
    fwrite($connection, base64_encode($smtp_username) . "\r\n");
    $response = fgets($connection, 512);
    echo "   Username: " . trim($response) . "\n";
    
    if (strpos($response, '334') === false) {
        echo "   ❌ Username-Authentifizierung fehlgeschlagen\n";
        fclose($connection);
        exit;
    }
    
    // Password senden
    fwrite($connection, base64_encode($smtp_password) . "\r\n");
    $response = fgets($connection, 512);
    echo "   Password: " . trim($response) . "\n";
    
    if (strpos($response, '235') === false) {
        echo "   ❌ Password-Authentifizierung fehlgeschlagen\n";
        echo "   Mögliche Ursachen:\n";
        echo "   - Gmail App-Passwort ist falsch\n";
        echo "   - 2-Faktor-Authentifizierung nicht aktiviert\n";
        echo "   - Gmail blockiert die Verbindung\n";
        fclose($connection);
        exit;
    }
    
    echo "   ✅ Authentifizierung erfolgreich\n";
    
    // MAIL FROM
    fwrite($connection, "MAIL FROM: <$smtp_from_email>\r\n");
    $response = fgets($connection, 512);
    echo "   MAIL FROM: " . trim($response) . "\n";
    
    if (strpos($response, '250') === false) {
        echo "   ❌ MAIL FROM fehlgeschlagen\n";
        fclose($connection);
        exit;
    }
    
    // RCPT TO
    fwrite($connection, "RCPT TO: <test@example.com>\r\n");
    $response = fgets($connection, 512);
    echo "   RCPT TO: " . trim($response) . "\n";
    
    if (strpos($response, '250') === false) {
        echo "   ❌ RCPT TO fehlgeschlagen\n";
        fclose($connection);
        exit;
    }
    
    // DATA
    fwrite($connection, "DATA\r\n");
    $response = fgets($connection, 512);
    echo "   DATA: " . trim($response) . "\n";
    
    if (strpos($response, '354') === false) {
        echo "   ❌ DATA fehlgeschlagen\n";
        fclose($connection);
        exit;
    }
    
    // E-Mail senden
    $email_data = "From: $smtp_from_name <$smtp_from_email>\r\n";
    $email_data .= "To: test@example.com\r\n";
    $email_data .= "Subject: SMTP Debug Test\r\n";
    $email_data .= "Content-Type: text/html; charset=UTF-8\r\n";
    $email_data .= "\r\n";
    $email_data .= "<h2>SMTP Debug Test</h2><p>Diese E-Mail wurde über SMTP gesendet.</p>\r\n";
    $email_data .= ".\r\n";
    
    fwrite($connection, $email_data);
    $response = fgets($connection, 512);
    echo "   E-Mail: " . trim($response) . "\n";
    
    if (strpos($response, '250') === false) {
        echo "   ❌ E-Mail-Versand fehlgeschlagen\n";
        fclose($connection);
        exit;
    }
    
    echo "   ✅ E-Mail erfolgreich gesendet\n";
    
    // QUIT
    fwrite($connection, "QUIT\r\n");
    $response = fgets($connection, 512);
    echo "   QUIT: " . trim($response) . "\n";
    
    fclose($connection);
    
    echo "\n🎉 SMTP-Verbindung erfolgreich getestet!\n";
    echo "   Die E-Mail sollte in Ihrem Postfach ankommen.\n";
    
} catch (Exception $e) {
    echo "❌ Fehler: " . $e->getMessage() . "\n";
}

echo "\n🎯 Debug abgeschlossen!\n";
?>
