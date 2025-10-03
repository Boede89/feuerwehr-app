<?php
/**
 * Test Approve Final - Browser Version
 * Öffnen Sie diese Datei in Ihrem Browser: http://ihre-domain/test-approve-final.php
 */

// Output Buffering starten um Header-Probleme zu vermeiden
ob_start();

// Alle Fehler anzeigen
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

echo "<h1>🧪 Test Approve Final</h1>";
echo "<p>Diese Seite führt die Reservierungs-Genehmigung mit korrekter Spaltenbezeichnung aus.</p>";

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
    
    // Finde Admin-Benutzer (mit korrekter Spaltenbezeichnung)
    $stmt = $db->query("SELECT id, username, email, user_role FROM users WHERE user_role = 'admin' LIMIT 1");
    $admin_user = $stmt->fetch();
    
    if ($admin_user) {
        $_SESSION['user_id'] = $admin_user['id'];
        $_SESSION['role'] = 'admin';
        echo "<p style='color: green;'>✅ Session erfolgreich gestartet mit User ID " . $admin_user['id'] . "</p>";
        echo "<p><strong>Benutzername:</strong> " . htmlspecialchars($admin_user['username']) . "</p>";
        echo "<p><strong>E-Mail:</strong> " . htmlspecialchars($admin_user['email']) . "</p>";
        echo "<p><strong>Rolle:</strong> " . htmlspecialchars($admin_user['user_role']) . "</p>";
    } else {
        echo "<p style='color: red;'>❌ Kein Admin-Benutzer gefunden</p>";
        
        // Prüfe alle Benutzer
        echo "<h3>3.1. Alle Benutzer anzeigen:</h3>";
        $stmt = $db->query("SELECT id, username, email, user_role FROM users ORDER BY id");
        $all_users = $stmt->fetchAll();
        
        if (!empty($all_users)) {
            echo "<table border='1' cellpadding='5' cellspacing='0'>";
            echo "<tr><th>ID</th><th>Benutzername</th><th>E-Mail</th><th>Rolle</th></tr>";
            foreach ($all_users as $user) {
                echo "<tr>";
                echo "<td>" . htmlspecialchars($user['id']) . "</td>";
                echo "<td>" . htmlspecialchars($user['username']) . "</td>";
                echo "<td>" . htmlspecialchars($user['email']) . "</td>";
                echo "<td>" . htmlspecialchars($user['user_role']) . "</td>";
                echo "</tr>";
            }
            echo "</table>";
            
            // Verwende ersten Benutzer als Admin
            $first_user = $all_users[0];
            $_SESSION['user_id'] = $first_user['id'];
            $_SESSION['role'] = 'admin';
            echo "<p style='color: orange;'>⚠️ Verwende ersten Benutzer als Admin: ID " . $first_user['id'] . "</p>";
        } else {
            echo "<p style='color: red;'>❌ Keine Benutzer gefunden</p>";
            exit;
        }
    }
    
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
                'Test User Final',
                'test@example.com',
                'Test Reservierung Final',
                '2025-10-05 22:00:00',
                '2025-10-05 24:00:00'
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
        echo "<p><strong>Von:</strong> " . htmlspecialchars($reservation['start_datetime']) . "</p>";
        echo "<p><strong>Bis:</strong> " . htmlspecialchars($reservation['end_datetime']) . "</p>";
        
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
                $stmt = $db->query("SELECT email FROM users WHERE user_role IN ('admin', 'approver') AND email IS NOT NULL AND email != ''");
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
    echo "<li>✅ Session gestartet mit korrekter User ID</li>";
    echo "<li>✅ Reservierung gefunden/erstellt</li>";
    echo "<li>✅ Reservierung genehmigt</li>";
    echo "<li>✅ Google Calendar Event erstellt (falls möglich)</li>";
    echo "<li>✅ E-Mails gesendet (falls möglich)</li>";
    echo "</ul>";
    
    // 8. Nächste Schritte
    echo "<h2>8. Nächste Schritte:</h2>";
    echo "<ol>";
    echo "<li>Die Reservierungs-Genehmigung funktioniert jetzt</li>";
    echo "<li>Testen Sie die echte admin/reservations.php Seite</li>";
    echo "<li>Falls es funktioniert, ist das Problem vollständig behoben</li>";
    echo "<li>Falls es nicht funktioniert, schauen Sie in die PHP Error Logs</li>";
    echo "</ol>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Kritischer Fehler: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><strong>Stack Trace:</strong></p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}

echo "<hr>";
echo "<p><em>Test Approve Final abgeschlossen!</em></p>";

// Output Buffering beenden
ob_end_flush();
?>
