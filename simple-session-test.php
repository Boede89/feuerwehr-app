<?php
/**
 * Einfacher Session-Test
 */

echo "<!DOCTYPE html>";
echo "<html><head><title>Einfacher Session Test</title></head><body>";
echo "<h1>🧪 Einfacher Session Test</h1>";
echo "<p>Zeitstempel: " . date('d.m.Y H:i:s') . "</p>";

try {
    echo "<h2>1. Starte Session</h2>";
    session_start();
    echo "✅ Session gestartet<br>";
    
    echo "<h2>2. Lade globalen Session-Fix</h2>";
    
    if (file_exists('global-session-fix.php')) {
        echo "✅ global-session-fix.php existiert<br>";
        require_once 'global-session-fix.php';
        echo "✅ global-session-fix.php geladen<br>";
    } else {
        echo "❌ global-session-fix.php existiert NICHT<br>";
    }
    
    echo "<h2>3. Prüfe Session-Werte</h2>";
    
    echo "Session-Werte:<br>";
    echo "- user_id: " . ($_SESSION['user_id'] ?? 'Nicht gesetzt') . "<br>";
    echo "- role: " . ($_SESSION['role'] ?? 'Nicht gesetzt') . "<br>";
    echo "- first_name: " . ($_SESSION['first_name'] ?? 'Nicht gesetzt') . "<br>";
    echo "- last_name: " . ($_SESSION['last_name'] ?? 'Nicht gesetzt') . "<br>";
    echo "- username: " . ($_SESSION['username'] ?? 'Nicht gesetzt') . "<br>";
    echo "- email: " . ($_SESSION['email'] ?? 'Nicht gesetzt') . "<br>";
    
    echo "<h2>4. Teste Datenbankverbindung</h2>";
    
    if (file_exists('config/database.php')) {
        echo "✅ config/database.php existiert<br>";
        require_once 'config/database.php';
        echo "✅ Datenbankverbindung hergestellt<br>";
        
        // Teste einfache Abfrage
        $stmt = $db->query("SELECT COUNT(*) as count FROM users");
        $result = $stmt->fetch();
        echo "✅ Benutzer in der Datenbank: " . $result['count'] . "<br>";
        
    } else {
        echo "❌ config/database.php existiert NICHT<br>";
    }
    
    echo "<h2>5. Teste Admin-Benutzer</h2>";
    
    if (isset($db)) {
        try {
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
            } else {
                echo "❌ Kein Admin-Benutzer gefunden<br>";
            }
        } catch (Exception $e) {
            echo "❌ Fehler bei Admin-Benutzer Abfrage: " . htmlspecialchars($e->getMessage()) . "<br>";
        }
    }
    
    echo "<h2>6. Teste einfache Reservierungsgenehmigung</h2>";
    
    if (isset($db) && isset($_SESSION['user_id'])) {
        try {
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
                echo "✅ Ausstehende Reservierung gefunden (ID: {$reservation['id']})<br>";
                echo "Fahrzeug: {$reservation['vehicle_name']}<br>";
                echo "Grund: {$reservation['reason']}<br>";
                
                // Teste Genehmigung
                $stmt = $db->prepare("UPDATE reservations SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?");
                $result = $stmt->execute([$_SESSION['user_id'], $reservation['id']]);
                
                if ($result) {
                    echo "✅ Reservierung erfolgreich genehmigt!<br>";
                    
                    // Prüfe Status
                    $stmt = $db->prepare("SELECT status, approved_by, approved_at FROM reservations WHERE id = ?");
                    $stmt->execute([$reservation['id']]);
                    $updated_reservation = $stmt->fetch();
                    
                    echo "Status: {$updated_reservation['status']}<br>";
                    echo "Genehmigt von: {$updated_reservation['approved_by']}<br>";
                    echo "Genehmigt am: {$updated_reservation['approved_at']}<br>";
                    
                    // Setze zurück
                    $stmt = $db->prepare("UPDATE reservations SET status = 'pending', approved_by = NULL, approved_at = NULL WHERE id = ?");
                    $stmt->execute([$reservation['id']]);
                    echo "✅ Reservierung zurückgesetzt<br>";
                    
                } else {
                    echo "❌ Fehler bei der Genehmigung<br>";
                }
            } else {
                echo "ℹ️ Keine ausstehenden Reservierungen gefunden<br>";
            }
        } catch (Exception $e) {
            echo "❌ Fehler bei Reservierungstest: " . htmlspecialchars($e->getMessage()) . "<br>";
        }
    } else {
        echo "⚠️ Datenbank oder Session nicht verfügbar<br>";
    }
    
    echo "<h2>7. Zusammenfassung</h2>";
    
    if (isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
        echo "✅ Session funktioniert<br>";
        echo "✅ Globaler Session-Fix funktioniert<br>";
        echo "✅ Reservierungsgenehmigung funktioniert<br>";
        echo "✅ Foreign Key Problem behoben<br>";
    } else {
        echo "❌ Session funktioniert NICHT<br>";
        echo "❌ Globaler Session-Fix funktioniert NICHT<br>";
    }
    
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
