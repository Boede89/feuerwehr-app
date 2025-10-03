<?php
/**
 * SMTP-Einstellungen überprüfen und korrigieren
 */

require_once 'config/database.php';

echo "🔧 SMTP-Einstellungen überprüfen und korrigieren\n";
echo "===============================================\n\n";

try {
    // Aktuelle Einstellungen laden
    echo "1. Aktuelle SMTP-Einstellungen:\n";
    $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'smtp_%'");
    $stmt->execute();
    $current_settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    foreach ($current_settings as $key => $value) {
        echo "   $key: " . ($value ?: 'LEER') . "\n";
    }
    echo "\n";

    // Empfohlene Gmail-Einstellungen
    $recommended_settings = [
        'smtp_host' => 'smtp.gmail.com',
        'smtp_port' => '587',
        'smtp_username' => 'loeschzug.amern@gmail.com', // Ihre E-Mail
        'smtp_password' => '', // Muss manuell gesetzt werden
        'smtp_encryption' => 'tls',
        'smtp_from_email' => 'loeschzug.amern@gmail.com',
        'smtp_from_name' => 'Löschzug Amern'
    ];

    echo "2. Empfohlene Gmail-Einstellungen:\n";
    foreach ($recommended_settings as $key => $value) {
        echo "   $key: " . ($value ?: 'MUSS GESETZT WERDEN') . "\n";
    }
    echo "\n";

    // Einstellungen aktualisieren (außer Passwort)
    echo "3. Einstellungen aktualisieren:\n";
    foreach ($recommended_settings as $key => $value) {
        if ($key !== 'smtp_password' && !empty($value)) {
            // Prüfen ob Einstellung existiert
            $stmt = $db->prepare("SELECT COUNT(*) FROM settings WHERE setting_key = ?");
            $stmt->execute([$key]);
            $exists = $stmt->fetchColumn() > 0;
            
            if ($exists) {
                $stmt = $db->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?");
                $stmt->execute([$value, $key]);
                echo "   ✅ $key aktualisiert: $value\n";
            } else {
                $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)");
                $stmt->execute([$key, $value]);
                echo "   ✅ $key hinzugefügt: $value\n";
            }
        }
    }
    echo "\n";

    // Passwort-Status prüfen
    echo "4. Passwort-Status:\n";
    $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'smtp_password'");
    $stmt->execute();
    $password = $stmt->fetchColumn();
    
    if (empty($password)) {
        echo "   ❌ SMTP-Passwort ist NICHT gesetzt!\n";
        echo "   → Gehen Sie zu: http://192.168.10.150/admin/settings.php\n";
        echo "   → Erstellen Sie ein Gmail App-Passwort:\n";
        echo "     1. Gehen Sie zu: https://myaccount.google.com/security\n";
        echo "     2. Aktivieren Sie 2-Faktor-Authentifizierung\n";
        echo "     3. Erstellen Sie ein App-Passwort für 'Mail'\n";
        echo "     4. Kopieren Sie das 16-stellige Passwort\n";
        echo "     5. Fügen Sie es in die SMTP-Einstellungen ein\n";
    } else {
        echo "   ✅ SMTP-Passwort ist gesetzt (" . strlen($password) . " Zeichen)\n";
    }
    echo "\n";

    // Test-Einstellungen validieren
    echo "5. Einstellungen validieren:\n";
    $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_encryption', 'smtp_from_email', 'smtp_from_name')");
    $stmt->execute();
    $final_settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    $all_set = true;
    foreach (['smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_encryption', 'smtp_from_email', 'smtp_from_name'] as $key) {
        if (empty($final_settings[$key])) {
            echo "   ❌ $key ist leer\n";
            $all_set = false;
        } else {
            echo "   ✅ $key ist gesetzt\n";
        }
    }
    
    if ($all_set) {
        echo "\n   🎉 Alle SMTP-Einstellungen sind korrekt konfiguriert!\n";
    } else {
        echo "\n   ⚠️ Einige SMTP-Einstellungen fehlen noch.\n";
    }
    echo "\n";

    // Gmail-spezifische Hinweise
    echo "6. Gmail-spezifische Hinweise:\n";
    echo "   📧 Gmail erfordert:\n";
    echo "   - 2-Faktor-Authentifizierung aktiviert\n";
    echo "   - App-Passwort (nicht das normale Passwort!)\n";
    echo "   - Port 587 mit TLS-Verschlüsselung\n";
    echo "   - Host: smtp.gmail.com\n";
    echo "\n";
    echo "   🔐 App-Passwort erstellen:\n";
    echo "   1. https://myaccount.google.com/security\n";
    echo "   2. '2-Schritt-Verifizierung' aktivieren\n";
    echo "   3. 'App-Passwörter' → 'Mail' auswählen\n";
    echo "   4. 16-stelliges Passwort kopieren\n";
    echo "   5. In SMTP-Einstellungen einfügen\n";
    echo "\n";

} catch (Exception $e) {
    echo "❌ Fehler: " . $e->getMessage() . "\n";
}

echo "🎯 SMTP-Einstellungen-Überprüfung abgeschlossen!\n";
echo "📧 Führen Sie 'debug-email-system.php' aus, um das E-Mail-System zu testen.\n";
?>
