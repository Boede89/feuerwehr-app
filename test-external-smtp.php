<?php
/**
 * Test mit externem SMTP-Server
 */

echo "📧 Externer SMTP-Test\n";
echo "====================\n\n";

// SMTP-Einstellungen für Gmail
$smtp_host = 'smtp.gmail.com';
$smtp_port = 587;
$smtp_username = 'loeschzug.amern@gmail.com';
$smtp_password = 'Ihr_Gmail_App_Passwort_hier'; // Hier das echte App-Passwort einsetzen
$smtp_encryption = 'tls';

echo "1. SMTP-Verbindung zu Gmail testen:\n";
echo "   Host: $smtp_host\n";
echo "   Port: $smtp_port\n";
echo "   Username: $smtp_username\n";
echo "   Encryption: $smtp_encryption\n\n";

// Einfache SMTP-Verbindung testen
$socket = fsockopen($smtp_host, $smtp_port, $errno, $errstr, 30);
if (!$socket) {
    echo "   ❌ Verbindung fehlgeschlagen: $errstr ($errno)\n";
    exit;
}

echo "   ✅ Verbindung zu $smtp_host:$smtp_port erfolgreich\n";

// SMTP-Handshake
$response = fgets($socket, 512);
echo "   Server: " . trim($response) . "\n";

// EHLO senden
fputs($socket, "EHLO localhost\r\n");
$response = fgets($socket, 512);
echo "   EHLO: " . trim($response) . "\n";

// STARTTLS
fputs($socket, "STARTTLS\r\n");
$response = fgets($socket, 512);
echo "   STARTTLS: " . trim($response) . "\n";

if (strpos($response, '220') === 0) {
    echo "   ✅ TLS-Verbindung kann hergestellt werden\n";
} else {
    echo "   ❌ TLS-Verbindung fehlgeschlagen\n";
}

fclose($socket);

echo "\n2. Mögliche Lösungen:\n";
echo "   - Verwenden Sie einen externen SMTP-Server (Gmail, SendGrid, etc.)\n";
echo "   - Konfigurieren Sie Postfix mit externem Relay\n";
echo "   - Verwenden Sie einen E-Mail-Service wie Mailgun\n";
echo "   - Prüfen Sie die Firewall-Einstellungen\n\n";

echo "🎯 Externer SMTP-Test abgeschlossen!\n";
?>
