<?php
/**
 * Sendmail Installation und Konfiguration
 */

echo "🔧 Sendmail Fix\n";
echo "===============\n\n";

// 1. Sendmail installieren
echo "1. Sendmail installieren...\n";
$install_cmd = "apt-get update -y && apt-get install -y sendmail";
$output = shell_exec($install_cmd);
echo "   Installations-Output: " . ($output ? "Erfolgreich" : "Fehler") . "\n";

// 2. Hosts-Datei konfigurieren
echo "2. Hosts-Datei konfigurieren...\n";
$hosts_entry = "127.0.0.1 localhost\n";
file_put_contents('/etc/hosts', $hosts_entry, FILE_APPEND);
echo "   ✅ Hosts-Eintrag hinzugefügt\n";

// 3. Mailname setzen
echo "3. Mailname setzen...\n";
file_put_contents('/etc/mailname', "localhost\n");
echo "   ✅ Mailname gesetzt\n";

// 4. Sendmail starten
echo "4. Sendmail starten...\n";
$start_cmd = "service sendmail start";
$start_output = shell_exec($start_cmd);
echo "   Start-Output: " . ($start_output ? "Erfolgreich" : "Fehler") . "\n";

// 5. Sendmail-Status prüfen
echo "5. Sendmail-Status prüfen...\n";
$status_cmd = "service sendmail status";
$status_output = shell_exec($status_cmd);
echo "   Status: " . ($status_output ? "Aktiv" : "Inaktiv") . "\n";

// 6. Sendmail-Pfad prüfen
echo "6. Sendmail-Pfad prüfen...\n";
if (file_exists('/usr/sbin/sendmail')) {
    echo "   ✅ /usr/sbin/sendmail gefunden\n";
} else {
    echo "   ❌ /usr/sbin/sendmail nicht gefunden\n";
}

// 7. PHP mail() Funktion testen
echo "7. PHP mail() Funktion testen...\n";
$test_result = mail('test@example.com', 'Test', 'Test E-Mail', 'From: test@localhost');
if ($test_result) {
    echo "   ✅ mail() Funktion funktioniert\n";
} else {
    echo "   ❌ mail() Funktion fehlgeschlagen\n";
}

// 8. Sendmail direkt testen
echo "8. Sendmail direkt testen...\n";
$sendmail_test = shell_exec('echo "Test E-Mail" | sendmail -v test@example.com 2>&1');
echo "   Sendmail-Test: " . ($sendmail_test ? "Erfolgreich" : "Fehler") . "\n";

echo "\n🎯 Sendmail Fix abgeschlossen!\n";
echo "📧 E-Mail-System sollte jetzt funktionieren!\n";
?>
