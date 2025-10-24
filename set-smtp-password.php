<?php
/**
 * Setzt das SMTP-Passwort direkt in der Datenbank
 */

require_once 'config/database.php';

echo "🔧 SMTP-Passwort direkt setzen\n";
echo "==============================\n\n";

// Gmail App-Passwort hier einfügen (16 Zeichen ohne Leerzeichen)
$gmail_app_password = 'tnli grex fdpw dmhv'; // ← HIER DAS PASSWORT EINFÜGEN

if ($gmail_app_password === 'IHR_GMAIL_APP_PASSWORT_HIER') {
    echo "❌ Bitte setzen Sie zuerst das Gmail App-Passwort in der Datei!\n";
    echo "   Öffnen Sie diese Datei und ersetzen Sie 'IHR_GMAIL_APP_PASSWORT_HIER'\n";
    echo "   mit Ihrem 16-stelligen Gmail App-Passwort.\n";
    echo "\n   Beispiel:\n";
    echo "   \$gmail_app_password = 'abcd efgh ijkl mnop';\n";
    exit;
}

try {
    echo "1. Setze SMTP-Passwort in der Datenbank...\n";
    echo "   Passwort-Länge: " . strlen($gmail_app_password) . " Zeichen\n";
    echo "   Passwort (erste 4 Zeichen): " . substr($gmail_app_password, 0, 4) . "...\n\n";
    
    // Prüfen ob Einstellung existiert
    $stmt = $db->prepare("SELECT COUNT(*) FROM settings WHERE setting_key = 'smtp_password'");
    $stmt->execute();
    $exists = $stmt->fetchColumn() > 0;
    
    if ($exists) {
        echo "2. Aktualisiere bestehende smtp_password Einstellung...\n";
        $stmt = $db->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'smtp_password'");
        $result = $stmt->execute([$gmail_app_password]);
        echo "   ✅ Passwort aktualisiert\n";
    } else {
        echo "2. Erstelle neue smtp_password Einstellung...\n";
        $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('smtp_password', ?)");
        $result = $stmt->execute([$gmail_app_password]);
        echo "   ✅ Passwort erstellt\n";
    }
    
    if (!$result) {
        echo "   ❌ Fehler beim Speichern des Passworts\n";
        exit;
    }
    
    // Überprüfen
    echo "\n3. Überprüfe gespeichertes Passwort...\n";
    $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'smtp_password'");
    $stmt->execute();
    $saved_password = $stmt->fetchColumn();
    
    if ($saved_password === $gmail_app_password) {
        echo "   ✅ Passwort korrekt gespeichert!\n";
        echo "   Länge: " . strlen($saved_password) . " Zeichen\n";
    } else {
        echo "   ❌ Passwort stimmt nicht überein!\n";
        echo "   Erwartet: " . substr($gmail_app_password, 0, 4) . "...\n";
        echo "   Gespeichert: " . substr($saved_password, 0, 4) . "...\n";
        exit;
    }
    
    // Alle SMTP-Einstellungen anzeigen
    echo "\n4. Aktuelle SMTP-Einstellungen:\n";
    $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'smtp_%' ORDER BY setting_key");
    $stmt->execute();
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    foreach ($settings as $key => $value) {
        if ($key === 'smtp_password') {
            echo "   $key: GESETZT (" . strlen($value) . " Zeichen)\n";
        } else {
            echo "   $key: " . ($value ?: 'LEER') . "\n";
        }
    }
    
    echo "\n🎉 SMTP-Passwort erfolgreich gesetzt!\n";
    echo "📧 Führen Sie jetzt 'debug-email-system.php' aus, um das E-Mail-System zu testen.\n";
    
} catch (Exception $e) {
    echo "❌ Fehler: " . $e->getMessage() . "\n";
}
?>
