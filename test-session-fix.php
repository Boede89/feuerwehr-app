<?php
require_once 'global-session-fix.php';
require_once 'config/database.php';

echo "<!DOCTYPE html>";
echo "<html><head><title>Test Session Fix</title></head><body>";
echo "<h1>🧪 Test: Session-Fix funktioniert</h1>";
echo "<p>Zeitstempel: " . date('d.m.Y H:i:s') . "</p>";

echo "<h2>Session-Werte:</h2>";
echo "user_id: " . ($_SESSION['user_id'] ?? 'Nicht gesetzt') . "<br>";
echo "role: " . ($_SESSION['role'] ?? 'Nicht gesetzt') . "<br>";
echo "first_name: " . ($_SESSION['first_name'] ?? 'Nicht gesetzt') . "<br>";
echo "last_name: " . ($_SESSION['last_name'] ?? 'Nicht gesetzt') . "<br>";
echo "username: " . ($_SESSION['username'] ?? 'Nicht gesetzt') . "<br>";
echo "email: " . ($_SESSION['email'] ?? 'Nicht gesetzt') . "<br>";

if (isset($_SESSION['user_id'])) {
    echo "<h2>✅ Session funktioniert!</h2>";
    echo "<p>Die Session-Werte sind korrekt gesetzt.</p>";
} else {
    echo "<h2>❌ Session funktioniert NICHT!</h2>";
    echo "<p>Die Session-Werte sind nicht gesetzt.</p>";
}

echo "<h2>Teste Reservierungsgenehmigung:</h2>";

if (isset($_SESSION['user_id']) && isset($db)) {
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
    echo "⚠️ Session oder Datenbank nicht verfügbar<br>";
}

echo "<hr>";
echo "<p><a href='admin/dashboard.php'>Zum Dashboard</a> | <a href='admin/reservations.php'>Zu den Reservierungen</a></p>";
echo "</body></html>";
?>
