<?php
/**
 * Detaillierte Gmail SMTP-Diagnose
 */

require_once 'config/database.php';
require_once 'includes/functions.php';
require_once 'includes/smtp.php';

echo "🔍 Detaillierte Gmail SMTP-Diagnose\n";
echo "===================================\n\n";

try {
    // 1. SMTP-Einstellungen laden
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

    // 2. Passwort-Format prüfen
    echo "2. Passwort-Format prüfen:\n";
    if (strlen($smtp_password) == 16) {
        echo "   ✅ Passwort-Länge korrekt (16 Zeichen)\n";
    } else {
        echo "   ⚠️ Passwort-Länge ungewöhnlich: " . strlen($smtp_password) . " Zeichen (erwartet: 16)\n";
        echo "   💡 Gmail App-Passwörter haben normalerweise 16 Zeichen\n";
    }
    
    // Prüfe ob Passwort nur aus Buchstaben und Zahlen besteht
    if (ctype_alnum($smtp_password)) {
        echo "   ✅ Passwort enthält nur Buchstaben und Zahlen\n";
    } else {
        echo "   ⚠️ Passwort enthält Sonderzeichen (könnte problematisch sein)\n";
    }
    echo "\n";

    // 3. Gmail SMTP-Verbindung Schritt für Schritt testen
    echo "3. Gmail SMTP-Verbindung testen:\n";
    
    // Socket-Verbindung
    echo "   a) Socket-Verbindung zu $smtp_host:$smtp_port...\n";
    $socket = fsockopen($smtp_host, $smtp_port, $errno, $errstr, 30);
    if (!$socket) {
        echo "   ❌ Verbindung fehlgeschlagen: $errstr ($errno)\n";
        exit;
    }
    echo "   ✅ Socket-Verbindung erfolgreich\n";

    // Server-Begrüßung
    $response = fgets($socket, 512);
    echo "   b) Server-Begrüßung: " . trim($response) . "\n";
    if (strpos($response, '220') !== 0) {
        echo "   ❌ Ungültige Server-Antwort\n";
        fclose($socket);
        exit;
    }

    // EHLO
    echo "   c) EHLO senden...\n";
    fputs($socket, "EHLO localhost\r\n");
    $response = fgets($socket, 512);
    echo "   EHLO Antwort: " . trim($response) . "\n";
    
    // Weitere EHLO-Antworten lesen
    while (strpos($response, '250-') === 0) {
        $response = fgets($socket, 512);
        echo "   EHLO (2): " . trim($response) . "\n";
    }

    // STARTTLS
    echo "   d) STARTTLS senden...\n";
    fputs($socket, "STARTTLS\r\n");
    $response = fgets($socket, 512);
    echo "   STARTTLS Antwort: " . trim($response) . "\n";
    
    if (strpos($response, '220') === 0) {
        echo "   ✅ STARTTLS akzeptiert\n";
        
        // TLS-Verbindung starten
        echo "   e) TLS-Verbindung starten...\n";
        if (stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            echo "   ✅ TLS-Verbindung erfolgreich\n";
        } else {
            echo "   ❌ TLS-Verbindung fehlgeschlagen\n";
            fclose($socket);
            exit;
        }
        
        // EHLO nach TLS
        echo "   f) EHLO nach TLS...\n";
        fputs($socket, "EHLO localhost\r\n");
        $response = fgets($socket, 512);
        echo "   EHLO (TLS): " . trim($response) . "\n";
        
        // Weitere EHLO-Antworten nach TLS
        while (strpos($response, '250-') === 0) {
            $response = fgets($socket, 512);
            echo "   EHLO (TLS 2): " . trim($response) . "\n";
        }
        
        // AUTH LOGIN
        echo "   g) AUTH LOGIN senden...\n";
        fputs($socket, "AUTH LOGIN\r\n");
        $response = fgets($socket, 512);
        echo "   AUTH LOGIN Antwort: " . trim($response) . "\n";
        
        if (strpos($response, '334') === 0) {
            echo "   ✅ AUTH LOGIN akzeptiert\n";
            
            // Username senden
            echo "   h) Username senden...\n";
            $username_b64 = base64_encode($smtp_username);
            fputs($socket, $username_b64 . "\r\n");
            $response = fgets($socket, 512);
            echo "   Username Antwort: " . trim($response) . "\n";
            
            if (strpos($response, '334') === 0) {
                echo "   ✅ Username akzeptiert\n";
                
                // Password senden
                echo "   i) Password senden...\n";
                $password_b64 = base64_encode($smtp_password);
                fputs($socket, $password_b64 . "\r\n");
                $response = fgets($socket, 512);
                echo "   Password Antwort: " . trim($response) . "\n";
                
                if (strpos($response, '235') === 0) {
                    echo "   ✅ Authentifizierung erfolgreich!\n";
                    
                    // E-Mail senden
                    echo "   j) E-Mail senden...\n";
                    fputs($socket, "MAIL FROM:<$smtp_from_email>\r\n");
                    $response = fgets($socket, 512);
                    echo "   MAIL FROM: " . trim($response) . "\n";
                    
                    fputs($socket, "RCPT TO:<dleuchtenberg89@gmail.com>\r\n");
                    $response = fgets($socket, 512);
                    echo "   RCPT TO: " . trim($response) . "\n";
                    
                    fputs($socket, "DATA\r\n");
                    $response = fgets($socket, 512);
                    echo "   DATA: " . trim($response) . "\n";
                    
                    $email_content = "Subject: Gmail SMTP Test " . date('H:i:s') . "\r\n";
                    $email_content .= "From: $smtp_from_name <$smtp_from_email>\r\n";
                    $email_content .= "To: dleuchtenberg89@gmail.com\r\n";
                    $email_content .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";
                    $email_content .= "<h2>Gmail SMTP Test erfolgreich!</h2>\r\n";
                    $email_content .= "<p>Zeitstempel: " . date('d.m.Y H:i:s') . "</p>\r\n";
                    $email_content .= ".\r\n";
                    
                    fputs($socket, $email_content);
                    $response = fgets($socket, 512);
                    echo "   E-Mail: " . trim($response) . "\n";
                    
                    if (strpos($response, '250') === 0) {
                        echo "   ✅ E-Mail erfolgreich gesendet!\n";
                    } else {
                        echo "   ❌ E-Mail-Versand fehlgeschlagen\n";
                    }
                    
                } else {
                    echo "   ❌ Authentifizierung fehlgeschlagen\n";
                    echo "   💡 Mögliche Ursachen:\n";
                    echo "      - Gmail App-Passwort ist falsch\n";
                    echo "      - 2-Faktor-Authentifizierung nicht aktiviert\n";
                    echo "      - App-Passwort wurde widerrufen\n";
                }
            } else {
                echo "   ❌ Username nicht akzeptiert\n";
            }
        } else {
            echo "   ❌ AUTH LOGIN nicht akzeptiert\n";
        }
    } else {
        echo "   ❌ STARTTLS nicht akzeptiert\n";
    }

    fclose($socket);

} catch (Exception $e) {
    echo "❌ Fehler: " . $e->getMessage() . "\n";
}

echo "\n🎯 Gmail SMTP-Diagnose abgeschlossen!\n";
?>
