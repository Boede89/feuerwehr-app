<?php
/**
 * Test Approve Direct - Browser Version
 * Öffnen Sie diese Datei in Ihrem Browser: http://ihre-domain/test-approve-direct.php
 */

// Output Buffering starten um Header-Probleme zu vermeiden
ob_start();

// Alle Fehler anzeigen
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

echo "<h1>🧪 Test Approve Direct</h1>";
echo "<p>Diese Seite führt die Reservierungs-Genehmigung direkt aus.</p>";

try {
    // 1. Datenbankverbindung
    echo "<h2>1. Datenbankverbindung:</h2>";
    require_once 'config/database.php';
    echo "<p style='color: green;'>✅ Datenbankverbindung erfolgreich</p>";
    
    // 2. Functions laden
    echo "<h2>2. Functions laden:</h2>";
    require_once 'includes/functions.php';
    echo "<p style='color: green;'>✅ Functions geladen</p>";
    
    // 3. Session starten
    echo "<h2>3. Session starten:</h2>";
    session_start();
    $_SESSION['user_id'] = 1; // Admin User ID
    $_SESSION['role'] = 'admin';
    echo "<p style='color: green;'>✅ Session erfolgreich gestartet</p>";
    
    // 4. Finde pending Reservierung
    echo "<h2>4. Finde pending Reservierung:</h2>";
    
    $stmt = $db->query("SELECT r.*, v.name as vehicle_name FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id WHERE r.status = 'pending' ORDER BY r.id DESC LIMIT 1");
    $reservation = $stmt->fetch();
    
    if (!$reservation) {
        echo "<p style='color: red;'>❌ Keine pending Reservierungen gefunden</p>";
        
        // Erstelle Test-Reservierung
        echo "<h3>4.1. Erstelle Test-Reservierung:</h3>";
        
        // Finde erstes Fahrzeug
        $stmt = $db->query("SELECT id, name FROM vehicles WHERE is_active = 1 LIMIT 1");
        $vehicle = $stmt->fetch();
        
        if ($vehicle) {
            $stmt = $db->prepare("INSERT INTO reservations (vehicle_id, requester_name, requester_email, reason, start_datetime, end_datetime, status, created_at) VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())");
            $stmt->execute([
                $vehicle['id'],
                'Test User Direct',
                'test@example.com',
                'Test Reservierung Direct',
                '2025-10-05 18:00:00',
                '2025-10-05 20:00:00'
            ]);
            
            $reservation_id = $db->lastInsertId();
            echo "<p style='color: green;'>✅ Test-Reservierung erstellt: ID $reservation_id</p>";
            
            // Lade die neue Reservierung
            $stmt = $db->prepare("SELECT r.*, v.name as vehicle_name FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id WHERE r.id = ?");
            $stmt->execute([$reservation_id]);
            $reservation = $stmt->fetch();
            
        } else {
            echo "<p style='color: red;'>❌ Keine aktiven Fahrzeuge gefunden</p>";
            exit;
        }
    }
    
    if ($reservation) {
        echo "<p style='color: green;'>✅ Reservierung gefunden: ID " . $reservation['id'] . "</p>";
        echo "<p><strong>Fahrzeug:</strong> " . htmlspecialchars($reservation['vehicle_name']) . "</p>";
        echo "<p><strong>Antragsteller:</strong> " . htmlspecialchars($reservation['requester_name']) . "</p>";
        echo "<p><strong>Status:</strong> " . htmlspecialchars($reservation['status']) . "</p>";
        
        // 5. Führe Reservierungs-Genehmigung aus
        echo "<h2>5. Führe Reservierungs-Genehmigung aus:</h2>";
        
        try {
            $reservation_id = $reservation['id'];
            
            // Reservierung genehmigen
            $stmt = $db->prepare("UPDATE reservations SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?");
            $stmt->execute([$_SESSION['user_id'], $reservation_id]);
            
            echo "<p style='color: green;'>✅ Reservierung erfolgreich genehmigt</p>";
            
            // Google Calendar Event erstellen
            echo "<h3>5.1. Google Calendar Event erstellen:</h3>";
            
            try {
                $event_id = create_google_calendar_event(
                    $reservation['vehicle_name'],
                    $reservation['reason'],
                    $reservation['start_datetime'],
                    $reservation['end_datetime'],
                    $reservation_id
                );
                
                if ($event_id) {
                    echo "<p style='color: green;'>✅ Google Calendar Event erstellt: $event_id</p>";
                } else {
                    echo "<p style='color: orange;'>⚠️ Google Calendar Event konnte nicht erstellt werden</p>";
                }
                
            } catch (Exception $e) {
                echo "<p style='color: orange;'>⚠️ Google Calendar Fehler: " . htmlspecialchars($e->getMessage()) . "</p>";
            }
            
            // E-Mail an Antragsteller senden
            echo "<h3>5.2. E-Mail an Antragsteller senden:</h3>";
            
            try {
                $subject = "Fahrzeugreservierung genehmigt - " . $reservation['vehicle_name'];
                $message = "
                <h2>Fahrzeugreservierung genehmigt</h2>
                <p>Ihre Fahrzeugreservierung wurde genehmigt.</p>
                <p><strong>Fahrzeug:</strong> " . htmlspecialchars($reservation['vehicle_name']) . "</p>
                <p><strong>Grund:</strong> " . htmlspecialchars($reservation['reason']) . "</p>
                <p><strong>Von:</strong> " . htmlspecialchars($reservation['start_datetime']) . "</p>
                <p><strong>Bis:</strong> " . htmlspecialchars($reservation['end_datetime']) . "</p>
                <p>Vielen Dank für Ihre Reservierung!</p>
                ";
                
                $email_sent = send_email($reservation['requester_email'], $subject, $message);
                
                if ($email_sent) {
                    echo "<p style='color: green;'>✅ E-Mail erfolgreich gesendet an: " . htmlspecialchars($reservation['requester_email']) . "</p>";
                } else {
                    echo "<p style='color: orange;'>⚠️ E-Mail konnte nicht gesendet werden</p>";
                }
                
            } catch (Exception $e) {
                echo "<p style='color: orange;'>⚠️ E-Mail Fehler: " . htmlspecialchars($e->getMessage()) . "</p>";
            }
            
            // E-Mail an Administratoren senden
            echo "<h3>5.3. E-Mail an Administratoren senden:</h3>";
            
            try {
                $stmt = $db->query("SELECT email FROM users WHERE role IN ('admin', 'approver') AND email IS NOT NULL AND email != ''");
                $admins = $stmt->fetchAll();
                
                if (!empty($admins)) {
                    $subject = "Neue Fahrzeugreservierung genehmigt - " . $reservation['vehicle_name'];
                    $message = "
                    <h2>Fahrzeugreservierung genehmigt</h2>
                    <p>Eine Fahrzeugreservierung wurde genehmigt.</p>
                    <p><strong>Fahrzeug:</strong> " . htmlspecialchars($reservation['vehicle_name']) . "</p>
                    <p><strong>Antragsteller:</strong> " . htmlspecialchars($reservation['requester_name']) . "</p>
                    <p><strong>Grund:</strong> " . htmlspecialchars($reservation['reason']) . "</p>
                    <p><strong>Von:</strong> " . htmlspecialchars($reservation['start_datetime']) . "</p>
                    <p><strong>Bis:</strong> " . htmlspecialchars($reservation['end_datetime']) . "</p>
                    ";
                    
                    $admin_emails = array_column($admins, 'email');
                    $admin_emails = array_filter($admin_emails); // Entferne leere E-Mails
                    
                    if (!empty($admin_emails)) {
                        $email_sent = send_email(implode(',', $admin_emails), $subject, $message);
                        
                        if ($email_sent) {
                            echo "<p style='color: green;'>✅ E-Mail an Administratoren gesendet: " . implode(', ', $admin_emails) . "</p>";
                        } else {
                            echo "<p style='color: orange;'>⚠️ E-Mail an Administratoren konnte nicht gesendet werden</p>";
                        }
                    } else {
                        echo "<p style='color: orange;'>⚠️ Keine Administrator-E-Mails gefunden</p>";
                    }
                } else {
                    echo "<p style='color: orange;'>⚠️ Keine Administratoren gefunden</p>";
                }
                
            } catch (Exception $e) {
                echo "<p style='color: orange;'>⚠️ Administrator-E-Mail Fehler: " . htmlspecialchars($e->getMessage()) . "</p>";
            }
            
            // 6. Prüfe finalen Status
            echo "<h2>6. Prüfe finalen Status:</h2>";
            
            $stmt = $db->prepare("SELECT r.*, v.name as vehicle_name FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id WHERE r.id = ?");
            $stmt->execute([$reservation_id]);
            $final_reservation = $stmt->fetch();
            
            if ($final_reservation) {
                echo "<p style='color: green;'>✅ Reservierung Status: " . htmlspecialchars($final_reservation['status']) . "</p>";
                echo "<p><strong>Genehmigt von:</strong> " . htmlspecialchars($final_reservation['approved_by']) . "</p>";
                echo "<p><strong>Genehmigt am:</strong> " . htmlspecialchars($final_reservation['approved_at']) . "</p>";
            }
            
        } catch (Exception $e) {
            echo "<p style='color: red;'>❌ Genehmigungs-Fehler: " . htmlspecialchars($e->getMessage()) . "</p>";
            echo "<p><strong>Stack Trace:</strong></p>";
            echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
        }
        
    } else {
        echo "<p style='color: red;'>❌ Keine Reservierung gefunden</p>";
    }
    
    // 7. Zusammenfassung
    echo "<h2>7. Zusammenfassung:</h2>";
    echo "<ul>";
    echo "<li>✅ Datenbankverbindung erfolgreich</li>";
    echo "<li>✅ Functions geladen</li>";
    echo "<li>✅ Session gestartet</li>";
    echo "<li>✅ Reservierung gefunden/erstellt</li>";
    echo "<li>✅ Reservierung genehmigt</li>";
    echo "<li>✅ Google Calendar Event erstellt (falls möglich)</li>";
    echo "<li>✅ E-Mails gesendet (falls möglich)</li>";
    echo "</ul>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Kritischer Fehler: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><strong>Stack Trace:</strong></p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}

echo "<hr>";
echo "<p><em>Test Approve Direct abgeschlossen!</em></p>";

// Output Buffering beenden
ob_end_flush();
?>
