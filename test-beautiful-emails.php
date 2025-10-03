<?php
/**
 * Test der verschönerten E-Mail-Templates
 */

require_once 'config/database.php';
require_once 'includes/functions.php';

echo "📧 Schöne E-Mail-Templates Test\n";
echo "================================\n\n";

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
        echo "   Führen Sie zuerst 'set-smtp-password.php' aus.\n";
        exit;
    }
    
    $test_email = "boedefeld1@freenet.de";
    
    // Test 1: Genehmigungs-E-Mail (verschönert)
    echo "2. Test 1: Genehmigungs-E-Mail (verschönert)\n";
    $subject = "✅ Fahrzeugreservierung genehmigt - LF 20/1";
    $message_content = "
    <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; background-color: #f8f9fa; padding: 20px;'>
        <div style='background-color: #28a745; color: white; padding: 20px; border-radius: 8px 8px 0 0; text-align: center;'>
            <h1 style='margin: 0; font-size: 24px;'>🚒 Reservierung genehmigt!</h1>
        </div>
        <div style='background-color: white; padding: 30px; border-radius: 0 0 8px 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);'>
            <p style='font-size: 16px; color: #333; margin-bottom: 20px;'>Hallo Max Mustermann,</p>
            <p style='font-size: 16px; color: #333; margin-bottom: 25px;'>Ihr Antrag für die Fahrzeugreservierung wurde <strong style='color: #28a745;'>genehmigt</strong>!</p>
            
            <div style='background-color: #e8f5e8; border-left: 4px solid #28a745; padding: 20px; margin: 20px 0; border-radius: 4px;'>
                <h3 style='margin: 0 0 15px 0; color: #28a745; font-size: 18px;'>📋 Reservierungsdetails</h3>
                <table style='width: 100%; border-collapse: collapse;'>
                    <tr>
                        <td style='padding: 8px 0; font-weight: bold; color: #555; width: 120px;'>🚛 Fahrzeug:</td>
                        <td style='padding: 8px 0; color: #333;'>LF 20/1</td>
                    </tr>
                    <tr>
                        <td style='padding: 8px 0; font-weight: bold; color: #555;'>📅 Von:</td>
                        <td style='padding: 8px 0; color: #333;'>15.10.2025 14:00</td>
                    </tr>
                    <tr>
                        <td style='padding: 8px 0; font-weight: bold; color: #555;'>📅 Bis:</td>
                        <td style='padding: 8px 0; color: #333;'>15.10.2025 16:00</td>
                    </tr>
                    <tr>
                        <td style='padding: 8px 0; font-weight: bold; color: #555;'>📝 Grund:</td>
                        <td style='padding: 8px 0; color: #333;'>Übung</td>
                    </tr>
                </table>
            </div>
            
            <div style='background-color: #d1ecf1; border: 1px solid #bee5eb; padding: 15px; border-radius: 4px; margin: 20px 0;'>
                <p style='margin: 0; color: #0c5460; font-size: 14px;'>
                    <strong>ℹ️ Hinweis:</strong> Diese Reservierung wurde automatisch in den Google Kalender eingetragen.
                </p>
            </div>
            
            <p style='font-size: 14px; color: #666; margin-top: 25px;'>
                Mit freundlichen Grüßen,<br>
                Ihr Feuerwehr-Team
            </p>
        </div>
    </div>
    ";
    
    echo "   An: $test_email\n";
    echo "   Betreff: $subject\n";
    echo "   Sende E-Mail...\n";
    
    $result1 = send_email($test_email, $subject, $message_content);
    
    if ($result1) {
        echo "   ✅ Genehmigungs-E-Mail erfolgreich gesendet!\n";
    } else {
        echo "   ❌ Genehmigungs-E-Mail fehlgeschlagen\n";
    }
    
    // Warten 2 Sekunden
    sleep(2);
    
    // Test 2: Ablehnungs-E-Mail (verschönert)
    echo "\n3. Test 2: Ablehnungs-E-Mail (verschönert)\n";
    $subject = "❌ Fahrzeugreservierung abgelehnt - RW 2";
    $message_content = "
    <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; background-color: #f8f9fa; padding: 20px;'>
        <div style='background-color: #dc3545; color: white; padding: 20px; border-radius: 8px 8px 0 0; text-align: center;'>
            <h1 style='margin: 0; font-size: 24px;'>🚒 Reservierung abgelehnt</h1>
        </div>
        <div style='background-color: white; padding: 30px; border-radius: 0 0 8px 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);'>
            <p style='font-size: 16px; color: #333; margin-bottom: 20px;'>Hallo Anna Schmidt,</p>
            <p style='font-size: 16px; color: #333; margin-bottom: 25px;'>Ihr Antrag für die Fahrzeugreservierung wurde leider <strong style='color: #dc3545;'>abgelehnt</strong>.</p>
            
            <div style='background-color: #f8d7da; border-left: 4px solid #dc3545; padding: 20px; margin: 20px 0; border-radius: 4px;'>
                <h3 style='margin: 0 0 15px 0; color: #dc3545; font-size: 18px;'>📋 Reservierungsdetails</h3>
                <table style='width: 100%; border-collapse: collapse;'>
                    <tr>
                        <td style='padding: 8px 0; font-weight: bold; color: #555; width: 120px;'>🚛 Fahrzeug:</td>
                        <td style='padding: 8px 0; color: #333;'>RW 2</td>
                    </tr>
                    <tr>
                        <td style='padding: 8px 0; font-weight: bold; color: #555;'>📅 Von:</td>
                        <td style='padding: 8px 0; color: #333;'>16.10.2025 10:00</td>
                    </tr>
                    <tr>
                        <td style='padding: 8px 0; font-weight: bold; color: #555;'>📅 Bis:</td>
                        <td style='padding: 8px 0; color: #333;'>16.10.2025 12:00</td>
                    </tr>
                    <tr>
                        <td style='padding: 8px 0; font-weight: bold; color: #555;'>📝 Grund:</td>
                        <td style='padding: 8px 0; color: #333;'>Einsatz</td>
                    </tr>
                </table>
            </div>
            
            <div style='background-color: #f8d7da; border: 1px solid #f5c6cb; padding: 15px; border-radius: 4px; margin: 20px 0;'>
                <h4 style='margin: 0 0 10px 0; color: #721c24; font-size: 16px;'>❌ Ablehnungsgrund:</h4>
                <p style='margin: 0; color: #721c24; font-size: 14px;'>Fahrzeug bereits vergeben</p>
            </div>
            
            <div style='background-color: #d1ecf1; border: 1px solid #bee5eb; padding: 15px; border-radius: 4px; margin: 20px 0;'>
                <p style='margin: 0; color: #0c5460; font-size: 14px;'>
                    <strong>💡 Tipp:</strong> Sie können gerne einen neuen Antrag mit einem anderen Zeitraum stellen.
                </p>
            </div>
            
            <p style='font-size: 14px; color: #666; margin-top: 25px;'>
                Mit freundlichen Grüßen,<br>
                Ihr Feuerwehr-Team
            </p>
        </div>
    </div>
    ";
    
    echo "   An: $test_email\n";
    echo "   Betreff: $subject\n";
    echo "   Sende E-Mail...\n";
    
    $result2 = send_email($test_email, $subject, $message_content);
    
    if ($result2) {
        echo "   ✅ Ablehnungs-E-Mail erfolgreich gesendet!\n";
    } else {
        echo "   ❌ Ablehnungs-E-Mail fehlgeschlagen\n";
    }
    
    // Warten 2 Sekunden
    sleep(2);
    
    // Test 3: Neue Antrag-E-Mail (verschönert)
    echo "\n4. Test 3: Neue Antrag-E-Mail (verschönert)\n";
    $subject = "🔔 Neue Fahrzeugreservierung - ELW 1";
    $message_content = "
    <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; background-color: #f8f9fa; padding: 20px;'>
        <div style='background-color: #007bff; color: white; padding: 20px; border-radius: 8px 8px 0 0; text-align: center;'>
            <h1 style='margin: 0; font-size: 24px;'>🔔 Neue Reservierung eingegangen</h1>
        </div>
        <div style='background-color: white; padding: 30px; border-radius: 0 0 8px 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);'>
            <p style='font-size: 16px; color: #333; margin-bottom: 25px;'>Ein neuer Antrag für eine Fahrzeugreservierung ist eingegangen und wartet auf Ihre Bearbeitung.</p>
            
            <div style='background-color: #e3f2fd; border-left: 4px solid #007bff; padding: 20px; margin: 20px 0; border-radius: 4px;'>
                <h3 style='margin: 0 0 15px 0; color: #007bff; font-size: 18px;'>📋 Antragsdetails</h3>
                <table style='width: 100%; border-collapse: collapse;'>
                    <tr>
                        <td style='padding: 8px 0; font-weight: bold; color: #555; width: 120px;'>🚛 Fahrzeug:</td>
                        <td style='padding: 8px 0; color: #333;'>ELW 1</td>
                    </tr>
                    <tr>
                        <td style='padding: 8px 0; font-weight: bold; color: #555;'>👤 Antragsteller:</td>
                        <td style='padding: 8px 0; color: #333;'>Peter Müller</td>
                    </tr>
                    <tr>
                        <td style='padding: 8px 0; font-weight: bold; color: #555;'>📧 E-Mail:</td>
                        <td style='padding: 8px 0; color: #333;'>peter.mueller@example.com</td>
                    </tr>
                    <tr>
                        <td style='padding: 8px 0; font-weight: bold; color: #555;'>📝 Grund:</td>
                        <td style='padding: 8px 0; color: #333;'>Schulung</td>
                    </tr>
                    <tr>
                        <td style='padding: 8px 0; font-weight: bold; color: #555;'>📅 Zeiträume:</td>
                        <td style='padding: 8px 0; color: #333;'>2 Zeitraum(e) beantragt</td>
                    </tr>
                </table>
            </div>
            
            <div style='background-color: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; border-radius: 4px; margin: 20px 0;'>
                <p style='margin: 0; color: #856404; font-size: 14px;'>
                    <strong>⏰ Wichtig:</strong> Bitte bearbeiten Sie diesen Antrag zeitnah, damit der Antragsteller eine schnelle Rückmeldung erhält.
                </p>
            </div>
            
            <div style='text-align: center; margin: 25px 0;'>
                <a href='http://192.168.10.150/admin/reservations.php' 
                   style='background-color: #007bff; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: bold; display: inline-block;'>
                    🔗 Antrag bearbeiten
                </a>
            </div>
            
            <p style='font-size: 14px; color: #666; margin-top: 25px;'>
                Mit freundlichen Grüßen,<br>
                Ihr Feuerwehr-System
            </p>
        </div>
    </div>
    ";
    
    echo "   An: $test_email\n";
    echo "   Betreff: $subject\n";
    echo "   Sende E-Mail...\n";
    
    $result3 = send_email($test_email, $subject, $message_content);
    
    if ($result3) {
        echo "   ✅ Neue Antrag-E-Mail erfolgreich gesendet!\n";
    } else {
        echo "   ❌ Neue Antrag-E-Mail fehlgeschlagen\n";
    }
    
    echo "\n5. Zusammenfassung:\n";
    echo "   Genehmigungs-E-Mail: " . ($result1 ? "✅ Erfolgreich" : "❌ Fehlgeschlagen") . "\n";
    echo "   Ablehnungs-E-Mail: " . ($result2 ? "✅ Erfolgreich" : "❌ Fehlgeschlagen") . "\n";
    echo "   Neue Antrag-E-Mail: " . ($result3 ? "✅ Erfolgreich" : "❌ Fehlgeschlagen") . "\n";
    
    if ($result1 && $result2 && $result3) {
        echo "\n🎉 Alle verschönerten E-Mail-Templates erfolgreich getestet!\n";
        echo "📧 Prüfen Sie Ihr Postfach - die E-Mails sollten jetzt viel schöner aussehen!\n";
    } else {
        echo "\n❌ Einige E-Mail-Tests fehlgeschlagen!\n";
        echo "🔍 Prüfen Sie die Log-Dateien für weitere Details\n";
    }
    
} catch (Exception $e) {
    echo "❌ Fehler: " . $e->getMessage() . "\n";
}

echo "\n🎯 Schöne E-Mail-Templates Test abgeschlossen!\n";
?>
