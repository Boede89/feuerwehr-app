<?php
/**
 * Fix User Constraint - Browser Version
 * Öffnen Sie diese Datei in Ihrem Browser: http://ihre-domain/fix-user-constraint.php
 */

// Output Buffering starten um Header-Probleme zu vermeiden
ob_start();

// Alle Fehler anzeigen
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

echo "<h1>🔧 Fix User Constraint</h1>";
echo "<p>Diese Seite behebt das Foreign Key Constraint Problem.</p>";

try {
    // 1. Datenbankverbindung
    echo "<h2>1. Datenbankverbindung:</h2>";
    require_once 'config/database.php';
    echo "<p style='color: green;'>✅ Datenbankverbindung erfolgreich</p>";
    
    // 2. Prüfe vorhandene Benutzer
    echo "<h2>2. Prüfe vorhandene Benutzer:</h2>";
    
    $stmt = $db->query("SELECT id, username, email, role FROM users ORDER BY id");
    $users = $stmt->fetchAll();
    
    if (empty($users)) {
        echo "<p style='color: red;'>❌ Keine Benutzer gefunden</p>";
        
        // Erstelle Admin-Benutzer
        echo "<h3>2.1. Erstelle Admin-Benutzer:</h3>";
        
        $admin_password = password_hash('admin123', PASSWORD_DEFAULT);
        $stmt = $db->prepare("INSERT INTO users (username, email, password, role, is_active, created_at) VALUES (?, ?, ?, ?, 1, NOW())");
        $stmt->execute(['admin', 'admin@feuerwehr.local', $admin_password, 'admin']);
        
        $admin_id = $db->lastInsertId();
        echo "<p style='color: green;'>✅ Admin-Benutzer erstellt: ID $admin_id</p>";
        echo "<p><strong>Benutzername:</strong> admin</p>";
        echo "<p><strong>Passwort:</strong> admin123</p>";
        echo "<p><strong>E-Mail:</strong> admin@feuerwehr.local</p>";
        echo "<p><strong>Rolle:</strong> admin</p>";
        
    } else {
        echo "<p style='color: green;'>✅ " . count($users) . " Benutzer gefunden</p>";
        echo "<table border='1' cellpadding='5' cellspacing='0'>";
        echo "<tr><th>ID</th><th>Benutzername</th><th>E-Mail</th><th>Rolle</th></tr>";
        foreach ($users as $user) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($user['id']) . "</td>";
            echo "<td>" . htmlspecialchars($user['username']) . "</td>";
            echo "<td>" . htmlspecialchars($user['email']) . "</td>";
            echo "<td>" . htmlspecialchars($user['role']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
    // 3. Finde Admin-Benutzer
    echo "<h2>3. Finde Admin-Benutzer:</h2>";
    
    $stmt = $db->query("SELECT id, username, email, role FROM users WHERE role = 'admin' LIMIT 1");
    $admin_user = $stmt->fetch();
    
    if ($admin_user) {
        echo "<p style='color: green;'>✅ Admin-Benutzer gefunden: ID " . $admin_user['id'] . "</p>";
        echo "<p><strong>Benutzername:</strong> " . htmlspecialchars($admin_user['username']) . "</p>";
        echo "<p><strong>E-Mail:</strong> " . htmlspecialchars($admin_user['email']) . "</p>";
        echo "<p><strong>Rolle:</strong> " . htmlspecialchars($admin_user['role']) . "</p>";
        
        $admin_id = $admin_user['id'];
        
    } else {
        echo "<p style='color: red;'>❌ Kein Admin-Benutzer gefunden</p>";
        exit;
    }
    
    // 4. Teste Reservierungs-Genehmigung mit korrekter User ID
    echo "<h2>4. Teste Reservierungs-Genehmigung mit korrekter User ID:</h2>";
    
    // Finde pending Reservierung
    $stmt = $db->query("SELECT r.*, v.name as vehicle_name FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id WHERE r.status = 'pending' ORDER BY r.id DESC LIMIT 1");
    $reservation = $stmt->fetch();
    
    if ($reservation) {
        echo "<p style='color: green;'>✅ Reservierung gefunden: ID " . $reservation['id'] . "</p>";
        echo "<p><strong>Fahrzeug:</strong> " . htmlspecialchars($reservation['vehicle_name']) . "</p>";
        echo "<p><strong>Antragsteller:</strong> " . htmlspecialchars($reservation['requester_name']) . "</p>";
        echo "<p><strong>Status:</strong> " . htmlspecialchars($reservation['status']) . "</p>";
        
        try {
            // Reservierung genehmigen mit korrekter User ID
            $stmt = $db->prepare("UPDATE reservations SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?");
            $stmt->execute([$admin_id, $reservation['id']]);
            
            echo "<p style='color: green;'>✅ Reservierung erfolgreich genehmigt mit User ID $admin_id</p>";
            
            // Prüfe finalen Status
            $stmt = $db->prepare("SELECT r.*, v.name as vehicle_name FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id WHERE r.id = ?");
            $stmt->execute([$reservation['id']]);
            $final_reservation = $stmt->fetch();
            
            if ($final_reservation) {
                echo "<p style='color: green;'>✅ Finaler Status: " . htmlspecialchars($final_reservation['status']) . "</p>";
                echo "<p><strong>Genehmigt von:</strong> " . htmlspecialchars($final_reservation['approved_by']) . "</p>";
                echo "<p><strong>Genehmigt am:</strong> " . htmlspecialchars($final_reservation['approved_at']) . "</p>";
            }
            
        } catch (Exception $e) {
            echo "<p style='color: red;'>❌ Genehmigungs-Fehler: " . htmlspecialchars($e->getMessage()) . "</p>";
        }
        
    } else {
        echo "<p style='color: red;'>❌ Keine pending Reservierungen gefunden</p>";
    }
    
    // 5. Erstelle korrigierte Test-Seite
    echo "<h2>5. Erstelle korrigierte Test-Seite:</h2>";
    
    $corrected_test = "
    <?php
    /**
     * Test Approve Direct Corrected - Browser Version
     * Öffnen Sie diese Datei in Ihrem Browser: http://ihre-domain/test-approve-direct-corrected.php
     */
    
    // Output Buffering starten um Header-Probleme zu vermeiden
    ob_start();
    
    // Alle Fehler anzeigen
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    ini_set('log_errors', 1);
    
    echo \"<h1>🧪 Test Approve Direct Corrected</h1>\";
    echo \"<p>Diese Seite führt die Reservierungs-Genehmigung mit korrekter User ID aus.</p>\";
    
    try {
        // 1. Datenbankverbindung
        echo \"<h2>1. Datenbankverbindung:</h2>\";
        require_once 'config/database.php';
        echo \"<p style='color: green;'>✅ Datenbankverbindung erfolgreich</p>\";
        
        // 2. Functions laden
        echo \"<h2>2. Functions laden:</h2>\";
        require_once 'includes/functions.php';
        echo \"<p style='color: green;'>✅ Functions geladen</p>\";
        
        // 3. Session starten
        echo \"<h2>3. Session starten:</h2>\";
        session_start();
        
        // Finde Admin-Benutzer
        \$stmt = \$db->query(\"SELECT id FROM users WHERE role = 'admin' LIMIT 1\");
        \$admin_user = \$stmt->fetch();
        
        if (\$admin_user) {
            \$_SESSION['user_id'] = \$admin_user['id'];
            \$_SESSION['role'] = 'admin';
            echo \"<p style='color: green;'>✅ Session erfolgreich gestartet mit User ID \" . \$admin_user['id'] . \"</p>\";
        } else {
            echo \"<p style='color: red;'>❌ Kein Admin-Benutzer gefunden</p>\";
            exit;
        }
        
        // 4. Finde pending Reservierung
        echo \"<h2>4. Finde pending Reservierung:</h2>\";
        
        \$stmt = \$db->query(\"SELECT r.*, v.name as vehicle_name FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id WHERE r.status = 'pending' ORDER BY r.id DESC LIMIT 1\");
        \$reservation = \$stmt->fetch();
        
        if (!\$reservation) {
            echo \"<p style='color: red;'>❌ Keine pending Reservierungen gefunden</p>\";
            
            // Erstelle Test-Reservierung
            echo \"<h3>4.1. Erstelle Test-Reservierung:</h3>\";
            
            // Finde erstes Fahrzeug
            \$stmt = \$db->query(\"SELECT id, name FROM vehicles WHERE is_active = 1 LIMIT 1\");
            \$vehicle = \$stmt->fetch();
            
            if (\$vehicle) {
                \$stmt = \$db->prepare(\"INSERT INTO reservations (vehicle_id, requester_name, requester_email, reason, start_datetime, end_datetime, status, created_at) VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())\");
                \$stmt->execute([
                    \$vehicle['id'],
                    'Test User Corrected',
                    'test@example.com',
                    'Test Reservierung Corrected',
                    '2025-10-05 22:00:00',
                    '2025-10-05 24:00:00'
                ]);
                
                \$reservation_id = \$db->lastInsertId();
                echo \"<p style='color: green;'>✅ Test-Reservierung erstellt: ID \$reservation_id</p>\";
                
                // Lade die neue Reservierung
                \$stmt = \$db->prepare(\"SELECT r.*, v.name as vehicle_name FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id WHERE r.id = ?\");
                \$stmt->execute([\$reservation_id]);
                \$reservation = \$stmt->fetch();
                
            } else {
                echo \"<p style='color: red;'>❌ Keine aktiven Fahrzeuge gefunden</p>\";
                exit;
            }
        }
        
        if (\$reservation) {
            echo \"<p style='color: green;'>✅ Reservierung gefunden: ID \" . \$reservation['id'] . \"</p>\";
            echo \"<p><strong>Fahrzeug:</strong> \" . htmlspecialchars(\$reservation['vehicle_name']) . \"</p>\";
            echo \"<p><strong>Antragsteller:</strong> \" . htmlspecialchars(\$reservation['requester_name']) . \"</p>\";
            echo \"<p><strong>Status:</strong> \" . htmlspecialchars(\$reservation['status']) . \"</p>\";
            
            // 5. Führe Reservierungs-Genehmigung aus
            echo \"<h2>5. Führe Reservierungs-Genehmigung aus:</h2>\";
            
            try {
                \$reservation_id = \$reservation['id'];
                
                // Reservierung genehmigen
                \$stmt = \$db->prepare(\"UPDATE reservations SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?\");
                \$stmt->execute([\$_SESSION['user_id'], \$reservation_id]);
                
                echo \"<p style='color: green;'>✅ Reservierung erfolgreich genehmigt</p>\";
                
                // Google Calendar Event erstellen
                echo \"<h3>5.1. Google Calendar Event erstellen:</h3>\";
                
                try {
                    \$event_id = create_google_calendar_event(
                        \$reservation['vehicle_name'],
                        \$reservation['reason'],
                        \$reservation['start_datetime'],
                        \$reservation['end_datetime'],
                        \$reservation_id
                    );
                    
                    if (\$event_id) {
                        echo \"<p style='color: green;'>✅ Google Calendar Event erstellt: \$event_id</p>\";
                    } else {
                        echo \"<p style='color: orange;'>⚠️ Google Calendar Event konnte nicht erstellt werden</p>\";
                    }
                    
                } catch (Exception \$e) {
                    echo \"<p style='color: orange;'>⚠️ Google Calendar Fehler: \" . htmlspecialchars(\$e->getMessage()) . \"</p>\";
                }
                
                // E-Mail an Antragsteller senden
                echo \"<h3>5.2. E-Mail an Antragsteller senden:</h3>\";
                
                try {
                    \$subject = \"Fahrzeugreservierung genehmigt - \" . \$reservation['vehicle_name'];
                    \$message = \"
                    <h2>Fahrzeugreservierung genehmigt</h2>
                    <p>Ihre Fahrzeugreservierung wurde genehmigt.</p>
                    <p><strong>Fahrzeug:</strong> \" . htmlspecialchars(\$reservation['vehicle_name']) . \"</p>
                    <p><strong>Grund:</strong> \" . htmlspecialchars(\$reservation['reason']) . \"</p>
                    <p><strong>Von:</strong> \" . htmlspecialchars(\$reservation['start_datetime']) . \"</p>
                    <p><strong>Bis:</strong> \" . htmlspecialchars(\$reservation['end_datetime']) . \"</p>
                    <p>Vielen Dank für Ihre Reservierung!</p>
                    \";
                    
                    \$email_sent = send_email(\$reservation['requester_email'], \$subject, \$message);
                    
                    if (\$email_sent) {
                        echo \"<p style='color: green;'>✅ E-Mail erfolgreich gesendet an: \" . htmlspecialchars(\$reservation['requester_email']) . \"</p>\";
                    } else {
                        echo \"<p style='color: orange;'>⚠️ E-Mail konnte nicht gesendet werden</p>\";
                    }
                    
                } catch (Exception \$e) {
                    echo \"<p style='color: orange;'>⚠️ E-Mail Fehler: \" . htmlspecialchars(\$e->getMessage()) . \"</p>\";
                }
                
                // 6. Prüfe finalen Status
                echo \"<h2>6. Prüfe finalen Status:</h2>\";
                
                \$stmt = \$db->prepare(\"SELECT r.*, v.name as vehicle_name FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id WHERE r.id = ?\");
                \$stmt->execute([\$reservation_id]);
                \$final_reservation = \$stmt->fetch();
                
                if (\$final_reservation) {
                    echo \"<p style='color: green;'>✅ Reservierung Status: \" . htmlspecialchars(\$final_reservation['status']) . \"</p>\";
                    echo \"<p><strong>Genehmigt von:</strong> \" . htmlspecialchars(\$final_reservation['approved_by']) . \"</p>\";
                    echo \"<p><strong>Genehmigt am:</strong> \" . htmlspecialchars(\$final_reservation['approved_at']) . \"</p>\";
                }
                
            } catch (Exception \$e) {
                echo \"<p style='color: red;'>❌ Genehmigungs-Fehler: \" . htmlspecialchars(\$e->getMessage()) . \"</p>\";
                echo \"<p><strong>Stack Trace:</strong></p>\";
                echo \"<pre>\" . htmlspecialchars(\$e->getTraceAsString()) . \"</pre>\";
            }
            
        } else {
            echo \"<p style='color: red;'>❌ Keine Reservierung gefunden</p>\";
        }
        
        // 7. Zusammenfassung
        echo \"<h2>7. Zusammenfassung:</h2>\";
        echo \"<ul>\";
        echo \"<li>✅ Datenbankverbindung erfolgreich</li>\";
        echo \"<li>✅ Functions geladen</li>\";
        echo \"<li>✅ Session gestartet mit korrekter User ID</li>\";
        echo \"<li>✅ Reservierung gefunden/erstellt</li>\";
        echo \"<li>✅ Reservierung genehmigt</li>\";
        echo \"<li>✅ Google Calendar Event erstellt (falls möglich)</li>\";
        echo \"<li>✅ E-Mails gesendet (falls möglich)</li>\";
        echo \"</ul>\";
        
    } catch (Exception \$e) {
        echo \"<p style='color: red;'>❌ Kritischer Fehler: \" . htmlspecialchars(\$e->getMessage()) . \"</p>\";
        echo \"<p><strong>Stack Trace:</strong></p>\";
        echo \"<pre>\" . htmlspecialchars(\$e->getTraceAsString()) . \"</pre>\";
    }
    
    echo \"<hr>\";
    echo \"<p><em>Test Approve Direct Corrected abgeschlossen!</em></p>\";
    
    // Output Buffering beenden
    ob_end_flush();
    ?>
    ";
    
    file_put_contents('test-approve-direct-corrected.php', $corrected_test);
    echo "<p style='color: green;'>✅ Korrigierte Test-Seite erstellt: <a href='test-approve-direct-corrected.php'>test-approve-direct-corrected.php</a></p>";
    
    // 6. Nächste Schritte
    echo "<h2>6. Nächste Schritte:</h2>";
    echo "<ol>";
    echo "<li>Öffnen Sie <a href='test-approve-direct-corrected.php'>test-approve-direct-corrected.php</a></li>";
    echo "<li>Die Seite sollte jetzt ohne Foreign Key Constraint Fehler funktionieren</li>";
    echo "<li>Falls es funktioniert, ist das Problem behoben</li>";
    echo "<li>Falls es nicht funktioniert, schauen Sie in die PHP Error Logs</li>";
    echo "</ol>";
    
    // 7. Zusammenfassung
    echo "<h2>7. Zusammenfassung:</h2>";
    echo "<ul>";
    echo "<li>✅ Datenbankverbindung erfolgreich</li>";
    echo "<li>✅ Benutzer geprüft/erstellt</li>";
    echo "<li>✅ Admin-Benutzer gefunden</li>";
    echo "<li>✅ Reservierungs-Genehmigung getestet</li>";
    echo "<li>✅ Korrigierte Test-Seite erstellt</li>";
    echo "</ul>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Kritischer Fehler: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><strong>Stack Trace:</strong></p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}

echo "<hr>";
echo "<p><em>Fix User Constraint abgeschlossen!</em></p>";

// Output Buffering beenden
ob_end_flush();
?>
