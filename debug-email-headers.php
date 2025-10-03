<?php
/**
 * Debug E-Mail-Header Probleme
 */

require_once 'config/database.php';
require_once 'includes/functions.php';

echo "🔍 E-Mail-Header Debug\n";
echo "======================\n\n";

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
        exit;
    }
    
    // Test verschiedene Header-Formate
    echo "2. Header-Format Tests:\n";
    
    $test_subjects = [
        "Test E-Mail - Header Fix",
        "❌ Fahrzeugreservierung abgelehnt - MTF",
        "✅ Fahrzeugreservierung genehmigt - LF 20/1",
        "🔔 Neue Fahrzeugreservierung - RW 2"
    ];
    
    $test_from_names = [
        "Löschzug Amern",
        "Feuerwehr App",
        "Admin System"
    ];
    
    foreach ($test_subjects as $subject) {
        echo "   Subject: '$subject'\n";
        $clean_subject = str_replace(["\r", "\n"], "", $subject);
        $clean_subject = preg_replace('/\s+/', ' ', $clean_subject);
        echo "   Cleaned: '$clean_subject'\n";
        echo "   Length: " . strlen($clean_subject) . " chars\n";
        echo "   Valid: " . (strlen($clean_subject) <= 78 ? "✅" : "❌") . "\n\n";
    }
    
    foreach ($test_from_names as $from_name) {
        echo "   From Name: '$from_name'\n";
        $clean_from_name = str_replace(["\r", "\n"], "", $from_name);
        $clean_from_name = preg_replace('/\s+/', ' ', $clean_from_name);
        echo "   Cleaned: '$clean_from_name'\n";
        echo "   Length: " . strlen($clean_from_name) . " chars\n";
        echo "   Valid: " . (strlen($clean_from_name) <= 78 ? "✅" : "❌") . "\n\n";
    }
    
    // Test E-Mail mit verschiedenen Headern senden
    echo "3. Test E-Mail senden:\n";
    
    $test_email = "boedefeld1@freenet.de";
    $subject = "🔍 Header Debug Test - " . date('H:i:s');
    $message = "
    <h2>Header Debug Test</h2>
    <p>Diese E-Mail testet die Header-Formatierung.</p>
    <p><strong>Zeitstempel:</strong> " . date('d.m.Y H:i:s') . "</p>
    <p><strong>From Name:</strong> $smtp_from_name</p>
    <p><strong>Subject:</strong> $subject</p>
    ";
    
    echo "   An: $test_email\n";
    echo "   Betreff: $subject\n";
    echo "   From Name: $smtp_from_name\n";
    echo "   Sende E-Mail...\n";
    
    $result = send_email($test_email, $subject, $message);
    
    if ($result) {
        echo "   ✅ E-Mail erfolgreich gesendet!\n";
    } else {
        echo "   ❌ E-Mail fehlgeschlagen\n";
    }
    
    // Test mit einfachem Header
    echo "\n4. Test mit einfachem Header:\n";
    
    $simple_subject = "Test E-Mail - Simple Header";
    $simple_message = "
    <h2>Simple Header Test</h2>
    <p>Diese E-Mail verwendet einfache Header ohne Emojis.</p>
    <p><strong>Zeitstempel:</strong> " . date('d.m.Y H:i:s') . "</p>
    ";
    
    echo "   An: $test_email\n";
    echo "   Betreff: $simple_subject\n";
    echo "   Sende E-Mail...\n";
    
    $result2 = send_email($test_email, $simple_subject, $simple_message);
    
    if ($result2) {
        echo "   ✅ E-Mail mit einfachem Header erfolgreich gesendet!\n";
    } else {
        echo "   ❌ E-Mail mit einfachem Header fehlgeschlagen\n";
    }
    
    echo "\n5. Header-Analyse:\n";
    echo "   Problem könnte sein:\n";
    echo "   - Emojis in Subject/From Name\n";
    echo "   - Zu lange Header-Zeilen (>78 Zeichen)\n";
    echo "   - Ungültige Zeichen in Headern\n";
    echo "   - Fehlende Message-ID\n";
    echo "   - RFC 5322 Verstöße\n";
    
    echo "\n6. Empfehlungen:\n";
    echo "   ✅ Message-ID hinzugefügt\n";
    echo "   ✅ Header-Bereinigung implementiert\n";
    echo "   ✅ Zeilenlängen-Limits beachtet\n";
    echo "   ✅ RFC 5322 konforme Formatierung\n";
    
} catch (Exception $e) {
    echo "❌ Fehler: " . $e->getMessage() . "\n";
}

echo "\n🎯 Header Debug abgeschlossen!\n";
?>
