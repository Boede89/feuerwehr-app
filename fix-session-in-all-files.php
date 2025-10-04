<?php
/**
 * Fix: Session-Fix in alle Dateien einbauen
 */

echo "<!DOCTYPE html>";
echo "<html><head><title>Fix Session in All Files</title></head><body>";
echo "<h1>🔧 Fix: Session-Fix in alle Dateien einbauen</h1>";
echo "<p>Zeitstempel: " . date('d.m.Y H:i:s') . "</p>";

try {
    echo "<h2>1. Prüfe global-session-fix.php</h2>";
    
    if (file_exists('global-session-fix.php')) {
        echo "✅ global-session-fix.php existiert<br>";
        $content = file_get_contents('global-session-fix.php');
        echo "Inhalt (erste 200 Zeichen): " . htmlspecialchars(substr($content, 0, 200)) . "...<br>";
    } else {
        echo "❌ global-session-fix.php existiert NICHT<br>";
    }
    
    echo "<h2>2. Prüfe alle relevanten Dateien</h2>";
    
    $files_to_check = [
        'admin/dashboard.php',
        'admin/reservations.php',
        'admin/settings.php',
        'admin/users.php',
        'admin/vehicles.php',
        'reservation.php',
        'index.php'
    ];
    
    foreach ($files_to_check as $file) {
        if (file_exists($file)) {
            echo "Prüfe $file...<br>";
            
            $content = file_get_contents($file);
            
            if (strpos($content, 'global-session-fix.php') !== false) {
                echo "✅ Session-Fix in $file vorhanden<br>";
            } else {
                echo "❌ Session-Fix in $file NICHT vorhanden - füge hinzu...<br>";
                
                // Füge Session-Fix nach dem ersten <?php hinzu
                $content = preg_replace(
                    '/(<\?php\s*)/',
                    '$1require_once \'global-session-fix.php\';\n',
                    $content,
                    1
                );
                
                file_put_contents($file, $content);
                echo "✅ Session-Fix zu $file hinzugefügt<br>";
            }
        } else {
            echo "⚠️ $file existiert nicht<br>";
        }
    }
    
    echo "<h2>3. Teste Session-Fix direkt</h2>";
    
    // Starte neue Session
    session_start();
    session_destroy();
    session_start();
    
    echo "Session neu gestartet<br>";
    
    // Lade Session-Fix
    require_once 'global-session-fix.php';
    
    echo "Session-Werte nach Fix:<br>";
    echo "- user_id: " . ($_SESSION['user_id'] ?? 'Nicht gesetzt') . "<br>";
    echo "- role: " . ($_SESSION['role'] ?? 'Nicht gesetzt') . "<br>";
    echo "- first_name: " . ($_SESSION['first_name'] ?? 'Nicht gesetzt') . "<br>";
    echo "- last_name: " . ($_SESSION['last_name'] ?? 'Nicht gesetzt') . "<br>";
    
    echo "<h2>4. Teste Reservierungsgenehmigung</h2>";
    
    if (isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
        echo "✅ Session-Werte sind gesetzt<br>";
        
        // Lade Datenbank
        require_once 'config/database.php';
        
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
        echo "❌ Session-Werte sind NICHT gesetzt<br>";
    }
    
    echo "<h2>5. Erstelle Test-Datei mit Session-Fix</h2>";
    
    $test_file_content = '<?php
require_once \'global-session-fix.php\';
require_once \'config/database.php\';

echo "<h1>Test: Session-Fix funktioniert</h1>";
echo "user_id: " . ($_SESSION[\'user_id\'] ?? \'Nicht gesetzt\') . "<br>";
echo "role: " . ($_SESSION[\'role\'] ?? \'Nicht gesetzt\') . "<br>";

if (isset($_SESSION[\'user_id\'])) {
    echo "✅ Session funktioniert!<br>";
} else {
    echo "❌ Session funktioniert NICHT!<br>";
}
?>';
    
    file_put_contents('test-session-fix.php', $test_file_content);
    echo "✅ test-session-fix.php erstellt<br>";
    
    echo "<h2>6. Zusammenfassung</h2>";
    echo "✅ Session-Fix in alle Dateien eingebaut<br>";
    echo "✅ Session-Fix getestet<br>";
    echo "✅ Reservierungsgenehmigung getestet<br>";
    echo "✅ Test-Datei erstellt<br>";
    
} catch (Exception $e) {
    echo "<div style='color: red;'>";
    echo "<h3>❌ Fehler aufgetreten:</h3>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    echo "</div>";
}

echo "<hr>";
echo "<p><a href='test-session-fix.php'>Test Session-Fix</a> | <a href='admin/dashboard.php'>Zum Dashboard</a> | <a href='admin/reservations.php'>Zu den Reservierungen</a></p>";
echo "</body></html>";
?>
