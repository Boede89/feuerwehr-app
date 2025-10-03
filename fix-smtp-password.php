<?php
/**
 * Fix-Skript für SMTP-Passwort-Problem
 */

require_once 'config/database.php';

echo "🔧 SMTP-Passwort Fix\n";
echo "===================\n\n";

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
    
    // 2. Prüfe ob smtp_password Eintrag existiert
    echo "\n2. Prüfe smtp_password Eintrag:\n";
    $stmt = $db->prepare("SELECT COUNT(*) FROM settings WHERE setting_key = 'smtp_password'");
    $stmt->execute();
    $count = $stmt->fetchColumn();
    
    echo "   smtp_password Einträge: $count\n";
    
    if ($count == 0) {
        echo "   ⚠️  smtp_password Eintrag existiert nicht! Erstelle...\n";
        $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('smtp_password', '')");
        $stmt->execute();
        echo "   ✅ smtp_password Eintrag erstellt\n";
    }
    
    // 3. Teste Passwort-Speicherung
    echo "\n3. Teste Passwort-Speicherung:\n";
    $test_password = 'test123456789';
    
    $stmt = $db->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'smtp_password'");
    $result = $stmt->execute([$test_password]);
    
    echo "   UPDATE Ergebnis: " . ($result ? 'ERFOLGREICH' : 'FEHLGESCHLAGEN') . "\n";
    
    // 4. Prüfe ob Passwort gespeichert wurde
    echo "\n4. Prüfe gespeichertes Passwort:\n";
    $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'smtp_password'");
    $stmt->execute();
    $saved_password = $stmt->fetchColumn();
    
    echo "   Gespeichertes Passwort: " . (empty($saved_password) ? 'LEER' : 'GESETZT (' . strlen($saved_password) . ' Zeichen)') . "\n";
    echo "   Passwort korrekt: " . ($saved_password === $test_password ? 'JA' : 'NEIN') . "\n";
    
    // 5. Lösche Test-Passwort
    echo "\n5. Lösche Test-Passwort:\n";
    $stmt = $db->prepare("UPDATE settings SET setting_value = '' WHERE setting_key = 'smtp_password'");
    $stmt->execute();
    echo "   ✅ Test-Passwort gelöscht\n";
    
    echo "\n6. Anweisungen:\n";
    echo "   Gehen Sie zu: http://192.168.10.150/admin/settings.php\n";
    echo "   Scrollen Sie zu 'SMTP-Einstellungen'\n";
    echo "   Geben Sie Ihr Gmail App-Passwort in das Feld 'SMTP-Passwort' ein\n";
    echo "   Klicken Sie 'Einstellungen speichern'\n";
    echo "   Testen Sie dann die E-Mail-Funktion\n";
    
} catch (Exception $e) {
    echo "❌ Fehler: " . $e->getMessage() . "\n";
}

echo "\n🎯 Fix abgeschlossen!\n";
?>
