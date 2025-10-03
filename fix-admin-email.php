<?php
/**
 * Fix für Admin E-Mail-Problem
 */

require_once 'config/database.php';

echo "🔧 Admin E-Mail-Problem beheben\n";
echo "===============================\n\n";

try {
    // 1. Prüfe ob alle SMTP-Einstellungen vorhanden sind
    echo "1. SMTP-Einstellungen prüfen:\n";
    $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'smtp_%'");
    $stmt->execute();
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    $required_settings = ['smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_encryption', 'smtp_from_email', 'smtp_from_name'];
    $missing_settings = [];
    
    foreach ($required_settings as $key) {
        if (empty($settings[$key])) {
            $missing_settings[] = $key;
            echo "   ❌ $key: FEHLT\n";
        } else {
            echo "   ✅ $key: GESETZT\n";
        }
    }
    
    if (!empty($missing_settings)) {
        echo "\n   ⚠️ Fehlende Einstellungen: " . implode(', ', $missing_settings) . "\n";
        echo "   → Setze Standardwerte...\n";
        
        $default_settings = [
            'smtp_host' => 'smtp.gmail.com',
            'smtp_port' => '587',
            'smtp_username' => 'loeschzug.amern@gmail.com',
            'smtp_password' => 'tnli grex fdpw dmhv',
            'smtp_encryption' => 'tls',
            'smtp_from_email' => 'loeschzug.amern@gmail.com',
            'smtp_from_name' => 'Löschzug Amern'
        ];
        
        foreach ($missing_settings as $key) {
            if (isset($default_settings[$key])) {
                $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
                $stmt->execute([$key, $default_settings[$key], $default_settings[$key]]);
                echo "   ✅ $key gesetzt: " . $default_settings[$key] . "\n";
            }
        }
    }
    echo "\n";

    // 2. Teste E-Mail-Versand
    echo "2. E-Mail-Versand testen:\n";
    require_once 'includes/functions.php';
    
    $test_email = 'dleuchtenberg89@gmail.com';
    $subject = "Admin E-Mail Fix Test - " . date('H:i:s');
    $message = "
    <h2>Admin E-Mail Fix Test</h2>
    <p>Diese E-Mail wurde nach dem Fix der Admin-E-Mail-Funktionalität gesendet.</p>
    <p><strong>Zeitstempel:</strong> " . date('d.m.Y H:i:s') . "</p>
    <p>Falls Sie diese E-Mail erhalten, funktioniert die Admin-E-Mail-Funktionalität korrekt.</p>
    ";
    
    echo "   Sende Test-E-Mail an: $test_email\n";
    
    if (send_email($test_email, $subject, $message)) {
        echo "   ✅ E-Mail erfolgreich gesendet!\n";
    } else {
        echo "   ❌ E-Mail fehlgeschlagen!\n";
    }
    echo "\n";

    // 3. Einstellungen in settings.php Format anzeigen
    echo "3. Aktuelle Einstellungen (settings.php Format):\n";
    $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings ORDER BY setting_key");
    $stmt->execute();
    $all_settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    foreach ($all_settings as $key => $value) {
        if (strpos($key, 'password') !== false) {
            echo "   $key: " . (!empty($value) ? 'GESETZT (' . strlen($value) . ' Zeichen)' : 'LEER') . "\n";
        } else {
            echo "   $key: " . ($value ?: 'LEER') . "\n";
        }
    }
    echo "\n";

    // 4. Empfehlungen
    echo "4. Empfehlungen:\n";
    echo "===============\n";
    echo "✅ Admin E-Mail-Fix abgeschlossen!\n";
    echo "📧 Testen Sie jetzt die Test-E-Mail in den Admin-Einstellungen:\n";
    echo "   1. Gehen Sie zu: http://192.168.10.150/admin/settings.php\n";
    echo "   2. Scrollen Sie zu 'Test E-Mail senden'\n";
    echo "   3. Geben Sie Ihre E-Mail-Adresse ein: $test_email\n";
    echo "   4. Klicken Sie 'Test E-Mail senden'\n";
    echo "   5. Sollte jetzt funktionieren!\n";

} catch (Exception $e) {
    echo "❌ Fehler: " . $e->getMessage() . "\n";
}

echo "\n🎯 Admin E-Mail-Fix abgeschlossen!\n";
?>
