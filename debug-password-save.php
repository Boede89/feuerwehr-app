<?php
/**
 * Debug: Passwort-Speichern in der Datenbank
 */

require_once 'config/database.php';

echo "🔍 Passwort-Speichern Debug\n";
echo "===========================\n\n";

try {
    // 1. Aktuelle SMTP-Einstellungen anzeigen
    echo "1. Aktuelle SMTP-Einstellungen:\n";
    $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'smtp_%'");
    $stmt->execute();
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    foreach ($settings as $key => $value) {
        if ($key === 'smtp_password') {
            echo "   $key: " . (!empty($value) ? 'GESETZT (' . strlen($value) . ' Zeichen)' : 'LEER') . "\n";
        } else {
            echo "   $key: " . ($value ?: 'LEER') . "\n";
        }
    }
    echo "\n";

    // 2. Test-Passwort direkt in die Datenbank schreiben
    echo "2. Test-Passwort direkt speichern:\n";
    $test_password = 'test123456789012'; // 16 Zeichen wie Gmail App-Passwort
    
    // Prüfen ob Einstellung existiert
    $stmt = $db->prepare("SELECT COUNT(*) FROM settings WHERE setting_key = 'smtp_password'");
    $stmt->execute();
    $exists = $stmt->fetchColumn() > 0;
    
    if ($exists) {
        echo "   Aktualisiere bestehende smtp_password Einstellung...\n";
        $stmt = $db->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'smtp_password'");
        $result = $stmt->execute([$test_password]);
    } else {
        echo "   Erstelle neue smtp_password Einstellung...\n";
        $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('smtp_password', ?)");
        $result = $stmt->execute([$test_password]);
    }
    
    if ($result) {
        echo "   ✅ Test-Passwort erfolgreich gespeichert\n";
    } else {
        echo "   ❌ Fehler beim Speichern des Test-Passworts\n";
    }
    echo "\n";

    // 3. Gespeichertes Passwort überprüfen
    echo "3. Gespeichertes Passwort überprüfen:\n";
    $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'smtp_password'");
    $stmt->execute();
    $saved_password = $stmt->fetchColumn();
    
    if ($saved_password === $test_password) {
        echo "   ✅ Passwort korrekt gespeichert und gelesen\n";
        echo "   Länge: " . strlen($saved_password) . " Zeichen\n";
        echo "   Inhalt: " . substr($saved_password, 0, 4) . "..." . substr($saved_password, -4) . "\n";
    } else {
        echo "   ❌ Passwort stimmt nicht überein!\n";
        echo "   Erwartet: $test_password\n";
        echo "   Gespeichert: " . ($saved_password ?: 'LEER') . "\n";
    }
    echo "\n";

    // 4. Datenbank-Schema prüfen
    echo "4. Datenbank-Schema prüfen:\n";
    $stmt = $db->prepare("DESCRIBE settings");
    $stmt->execute();
    $columns = $stmt->fetchAll();
    
    foreach ($columns as $column) {
        echo "   {$column['Field']}: {$column['Type']} " . ($column['Null'] === 'YES' ? 'NULL' : 'NOT NULL') . "\n";
    }
    echo "\n";

    // 5. Alle Einstellungen mit Passwort anzeigen
    echo "5. Alle Einstellungen mit Passwort:\n";
    $stmt = $db->prepare("SELECT setting_key, setting_value, LENGTH(setting_value) as length FROM settings WHERE setting_key LIKE '%password%'");
    $stmt->execute();
    $password_settings = $stmt->fetchAll();
    
    foreach ($password_settings as $setting) {
        echo "   {$setting['setting_key']}: Länge {$setting['length']} Zeichen\n";
        if (!empty($setting['setting_value'])) {
            echo "     Inhalt: " . substr($setting['setting_value'], 0, 4) . "..." . substr($setting['setting_value'], -4) . "\n";
        }
    }
    echo "\n";

    // 6. Test mit verschiedenen Passwort-Formaten
    echo "6. Test mit verschiedenen Passwort-Formaten:\n";
    $test_passwords = [
        'simple123',
        'test with spaces',
        'test-with-dashes',
        'test.with.dots',
        'test@with@symbols',
        'test12345678901234567890', // Sehr lang
        'test', // Sehr kurz
        '' // Leer
    ];
    
    foreach ($test_passwords as $index => $test_pwd) {
        $stmt = $db->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'smtp_password'");
        $result = $stmt->execute([$test_pwd]);
        
        $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'smtp_password'");
        $stmt->execute();
        $retrieved = $stmt->fetchColumn();
        
        $status = ($retrieved === $test_pwd) ? '✅' : '❌';
        echo "   Test " . ($index + 1) . " ($test_pwd): $status\n";
    }
    echo "\n";

    // 7. Empfehlungen
    echo "7. Empfehlungen:\n";
    echo "===============\n";
    
    if ($saved_password === $test_password) {
        echo "✅ Passwort-Speichern funktioniert korrekt!\n";
        echo "   Das Problem liegt wahrscheinlich in der Web-Oberfläche.\n";
        echo "   Mögliche Ursachen:\n";
        echo "   - CSRF-Token-Problem\n";
        echo "   - Formular-Validierung blockiert das Speichern\n";
        echo "   - JavaScript-Fehler verhindert das Absenden\n";
        echo "   - PHP-Fehler beim Verarbeiten des Formulars\n";
    } else {
        echo "❌ Passwort-Speichern funktioniert NICHT!\n";
        echo "   Mögliche Ursachen:\n";
        echo "   - Datenbank-Schema-Problem\n";
        echo "   - Zeichen-Encoding-Problem\n";
        echo "   - Datenbank-Berechtigungen\n";
    }
    
    echo "\n🔧 Nächste Schritte:\n";
    echo "1. Setzen Sie das Passwort direkt in der Datenbank:\n";
    echo "   docker exec feuerwehr_mysql mysql -u feuerwehr_user -pfeuerwehr_password -e \"UPDATE settings SET setting_value = 'IHR_GMAIL_APP_PASSWORT' WHERE setting_key = 'smtp_password';\" feuerwehr_app\n";
    echo "\n2. Oder verwenden Sie das fix-smtp-settings.php Skript\n";
    echo "\n3. Testen Sie dann das E-Mail-System erneut\n";

} catch (Exception $e) {
    echo "❌ Fehler: " . $e->getMessage() . "\n";
}

echo "\n🎯 Passwort-Speichern-Debug abgeschlossen!\n";
?>
