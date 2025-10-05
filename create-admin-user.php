<?php
/**
 * Erstelle Admin-Benutzer falls keiner existiert
 */

require_once 'config/database.php';
require_once 'includes/functions.php';

echo "<h1>Admin-Benutzer erstellen</h1>";

try {
    // Prüfe ob bereits ein Admin existiert
    $stmt = $db->query("SELECT COUNT(*) as count FROM users WHERE user_role = 'admin' OR role = 'admin' OR is_admin = 1");
    $admin_count = $stmt->fetch()['count'];
    
    if ($admin_count > 0) {
        echo "<p style='color: green;'>✅ Es existieren bereits " . $admin_count . " Admin-Benutzer.</p>";
        
        // Zeige vorhandene Admins
        $stmt = $db->query("SELECT id, username, email, first_name, last_name, user_role, is_admin FROM users WHERE user_role = 'admin' OR role = 'admin' OR is_admin = 1");
        $admins = $stmt->fetchAll();
        
        echo "<h3>Vorhandene Admin-Benutzer:</h3>";
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>ID</th><th>Benutzername</th><th>E-Mail</th><th>Name</th><th>Rolle</th></tr>";
        foreach ($admins as $admin) {
            echo "<tr>";
            echo "<td>" . $admin['id'] . "</td>";
            echo "<td>" . htmlspecialchars($admin['username']) . "</td>";
            echo "<td>" . htmlspecialchars($admin['email']) . "</td>";
            echo "<td>" . htmlspecialchars($admin['first_name'] . ' ' . $admin['last_name']) . "</td>";
            echo "<td>" . htmlspecialchars($admin['user_role'] ?? 'admin') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
        
    } else {
        echo "<p style='color: orange;'>⚠️ Kein Admin-Benutzer gefunden. Erstelle Standard-Admin...</p>";
        
        // Erstelle Standard-Admin
        $admin_username = 'admin';
        $admin_email = 'admin@feuerwehr.de';
        $admin_password = 'admin123'; // Sollte in Produktion geändert werden
        $admin_first_name = 'Administrator';
        $admin_last_name = 'Feuerwehr';
        
        $password_hash = hash_password($admin_password);
        
        $stmt = $db->prepare("
            INSERT INTO users (username, email, password_hash, first_name, last_name, is_admin, is_active, user_role, created_at) 
            VALUES (?, ?, ?, ?, ?, 1, 1, 'admin', NOW())
        ");
        
        $stmt->execute([
            $admin_username,
            $admin_email,
            $password_hash,
            $admin_first_name,
            $admin_last_name
        ]);
        
        $admin_id = $db->lastInsertId();
        
        echo "<p style='color: green;'>✅ Admin-Benutzer erstellt!</p>";
        echo "<h3>Anmeldedaten:</h3>";
        echo "<ul>";
        echo "<li><strong>Benutzername:</strong> $admin_username</li>";
        echo "<li><strong>E-Mail:</strong> $admin_email</li>";
        echo "<li><strong>Passwort:</strong> $admin_password</li>";
        echo "<li><strong>Name:</strong> $admin_first_name $admin_last_name</li>";
        echo "</ul>";
        echo "<p style='color: red;'><strong>Wichtig:</strong> Ändern Sie das Passwort nach der ersten Anmeldung!</p>";
    }
    
    echo "<hr>";
    echo "<p><a href='login.php'>Zur Anmeldung</a> | <a href='admin/dashboard.php'>Zum Dashboard</a></p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler: " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>
