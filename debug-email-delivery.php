<?php
/**
 * Detaillierte E-Mail-Zustellungs-Diagnose
 */

echo "🔍 E-Mail-Zustellungs-Diagnose\n";
echo "==============================\n\n";

// 1. System-Informationen
echo "1. System-Informationen:\n";
echo "   PHP Version: " . phpversion() . "\n";
echo "   Server: " . ($_SERVER['SERVER_SOFTWARE'] ?? 'Unbekannt') . "\n";
echo "   Hostname: " . gethostname() . "\n";
echo "   IP: " . gethostbyname(gethostname()) . "\n\n";

// 2. Sendmail-Status prüfen
echo "2. Sendmail-Status:\n";
$sendmail_path = ini_get('sendmail_path');
echo "   sendmail_path: $sendmail_path\n";
echo "   sendmail existiert: " . (file_exists('/usr/sbin/sendmail') ? 'JA' : 'NEIN') . "\n";

$sendmail_version = shell_exec('/usr/sbin/sendmail -V 2>&1');
echo "   sendmail Version: " . trim($sendmail_version) . "\n";

$sendmail_status = shell_exec('service sendmail status 2>&1');
echo "   sendmail Status: " . trim($sendmail_status) . "\n\n";

// 3. Mail-Logs prüfen
echo "3. Mail-Logs prüfen:\n";
$mail_logs = [
    '/var/log/mail.log',
    '/var/log/mail.err',
    '/var/log/syslog',
    '/var/log/php_errors.log'
];

foreach ($mail_logs as $log_file) {
    if (file_exists($log_file)) {
        $log_content = shell_exec("tail -10 $log_file 2>&1");
        echo "   $log_file (letzte 10 Zeilen):\n";
        echo "   " . str_replace("\n", "\n   ", trim($log_content)) . "\n\n";
    } else {
        echo "   $log_file: NICHT GEFUNDEN\n";
    }
}

// 4. E-Mail mit detailliertem Logging senden
echo "4. E-Mail mit detailliertem Logging senden:\n";
$to = 'dleuchtenberg89@gmail.com';
$subject = "🔍 E-Mail-Diagnose " . date('H:i:s');
$message = "
<html>
<head><title>E-Mail-Diagnose</title></head>
<body>
<h2>E-Mail-Diagnose Test</h2>
<p><strong>Zeitstempel:</strong> " . date('d.m.Y H:i:s') . "</p>
<p><strong>Server:</strong> " . gethostname() . "</p>
<p><strong>IP:</strong> " . gethostbyname(gethostname()) . "</p>
<p><strong>PHP Version:</strong> " . phpversion() . "</p>
<p><strong>Sendmail Path:</strong> $sendmail_path</p>
<p>Wenn Sie diese E-Mail erhalten, funktioniert das E-Mail-System!</p>
</body>
</html>
";

$headers = "From: Feuerwehr App <noreply@feuerwehr-app.local>\r\n";
$headers .= "Reply-To: noreply@feuerwehr-app.local\r\n";
$headers .= "Content-Type: text/html; charset=UTF-8\r\n";
$headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
$headers .= "X-Test-ID: " . uniqid() . "\r\n";

echo "   Empfänger: $to\n";
echo "   Betreff: $subject\n";
echo "   Headers: " . str_replace("\r\n", " | ", trim($headers)) . "\n";

// E-Mail senden
$result = mail($to, $subject, $message, $headers);

echo "   Ergebnis: " . ($result ? 'ERFOLGREICH' : 'FEHLGESCHLAGEN') . "\n\n";

// 5. Sendmail direkt testen
echo "5. Sendmail direkt testen:\n";
$test_message = "Subject: Test E-Mail direkt\n\nDies ist eine Test-E-Mail direkt über sendmail.\nZeitstempel: " . date('d.m.Y H:i:s') . "\n";
$sendmail_result = shell_exec("echo '$test_message' | /usr/sbin/sendmail -v $to 2>&1");
echo "   Sendmail direkt: " . trim($sendmail_result) . "\n\n";

// 6. DNS und Netzwerk prüfen
echo "6. DNS und Netzwerk prüfen:\n";
$dns_lookup = shell_exec("nslookup gmail.com 2>&1");
echo "   Gmail DNS: " . (strpos($dns_lookup, 'gmail.com') !== false ? 'OK' : 'FEHLER') . "\n";

$ping_result = shell_exec("ping -c 1 gmail.com 2>&1");
echo "   Gmail Ping: " . (strpos($ping_result, '1 received') !== false ? 'OK' : 'FEHLER') . "\n\n";

// 7. Mögliche Lösungen
echo "7. Mögliche Lösungen:\n";
echo "   - Prüfen Sie die Firewall-Einstellungen\n";
echo "   - Prüfen Sie, ob Port 25 blockiert ist\n";
echo "   - Verwenden Sie einen externen SMTP-Server\n";
echo "   - Prüfen Sie die Gmail-Spam-Filter\n";
echo "   - Testen Sie mit einer anderen E-Mail-Adresse\n\n";

echo "🎯 Diagnose abgeschlossen!\n";
echo "📧 Prüfen Sie die Logs für weitere Informationen!\n";
?>
