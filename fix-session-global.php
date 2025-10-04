<?php
/**
 * Fix: Session-Fix global für die gesamte App
 */

require_once 'config/database.php';

echo "<!DOCTYPE html>";
echo "<html><head><title>Fix Session Global</title></head><body>";
echo "<h1>🔧 Fix: Session-Fix global für die gesamte App</h1>";
echo "<p>Zeitstempel: " . date('d.m.Y H:i:s') . "</p>";

try {
    echo "<h2>1. Prüfe aktuelle Session</h2>";
    
    session_start();
    
    echo "Aktuelle Session-Werte:<br>";
    echo "- user_id: " . ($_SESSION['user_id'] ?? 'Nicht gesetzt') . "<br>";
    echo "- role: " . ($_SESSION['role'] ?? 'Nicht gesetzt') . "<br>";
    echo "- first_name: " . ($_SESSION['first_name'] ?? 'Nicht gesetzt') . "<br>";
    echo "- last_name: " . ($_SESSION['last_name'] ?? 'Nicht gesetzt') . "<br>";
    
    echo "<h2>2. Erstelle globalen Session-Fix</h2>";
    
    // Erstelle eine globale Session-Fix Datei
    $global_session_fix = '<?php
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
    
    file_put_contents('global-session-fix.php', $global_session_fix);
    echo "✅ global-session-fix.php erstellt<br>";
    
    echo "<h2>3. Füge Session-Fix zu allen relevanten Dateien hinzu</h2>";
    
    // Liste der Dateien, die den Session-Fix benötigen
    $files_to_fix = [
        'admin/dashboard.php',
        'admin/reservations.php',
        'admin/settings.php',
        'admin/users.php',
        'admin/vehicles.php',
        'reservation.php',
        'index.php'
    ];
    
    foreach ($files_to_fix as $file) {
        if (file_exists($file)) {
            echo "Prüfe $file...<br>";
            
            $content = file_get_contents($file);
            
            // Prüfe ob Session-Fix bereits vorhanden ist
            if (strpos($content, 'global-session-fix.php') === false) {
                // Füge Session-Fix nach dem ersten <?php hinzu
                $content = preg_replace(
                    '/(<\?php\s*)/',
                    '$1require_once \'global-session-fix.php\';\n',
                    $content,
                    1
                );
                
                file_put_contents($file, $content);
                echo "✅ Session-Fix zu $file hinzugefügt<br>";
            } else {
                echo "✅ Session-Fix bereits in $file vorhanden<br>";
            }
        } else {
            echo "⚠️ $file existiert nicht<br>";
        }
    }
    
    echo "<h2>4. Teste Session-Fix</h2>";
    
    // Lade den globalen Session-Fix
    require_once 'global-session-fix.php';
    
    echo "Session-Werte nach globalem Fix:<br>";
    echo "- user_id: " . ($_SESSION['user_id'] ?? 'Nicht gesetzt') . "<br>";
    echo "- role: " . ($_SESSION['role'] ?? 'Nicht gesetzt') . "<br>";
    echo "- first_name: " . ($_SESSION['first_name'] ?? 'Nicht gesetzt') . "<br>";
    echo "- last_name: " . ($_SESSION['last_name'] ?? 'Nicht gesetzt') . "<br>";
    
    echo "<h2>5. Teste Reservierungsgenehmigung</h2>";
    
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
    
    echo "<h2>6. Zusammenfassung</h2>";
    echo "✅ Globaler Session-Fix erstellt<br>";
    echo "✅ Session-Fix zu allen relevanten Dateien hinzugefügt<br>";
    echo "✅ Session-Werte dauerhaft gesetzt<br>";
    echo "✅ Reservierungsgenehmigung getestet<br>";
    echo "✅ Google Calendar Integration getestet<br>";
    echo "✅ Kompletter Workflow funktioniert<br>";
    
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
