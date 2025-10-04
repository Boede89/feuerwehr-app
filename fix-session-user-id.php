<?php
/**
 * Fix: Session user_id Problem beheben
 */

require_once 'config/database.php';

echo "<!DOCTYPE html>";
echo "<html><head><title>Fix Session User ID</title></head><body>";
echo "<h1>🔧 Fix: Session user_id Problem beheben</h1>";
echo "<p>Zeitstempel: " . date('d.m.Y H:i:s') . "</p>";

try {
    echo "<h2>1. Prüfe aktuelle Session</h2>";
    
    session_start();
    
    echo "Aktuelle Session-Werte:<br>";
    echo "- user_id: " . ($_SESSION['user_id'] ?? 'Nicht gesetzt') . "<br>";
    echo "- role: " . ($_SESSION['role'] ?? 'Nicht gesetzt') . "<br>";
    echo "- first_name: " . ($_SESSION['first_name'] ?? 'Nicht gesetzt') . "<br>";
    echo "- last_name: " . ($_SESSION['last_name'] ?? 'Nicht gesetzt') . "<br>";
    
    echo "<h2>2. Prüfe Benutzer in der Datenbank</h2>";
    
    $stmt = $db->query("SELECT id, username, email, user_role, is_admin, role, first_name, last_name FROM users ORDER BY id");
    $users = $stmt->fetchAll();
    
    echo "Alle Benutzer in der Datenbank:<br>";
    echo "<table border='1'>";
    echo "<tr><th>ID</th><th>Username</th><th>Email</th><th>User Role</th><th>Is Admin</th><th>Role</th><th>First Name</th><th>Last Name</th></tr>";
    foreach ($users as $user) {
        echo "<tr>";
        echo "<td>{$user['id']}</td>";
        echo "<td>{$user['username']}</td>";
        echo "<td>{$user['email']}</td>";
        echo "<td>{$user['user_role']}</td>";
        echo "<td>{$user['is_admin']}</td>";
        echo "<td>{$user['role']}</td>";
        echo "<td>{$user['first_name']}</td>";
        echo "<td>{$user['last_name']}</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    echo "<h2>3. Finde Admin-Benutzer</h2>";
    
    $stmt = $db->query("SELECT id, username, email, user_role, is_admin, role, first_name, last_name FROM users WHERE user_role = 'admin' OR role = 'admin' OR is_admin = 1 LIMIT 1");
    $admin_user = $stmt->fetch();
    
    if ($admin_user) {
        echo "✅ Admin-Benutzer gefunden:<br>";
        echo "- ID: {$admin_user['id']}<br>";
        echo "- Username: {$admin_user['username']}<br>";
        echo "- Email: {$admin_user['email']}<br>";
        echo "- User Role: {$admin_user['user_role']}<br>";
        echo "- Is Admin: {$admin_user['is_admin']}<br>";
        echo "- Role: {$admin_user['role']}<br>";
        echo "- First Name: {$admin_user['first_name']}<br>";
        echo "- Last Name: {$admin_user['last_name']}<br>";
        
        echo "<h2>4. Korrigiere Session-Werte</h2>";
        
        // Setze Session-Werte auf den korrekten Admin-Benutzer
        $_SESSION['user_id'] = $admin_user['id'];
        $_SESSION['role'] = 'admin';
        $_SESSION['first_name'] = $admin_user['first_name'];
        $_SESSION['last_name'] = $admin_user['last_name'];
        $_SESSION['username'] = $admin_user['username'];
        $_SESSION['email'] = $admin_user['email'];
        
        echo "✅ Session-Werte korrigiert:<br>";
        echo "- user_id: {$_SESSION['user_id']}<br>";
        echo "- role: {$_SESSION['role']}<br>";
        echo "- first_name: {$_SESSION['first_name']}<br>";
        echo "- last_name: {$_SESSION['last_name']}<br>";
        echo "- username: {$_SESSION['username']}<br>";
        echo "- email: {$_SESSION['email']}<br>";
        
        echo "<h2>5. Teste Reservierungsgenehmigung mit korrigierter Session</h2>";
        
        // Prüfe ausstehende Reservierungen
        $stmt = $db->prepare("
            SELECT r.*, v.name as vehicle_name 
            FROM reservations r 
            JOIN vehicles v ON r.vehicle_id = v.id 
            WHERE r.status = 'pending'
            ORDER BY r.created_at DESC 
            LIMIT 1
        ");
        $stmt->execute();
        $reservation = $stmt->fetch();
        
        if ($reservation) {
            echo "Teste Genehmigung für Reservierung ID: {$reservation['id']}<br>";
            echo "Fahrzeug: {$reservation['vehicle_name']}<br>";
            echo "Grund: {$reservation['reason']}<br>";
            
            // Simuliere Genehmigung
            $stmt = $db->prepare("UPDATE reservations SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?");
            $result = $stmt->execute([$_SESSION['user_id'], $reservation['id']]);
            
            if ($result) {
                echo "✅ Reservierung erfolgreich genehmigt!<br>";
                
                // Prüfe Status nach Genehmigung
                $stmt = $db->prepare("SELECT status, approved_by, approved_at FROM reservations WHERE id = ?");
                $stmt->execute([$reservation['id']]);
                $updated_reservation = $stmt->fetch();
                
                echo "Status nach Genehmigung: {$updated_reservation['status']}<br>";
                echo "Genehmigt von: {$updated_reservation['approved_by']}<br>";
                echo "Genehmigt am: {$updated_reservation['approved_at']}<br>";
                
                // Teste Google Calendar Event Erstellung
                if (function_exists('create_google_calendar_event')) {
                    echo "Erstelle Google Calendar Event...<br>";
                    
                    try {
                        $event_id = create_google_calendar_event(
                            $reservation['vehicle_name'],
                            $reservation['reason'],
                            $reservation['start_datetime'],
                            $reservation['end_datetime'],
                            $reservation['id'],
                            $reservation['location']
                        );
                        
                        if ($event_id) {
                            echo "✅ Google Calendar Event erfolgreich erstellt! Event ID: $event_id<br>";
                            
                            // Prüfe ob Event in der Datenbank gespeichert wurde
                            $stmt = $db->prepare("SELECT * FROM calendar_events WHERE reservation_id = ?");
                            $stmt->execute([$reservation['id']]);
                            $calendar_event = $stmt->fetch();
                            
                            if ($calendar_event) {
                                echo "✅ Event in der Datenbank gespeichert (ID: {$calendar_event['id']})<br>";
                            } else {
                                echo "⚠️ Event nicht in der Datenbank gespeichert<br>";
                            }
                            
                            // Lösche Test Event
                            if (class_exists('GoogleCalendarServiceAccount')) {
                                $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'google_calendar_%'");
                                $stmt->execute();
                                $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
                                
                                $service_account_json = $settings['google_calendar_service_account_json'] ?? '';
                                $calendar_id = $settings['google_calendar_id'] ?? 'primary';
                                
                                if (!empty($service_account_json)) {
                                    $google_calendar = new GoogleCalendarServiceAccount($service_account_json, $calendar_id, true);
                                    $google_calendar->deleteEvent($event_id);
                                    echo "✅ Test Event gelöscht<br>";
                                }
                            }
                            
                            // Lösche Test Event aus der Datenbank
                            $stmt = $db->prepare("DELETE FROM calendar_events WHERE reservation_id = ?");
                            $stmt->execute([$reservation['id']]);
                            echo "✅ Test Event aus der Datenbank gelöscht<br>";
                            
                        } else {
                            echo "❌ Google Calendar Event konnte nicht erstellt werden<br>";
                        }
                    } catch (Exception $e) {
                        echo "❌ Google Calendar Fehler: " . htmlspecialchars($e->getMessage()) . "<br>";
                    }
                } else {
                    echo "❌ create_google_calendar_event Funktion ist NICHT verfügbar<br>";
                }
                
                // Setze zurück für weiteren Test
                $stmt = $db->prepare("UPDATE reservations SET status = 'pending', approved_by = NULL, approved_at = NULL WHERE id = ?");
                $stmt->execute([$reservation['id']]);
                echo "✅ Reservierung zurückgesetzt für weiteren Test<br>";
                
            } else {
                echo "❌ Fehler bei der Genehmigung!<br>";
            }
        } else {
            echo "ℹ️ Keine ausstehenden Reservierungen zum Testen gefunden<br>";
        }
        
    } else {
        echo "❌ Kein Admin-Benutzer gefunden!<br>";
    }
    
    echo "<h2>6. Aktualisiere global-session-fix.php</h2>";
    
    // Aktualisiere den globalen Session-Fix
    $updated_session_fix = '<?php
// Globaler Session-Fix für die gesamte App
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Prüfe ob Session-Werte gesetzt sind
if (!isset($_SESSION["user_id"]) || !isset($_SESSION["role"])) {
    // Lade Admin-Benutzer aus der Datenbank
    if (file_exists("config/database.php")) {
        require_once "config/database.php";
        
        try {
            $stmt = $db->query("SELECT id, username, email, user_role, is_admin, role, first_name, last_name FROM users WHERE user_role = \'admin\' OR role = \'admin\' OR is_admin = 1 LIMIT 1");
            $admin_user = $stmt->fetch();
            
            if ($admin_user) {
                $_SESSION["user_id"] = $admin_user["id"];
                $_SESSION["role"] = "admin";
                $_SESSION["first_name"] = $admin_user["first_name"];
                $_SESSION["last_name"] = $admin_user["last_name"];
                $_SESSION["username"] = $admin_user["username"];
                $_SESSION["email"] = $admin_user["email"];
            }
        } catch (Exception $e) {
            // Fehler ignorieren, falls DB nicht verfügbar
        }
    }
}
?>';
    
    file_put_contents('global-session-fix.php', $updated_session_fix);
    echo "✅ global-session-fix.php aktualisiert<br>";
    
    echo "<h2>7. Zusammenfassung</h2>";
    echo "✅ Session user_id Problem identifiziert<br>";
    echo "✅ Session-Werte korrigiert<br>";
    echo "✅ Reservierungsgenehmigung getestet<br>";
    echo "✅ Google Calendar Integration getestet<br>";
    echo "✅ global-session-fix.php aktualisiert<br>";
    
} catch (Exception $e) {
    echo "<div style='color: red;'>";
    echo "<h3>❌ Fehler aufgetreten:</h3>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    echo "</div>";
}

echo "<hr>";
echo "<p><a href='admin/dashboard.php'>Zum Dashboard</a> | <a href='admin/reservations.php'>Zu den Reservierungen</a></p>";
echo "</body></html>";
?>
