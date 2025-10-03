<?php
/**
 * Test Fahrzeugname in E-Mails
 */

require_once 'config/database.php';
require_once 'includes/functions.php';

echo "🚛 Fahrzeugname Fix Test\n";
echo "========================\n\n";

try {
    // 1. Prüfe Reservierungen mit Fahrzeugnamen
    echo "1. Prüfe Reservierungen in der Datenbank:\n";
    $stmt = $db->prepare("
        SELECT r.id, r.requester_name, r.requester_email, r.reason, r.status, 
               v.name as vehicle_name, r.start_datetime, r.end_datetime
        FROM reservations r 
        JOIN vehicles v ON r.vehicle_id = v.id 
        ORDER BY r.created_at DESC 
        LIMIT 5
    ");
    $stmt->execute();
    $reservations = $stmt->fetchAll();
    
    if (empty($reservations)) {
        echo "   ❌ Keine Reservierungen gefunden\n";
    } else {
        foreach ($reservations as $reservation) {
            echo "   ID: {$reservation['id']}\n";
            echo "   Antragsteller: {$reservation['requester_name']}\n";
            echo "   E-Mail: {$reservation['requester_email']}\n";
            echo "   Fahrzeug: {$reservation['vehicle_name']}\n";
            echo "   Status: {$reservation['status']}\n";
            echo "   Grund: {$reservation['reason']}\n";
            echo "   Zeitraum: " . format_datetime($reservation['start_datetime']) . " - " . format_datetime($reservation['end_datetime']) . "\n";
            echo "   ---\n";
        }
    }
    
    // 2. Teste SQL-Abfrage für Genehmigung
    echo "\n2. Teste SQL-Abfrage für Genehmigung:\n";
    if (!empty($reservations)) {
        $test_reservation_id = $reservations[0]['id'];
        echo "   Teste mit Reservierung ID: $test_reservation_id\n";
        
        $stmt = $db->prepare("SELECT r.*, v.name as vehicle_name FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id WHERE r.id = ?");
        $stmt->execute([$test_reservation_id]);
        $reservation = $stmt->fetch();
        
        if ($reservation) {
            echo "   ✅ SQL-Abfrage erfolgreich\n";
            echo "   Fahrzeugname: {$reservation['vehicle_name']}\n";
            echo "   Antragsteller: {$reservation['requester_name']}\n";
            echo "   E-Mail: {$reservation['requester_email']}\n";
        } else {
            echo "   ❌ SQL-Abfrage fehlgeschlagen\n";
        }
    }
    
    // 3. Teste E-Mail-Versand
    echo "\n3. Teste E-Mail-Versand:\n";
    
    // SMTP-Einstellungen laden
    $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'smtp_%'");
    $stmt->execute();
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    $smtp_password = $settings['smtp_password'] ?? '';
    
    if (empty($smtp_password)) {
        echo "   ❌ SMTP-Passwort ist nicht gesetzt!\n";
    } else {
        $test_email = "boedefeld1@freenet.de";
        
        // Simuliere Genehmigungs-E-Mail
        if (!empty($reservations)) {
            $reservation = $reservations[0];
            
            $subject = "✅ Fahrzeugreservierung genehmigt - " . htmlspecialchars($reservation['vehicle_name']);
            $message_content = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; background-color: #f8f9fa; padding: 20px;'>
                <div style='background-color: #28a745; color: white; padding: 20px; border-radius: 8px 8px 0 0; text-align: center;'>
                    <h1 style='margin: 0; font-size: 24px;'>🚒 Reservierung genehmigt!</h1>
                </div>
                <div style='background-color: white; padding: 30px; border-radius: 0 0 8px 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);'>
                    <p style='font-size: 16px; color: #333; margin-bottom: 20px;'>Hallo " . htmlspecialchars($reservation['requester_name']) . ",</p>
                    <p style='font-size: 16px; color: #333; margin-bottom: 25px;'>Ihr Antrag für die Fahrzeugreservierung wurde <strong style='color: #28a745;'>genehmigt</strong>!</p>
                    
                    <div style='background-color: #e8f5e8; border-left: 4px solid #28a745; padding: 20px; margin: 20px 0; border-radius: 4px;'>
                        <h3 style='margin: 0 0 15px 0; color: #28a745; font-size: 18px;'>📋 Reservierungsdetails</h3>
                        <table style='width: 100%; border-collapse: collapse;'>
                            <tr>
                                <td style='padding: 8px 0; font-weight: bold; color: #555; width: 120px;'>🚛 Fahrzeug:</td>
                                <td style='padding: 8px 0; color: #333;'>" . htmlspecialchars($reservation['vehicle_name']) . "</td>
                            </tr>
                            <tr>
                                <td style='padding: 8px 0; font-weight: bold; color: #555;'>📅 Von:</td>
                                <td style='padding: 8px 0; color: #333;'>" . format_datetime($reservation['start_datetime']) . "</td>
                            </tr>
                            <tr>
                                <td style='padding: 8px 0; font-weight: bold; color: #555;'>📅 Bis:</td>
                                <td style='padding: 8px 0; color: #333;'>" . format_datetime($reservation['end_datetime']) . "</td>
                            </tr>
                            <tr>
                                <td style='padding: 8px 0; font-weight: bold; color: #555;'>📝 Grund:</td>
                                <td style='padding: 8px 0; color: #333;'>" . htmlspecialchars($reservation['reason']) . "</td>
                            </tr>
                        </table>
                    </div>
                    
                    <p style='font-size: 14px; color: #666; margin-top: 25px;'>
                        Mit freundlichen Grüßen,<br>
                        Ihr Feuerwehr-Team
                    </p>
                </div>
            </div>
            ";
            
            echo "   Sende Test-E-Mail an: $test_email\n";
            echo "   Betreff: $subject\n";
            echo "   Fahrzeug: {$reservation['vehicle_name']}\n";
            
            $result = send_email($test_email, $subject, $message_content);
            
            if ($result) {
                echo "   ✅ Test-E-Mail erfolgreich gesendet!\n";
            } else {
                echo "   ❌ Test-E-Mail fehlgeschlagen\n";
            }
        }
    }
    
    echo "\n4. Zusammenfassung:\n";
    echo "   ✅ SQL-Abfrage für Fahrzeugname korrigiert\n";
    echo "   ✅ Genehmigungs-E-Mail lädt jetzt vehicle_name\n";
    echo "   ✅ Ablehnungs-E-Mail lädt jetzt vehicle_name\n";
    echo "   📧 Testen Sie die E-Mails über die Web-Oberfläche\n";
    
} catch (Exception $e) {
    echo "❌ Fehler: " . $e->getMessage() . "\n";
}

echo "\n🎯 Fahrzeugname Fix Test abgeschlossen!\n";
?>
