<?php
/**
 * Debug-Skript für SMTP-Speicherung
 */

require_once 'config/database.php';

echo "🔍 SMTP-Speicherung Debug\n";
echo "========================\n\n";

try {
    // 1. Prüfe aktuelle SMTP-Einstellungen
    echo "1. Aktuelle SMTP-Einstellungen:\n";
    $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'smtp_%'");
    $stmt->execute();
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    foreach ($settings as $key => $value) {
        if ($key === 'smtp_password') {
            echo "   $key: " . (empty($value) ? 'LEER' : 'GESETZT (' . strlen($value) . ' Zeichen)') . "\n";
        } else {
            echo "   $key: " . (empty($value) ? 'LEER' : $value) . "\n";
        }
    }
    
    // 2. Simuliere SMTP-Passwort-Speicherung
    echo "\n2. Simuliere SMTP-Passwort-Speicherung:\n";
    $test_password = 'test123456789';
    
    $stmt = $db->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'smtp_password'");
    $result = $stmt->execute([$test_password]);
    
    echo "   UPDATE Ergebnis: " . ($result ? 'ERFOLGREICH' : 'FEHLGESCHLAGEN') . "\n";
    
    // 3. Prüfe ob Passwort gespeichert wurde
    echo "\n3. Prüfe gespeichertes Passwort:\n";
    $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'smtp_password'");
    $stmt->execute();
    $saved_password = $stmt->fetchColumn();
    
    echo "   Gespeichertes Passwort: " . (empty($saved_password) ? 'LEER' : 'GESETZT (' . strlen($saved_password) . ' Zeichen)') . "\n";
    echo "   Passwort korrekt: " . ($saved_password === $test_password ? 'JA' : 'NEIN') . "\n";
    
    // 4. Prüfe ob smtp_password Eintrag existiert
    echo "\n4. Prüfe smtp_password Eintrag:\n";
    $stmt = $db->prepare("SELECT COUNT(*) FROM settings WHERE setting_key = 'smtp_password'");
    $stmt->execute();
    $count = $stmt->fetchColumn();
    
    echo "   smtp_password Einträge: $count\n";
    
    if ($count == 0) {
        echo "   ⚠️  smtp_password Eintrag existiert nicht! Erstelle...\n";
        $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('smtp_password', ?)");
        $stmt->execute([$test_password]);
        echo "   ✅ smtp_password Eintrag erstellt\n";
    }
    
    // 5. Teste E-Mail-Versand mit gespeicherten Einstellungen
    echo "\n5. Teste E-Mail-Versand:\n";
    require_once 'includes/functions.php';
    
    $to = 'test@example.com';
    $subject = 'Debug Test E-Mail';
    $message = '<h2>Debug Test</h2><p>Diese E-Mail wurde als Debug-Test gesendet.</p>';
    
    $result = send_email($to, $subject, $message);
    echo "   E-Mail-Versand: " . ($result ? 'ERFOLGREICH' : 'FEHLGESCHLAGEN') . "\n";
    
} catch (Exception $e) {
    echo "❌ Fehler: " . $e->getMessage() . "\n";
}

echo "\n🎯 Debug abgeschlossen!\n";
?>
