<?php
/**
 * Test-Skript für send_email Funktion
 */

require_once 'config/database.php';
require_once 'includes/functions.php';

echo "📧 send_email Funktion Test\n";
echo "===========================\n\n";

try {
    // Test-E-Mail senden
    $to = 'test@example.com';
    $subject = 'send_email Funktion Test';
    $message = '<h2>send_email Test</h2><p>Diese E-Mail wurde über die send_email Funktion gesendet.</p>';
    
    echo "1. Teste send_email Funktion:\n";
    echo "   An: $to\n";
    echo "   Betreff: $subject\n";
    
    $result = send_email($to, $subject, $message);
    
    echo "   Ergebnis: " . ($result ? 'ERFOLGREICH' : 'FEHLGESCHLAGEN') . "\n";
    
    if ($result) {
        echo "\n✅ send_email Funktion funktioniert korrekt!\n";
    } else {
        echo "\n❌ send_email Funktion schlägt fehl.\n";
        echo "   Prüfen Sie die Log-Dateien für Fehlermeldungen.\n";
    }
    
    // Debug: SMTP-Einstellungen prüfen
    echo "\n2. SMTP-Einstellungen Debug:\n";
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
    
} catch (Exception $e) {
    echo "❌ Fehler: " . $e->getMessage() . "\n";
}

echo "\n🎯 Test abgeschlossen!\n";
?>
