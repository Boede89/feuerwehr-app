<?php
/**
 * Fix: Foreign Key Constraint Problem beheben
 */

require_once 'config/database.php';

echo "<!DOCTYPE html>";
echo "<html><head><title>Fix Foreign Key Constraint</title></head><body>";
echo "<h1>🔧 Fix: Foreign Key Constraint Problem</h1>";
echo "<p>Zeitstempel: " . date('d.m.Y H:i:s') . "</p>";

try {
    echo "<h2>1. Problem identifiziert</h2>";
    echo "Das Problem ist, dass der approved_by Wert (User ID) nicht in der users Tabelle existiert.<br>";
    echo "Foreign Key Constraint: reservations.approved_by → users.id<br>";
    
    echo "<h2>2. Prüfe aktuelle Session</h2>";
    session_start();
    
    if (isset($_SESSION['user_id'])) {
        echo "Session user_id: " . $_SESSION['user_id'] . "<br>";
    } else {
        echo "❌ Keine Session user_id gefunden<br>";
    }
    
    if (isset($_SESSION['role'])) {
        echo "Session role: " . $_SESSION['role'] . "<br>";
    } else {
        echo "❌ Keine Session role gefunden<br>";
    }
    
    echo "<h2>3. Prüfe users Tabelle</h2>";
    
    $stmt = $db->query("SELECT id, username, email, role FROM users ORDER BY id");
    $users = $stmt->fetchAll();
    
    echo "Gefundene Benutzer:<br>";
    echo "<table border='1'>";
    echo "<tr><th>ID</th><th>Username</th><th>Email</th><th>Role</th></tr>";
    foreach ($users as $user) {
        echo "<tr>";
        echo "<td>{$user['id']}</td>";
        echo "<td>{$user['username']}</td>";
        echo "<td>{$user['email']}</td>";
        echo "<td>{$user['role']}</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    echo "<h2>4. Prüfe ausstehende Reservierungen</h2>";
    
    $stmt = $db->prepare("
        SELECT r.*, v.name as vehicle_name 
        FROM reservations r 
        JOIN vehicles v ON r.vehicle_id = v.id 
        WHERE r.status = 'pending'
        ORDER BY r.created_at DESC 
        LIMIT 5
    ");
    $stmt->execute();
    $reservations = $stmt->fetchAll();
    
    if (empty($reservations)) {
        echo "ℹ️ Keine ausstehenden Reservierungen gefunden<br>";
    } else {
        echo "Ausstehende Reservierungen:<br>";
        echo "<table border='1'>";
        echo "<tr><th>ID</th><th>Fahrzeug</th><th>Grund</th><th>Status</th><th>Erstellt</th></tr>";
        foreach ($reservations as $reservation) {
            echo "<tr>";
            echo "<td>{$reservation['id']}</td>";
            echo "<td>{$reservation['vehicle_name']}</td>";
            echo "<td>{$reservation['reason']}</td>";
            echo "<td>{$reservation['status']}</td>";
            echo "<td>{$reservation['created_at']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
    echo "<h2>5. Erstelle Admin-Benutzer falls nötig</h2>";
    
    // Prüfe ob Admin-Benutzer existiert
    $stmt = $db->prepare("SELECT id FROM users WHERE role = 'admin' LIMIT 1");
    $stmt->execute();
    $admin_user = $stmt->fetch();
    
    if (!$admin_user) {
        echo "❌ Kein Admin-Benutzer gefunden. Erstelle einen...<br>";
        
        $stmt = $db->prepare("
            INSERT INTO users (username, email, password, first_name, last_name, role, is_active) 
            VALUES (?, ?, ?, ?, ?, 'admin', 1)
        ");
        $stmt->execute([
            'admin',
            'admin@feuerwehr.local',
            password_hash('admin123', PASSWORD_DEFAULT),
            'Admin',
            'User'
        ]);
        
        $admin_id = $db->lastInsertId();
        echo "✅ Admin-Benutzer erstellt (ID: $admin_id)<br>";
    } else {
        $admin_id = $admin_user['id'];
        echo "✅ Admin-Benutzer gefunden (ID: $admin_id)<br>";
    }
    
    echo "<h2>6. Teste Reservierungsgenehmigung</h2>";
    
    if (!empty($reservations)) {
        $test_reservation = $reservations[0];
        echo "Teste Genehmigung für Reservierung ID: {$test_reservation['id']}<br>";
        
        // Simuliere Genehmigung
        $stmt = $db->prepare("UPDATE reservations SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?");
        $result = $stmt->execute([$admin_id, $test_reservation['id']]);
        
        if ($result) {
            echo "✅ Reservierung erfolgreich genehmigt!<br>";
            
            // Prüfe Status nach Genehmigung
            $stmt = $db->prepare("SELECT status, approved_by, approved_at FROM reservations WHERE id = ?");
            $stmt->execute([$test_reservation['id']]);
            $updated_reservation = $stmt->fetch();
            
            echo "Status nach Genehmigung: {$updated_reservation['status']}<br>";
            echo "Genehmigt von: {$updated_reservation['approved_by']}<br>";
            echo "Genehmigt am: {$updated_reservation['approved_at']}<br>";
            
            // Setze zurück für weiteren Test
            $stmt = $db->prepare("UPDATE reservations SET status = 'pending', approved_by = NULL, approved_at = NULL WHERE id = ?");
            $stmt->execute([$test_reservation['id']]);
            echo "✅ Reservierung zurückgesetzt für weiteren Test<br>";
            
        } else {
            echo "❌ Fehler bei der Genehmigung!<br>";
        }
    }
    
    echo "<h2>7. Fix Session-Problem</h2>";
    
    // Setze Session-Werte korrekt
    $_SESSION['user_id'] = $admin_id;
    $_SESSION['role'] = 'admin';
    $_SESSION['first_name'] = 'Admin';
    $_SESSION['last_name'] = 'User';
    
    echo "✅ Session-Werte gesetzt:<br>";
    echo "- user_id: {$_SESSION['user_id']}<br>";
    echo "- role: {$_SESSION['role']}<br>";
    echo "- first_name: {$_SESSION['first_name']}<br>";
    echo "- last_name: {$_SESSION['last_name']}<br>";
    
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
