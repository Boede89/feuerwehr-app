<?php
/**
 * Fix: Users Table Structure Problem beheben
 */

require_once 'config/database.php';

echo "<!DOCTYPE html>";
echo "<html><head><title>Fix Users Table Structure</title></head><body>";
echo "<h1>🔧 Fix: Users Table Structure Problem</h1>";
echo "<p>Zeitstempel: " . date('d.m.Y H:i:s') . "</p>";

try {
    echo "<h2>1. Prüfe aktuelle users Tabelle Struktur</h2>";
    
    $stmt = $db->query("DESCRIBE users");
    $columns = $stmt->fetchAll();
    
    echo "Aktuelle Spalten in users Tabelle:<br>";
    echo "<table border='1'>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
    foreach ($columns as $column) {
        echo "<tr>";
        echo "<td>{$column['Field']}</td>";
        echo "<td>{$column['Type']}</td>";
        echo "<td>{$column['Null']}</td>";
        echo "<td>{$column['Key']}</td>";
        echo "<td>{$column['Default']}</td>";
        echo "<td>{$column['Extra']}</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    echo "<h2>2. Prüfe ob role Spalte existiert</h2>";
    
    $has_role = false;
    $has_is_active = false;
    $has_first_name = false;
    $has_last_name = false;
    
    foreach ($columns as $column) {
        if ($column['Field'] === 'role') $has_role = true;
        if ($column['Field'] === 'is_active') $has_is_active = true;
        if ($column['Field'] === 'first_name') $has_first_name = true;
        if ($column['Field'] === 'last_name') $has_last_name = true;
    }
    
    echo "Spalten-Check:<br>";
    echo "- role: " . ($has_role ? "✅" : "❌") . "<br>";
    echo "- is_active: " . ($has_is_active ? "✅" : "❌") . "<br>";
    echo "- first_name: " . ($has_first_name ? "✅" : "❌") . "<br>";
    echo "- last_name: " . ($has_last_name ? "✅" : "❌") . "<br>";
    
    echo "<h2>3. Füge fehlende Spalten hinzu</h2>";
    
    if (!$has_role) {
        echo "Füge 'role' Spalte hinzu...<br>";
        $db->exec("ALTER TABLE users ADD COLUMN role ENUM('admin', 'user') DEFAULT 'user' AFTER password");
        echo "✅ 'role' Spalte hinzugefügt<br>";
    }
    
    if (!$has_is_active) {
        echo "Füge 'is_active' Spalte hinzu...<br>";
        $db->exec("ALTER TABLE users ADD COLUMN is_active TINYINT(1) DEFAULT 1 AFTER role");
        echo "✅ 'is_active' Spalte hinzugefügt<br>";
    }
    
    if (!$has_first_name) {
        echo "Füge 'first_name' Spalte hinzu...<br>";
        $db->exec("ALTER TABLE users ADD COLUMN first_name VARCHAR(100) AFTER is_active");
        echo "✅ 'first_name' Spalte hinzugefügt<br>";
    }
    
    if (!$has_last_name) {
        echo "Füge 'last_name' Spalte hinzu...<br>";
        $db->exec("ALTER TABLE users ADD COLUMN last_name VARCHAR(100) AFTER first_name");
        echo "✅ 'last_name' Spalte hinzugefügt<br>";
    }
    
    echo "<h2>4. Prüfe aktuelle Benutzer</h2>";
    
    $stmt = $db->query("SELECT id, username, email, role, is_active, first_name, last_name FROM users ORDER BY id");
    $users = $stmt->fetchAll();
    
    if (empty($users)) {
        echo "❌ Keine Benutzer gefunden<br>";
    } else {
        echo "Gefundene Benutzer:<br>";
        echo "<table border='1'>";
        echo "<tr><th>ID</th><th>Username</th><th>Email</th><th>Role</th><th>Active</th><th>First Name</th><th>Last Name</th></tr>";
        foreach ($users as $user) {
            echo "<tr>";
            echo "<td>{$user['id']}</td>";
            echo "<td>{$user['username']}</td>";
            echo "<td>{$user['email']}</td>";
            echo "<td>{$user['role']}</td>";
            echo "<td>{$user['is_active']}</td>";
            echo "<td>{$user['first_name']}</td>";
            echo "<td>{$user['last_name']}</td>";
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
            INSERT INTO users (username, email, password, role, is_active, first_name, last_name) 
            VALUES (?, ?, ?, 'admin', 1, ?, ?)
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
    
    echo "<h2>6. Setze Session-Werte</h2>";
    
    session_start();
    $_SESSION['user_id'] = $admin_id;
    $_SESSION['role'] = 'admin';
    $_SESSION['first_name'] = 'Admin';
    $_SESSION['last_name'] = 'User';
    
    echo "✅ Session-Werte gesetzt:<br>";
    echo "- user_id: {$_SESSION['user_id']}<br>";
    echo "- role: {$_SESSION['role']}<br>";
    echo "- first_name: {$_SESSION['first_name']}<br>";
    echo "- last_name: {$_SESSION['last_name']}<br>";
    
    echo "<h2>7. Teste Reservierungsgenehmigung</h2>";
    
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
        
        // Simuliere Genehmigung
        $stmt = $db->prepare("UPDATE reservations SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?");
        $result = $stmt->execute([$admin_id, $reservation['id']]);
        
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
    
    echo "<h2>8. Zusammenfassung</h2>";
    echo "✅ Users Tabelle Struktur korrigiert<br>";
    echo "✅ Admin-Benutzer erstellt/gefunden<br>";
    echo "✅ Session-Werte gesetzt<br>";
    echo "✅ Foreign Key Constraint Problem behoben<br>";
    
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
