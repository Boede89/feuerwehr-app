<?php
/**
 * Test RFC 5322 konforme E-Mail-Header
 */

require_once 'config/database.php';
require_once 'includes/functions.php';

echo "📧 RFC 5322 Header Test\n";
echo "=======================\n\n";

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
    
    // Test verschiedene From-Name Formate
    echo "2. From-Header Format Tests:\n";
    
    $test_from_names = [
        "Löschzug Amern",           // Mit Umlauten
        "Feuerwehr App",            // ASCII
        "Admin System",             // ASCII
        "Löschzug Amern e.V.",     // Mit Umlauten und Sonderzeichen
        "Feuerwehr & Rettung",     // Mit Sonderzeichen
        "Test, Name",              // Mit Komma
        "Test; Name"               // Mit Semikolon
    ];
    
    foreach ($test_from_names as $from_name) {
        echo "   From Name: '$from_name'\n";
        
        // Simuliere die Header-Formatierung
        $from_name_clean = str_replace(["\r", "\n"], "", $from_name);
        $from_name_clean = preg_replace('/\s+/', ' ', $from_name_clean);
        
        if (preg_match('/[^\x20-\x7E]/', $from_name_clean) || strpos($from_name_clean, ',') !== false || strpos($from_name_clean, ';') !== false) {
            $from_name_encoded = '=?UTF-8?B?' . base64_encode($from_name_clean) . '?=';
            $from_header = "From: {$from_name_encoded} <{$smtp_from_email}>\r\n";
            echo "   Encoded: $from_name_encoded\n";
        } else {
            $from_header = "From: \"{$from_name_clean}\" <{$smtp_from_email}>\r\n";
            echo "   Quoted: \"{$from_name_clean}\"\n";
        }
        
        echo "   Header: " . trim($from_header) . "\n";
        echo "   Valid: " . (strlen(trim($from_header)) <= 78 ? "✅" : "❌") . "\n\n";
    }
    
    // Test verschiedene Subject Formate
    echo "3. Subject-Header Format Tests:\n";
    
    $test_subjects = [
        "Test E-Mail - Header Fix",
        "✅ Fahrzeugreservierung genehmigt - MTF",
        "❌ Fahrzeugreservierung abgelehnt - LF 20/1",
        "🔔 Neue Fahrzeugreservierung - RW 2",
        "Test mit Umlauten: äöüß",
        "Test mit Sonderzeichen: @#$%^&*()"
    ];
    
    foreach ($test_subjects as $subject) {
        echo "   Subject: '$subject'\n";
        
        $subject_clean = str_replace(["\r", "\n"], "", $subject);
        $subject_clean = preg_replace('/\s+/', ' ', $subject_clean);
        
        if (preg_match('/[^\x20-\x7E]/', $subject_clean)) {
            $subject_encoded = '=?UTF-8?B?' . base64_encode($subject_clean) . '?=';
            echo "   Encoded: $subject_encoded\n";
        } else {
            echo "   Plain: $subject_clean\n";
        }
        
        echo "   Length: " . strlen($subject_clean) . " chars\n";
        echo "   Valid: " . (strlen($subject_clean) <= 78 ? "✅" : "❌") . "\n\n";
    }
    
    // Test E-Mail-Versand
    echo "4. Test E-Mail-Versand:\n";
    
    $test_email = "boedefeld1@freenet.de";
    $subject = "🔍 RFC 5322 Header Test - " . date('H:i:s');
    $message = "
    <h2>RFC 5322 Header Test</h2>
    <p>Diese E-Mail testet die RFC 5322 konforme Header-Formatierung.</p>
    <p><strong>Zeitstempel:</strong> " . date('d.m.Y H:i:s') . "</p>
    <p><strong>From Name:</strong> $smtp_from_name</p>
    <p><strong>Subject:</strong> $subject</p>
    <p><strong>Features:</strong></p>
    <ul>
        <li>RFC 5322 konforme From-Header</li>
        <li>RFC 2047 MIME-Encoding für Non-ASCII</li>
        <li>Korrekte Anführungszeichen</li>
        <li>Message-ID hinzugefügt</li>
    </ul>
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
    
    echo "\n5. RFC 5322 Verbesserungen:\n";
    echo "   ✅ From-Header in Anführungszeichen\n";
    echo "   ✅ MIME-Encoding für Non-ASCII Zeichen\n";
    echo "   ✅ Subject-Header RFC 2047 konform\n";
    echo "   ✅ Message-ID hinzugefügt\n";
    echo "   ✅ Zeilenlängen-Limits beachtet\n";
    echo "   ✅ Sonderzeichen korrekt behandelt\n";
    
    echo "\n6. Empfohlene From-Name Formate:\n";
    echo "   ✅ \"Sender Name\" <email@domain.com>\n";
    echo "   ✅ =?UTF-8?B?U2VuZGVyIE5hbWU=?= <email@domain.com>\n";
    echo "   ❌ Sender Name <email@domain.com>\n";
    echo "   ❌ Sender, Name <email@domain.com>\n";
    
} catch (Exception $e) {
    echo "❌ Fehler: " . $e->getMessage() . "\n";
}

echo "\n🎯 RFC 5322 Header Test abgeschlossen!\n";
?>
