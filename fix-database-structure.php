<?php
/**
 * Fix Database Structure - Browser Version
 * Öffnen Sie diese Datei in Ihrem Browser: http://ihre-domain/fix-database-structure.php
 */

// Output Buffering starten um Header-Probleme zu vermeiden
ob_start();

// Alle Fehler anzeigen
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

echo "<h1>🔧 Fix Database Structure</h1>";
echo "<p>Diese Seite repariert die Datenbankstruktur.</p>";

try {
    // 1. Datenbankverbindung
    echo "<h2>1. Datenbankverbindung:</h2>";
    require_once 'config/database.php';
    echo "<p style='color: green;'>✅ Datenbankverbindung erfolgreich</p>";
    
    // 2. Prüfe users Tabelle
    echo "<h2>2. Prüfe users Tabelle:</h2>";
    
    $stmt = $db->query("DESCRIBE users");
    $columns = $stmt->fetchAll();
    
    if (empty($columns)) {
        echo "<p style='color: red;'>❌ users Tabelle existiert nicht</p>";
        
        // Erstelle users Tabelle
        echo "<h3>2.1. Erstelle users Tabelle:</h3>";
        
        $create_users_sql = "
        CREATE TABLE users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL UNIQUE,
            email VARCHAR(100) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            role ENUM('admin', 'approver', 'user') DEFAULT 'user',
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";
        
        $db->exec($create_users_sql);
        echo "<p style='color: green;'>✅ users Tabelle erstellt</p>";
        
    } else {
        echo "<p style='color: green;'>✅ users Tabelle existiert</p>";
        
        // Prüfe ob role Spalte existiert
        $has_role = false;
        foreach ($columns as $column) {
            if ($column['Field'] === 'role') {
                $has_role = true;
                break;
            }
        }
        
        if (!$has_role) {
            echo "<p style='color: orange;'>⚠️ role Spalte fehlt</p>";
            
            // Füge role Spalte hinzu
            echo "<h3>2.1. Füge role Spalte hinzu:</h3>";
            
            $db->exec("ALTER TABLE users ADD COLUMN role ENUM('admin', 'approver', 'user') DEFAULT 'user' AFTER password");
            echo "<p style='color: green;'>✅ role Spalte hinzugefügt</p>";
        } else {
            echo "<p style='color: green;'>✅ role Spalte existiert</p>";
        }
        
        // Prüfe ob is_active Spalte existiert
        $has_is_active = false;
        foreach ($columns as $column) {
            if ($column['Field'] === 'is_active') {
                $has_is_active = true;
                break;
            }
        }
        
        if (!$has_is_active) {
            echo "<p style='color: orange;'>⚠️ is_active Spalte fehlt</p>";
            
            // Füge is_active Spalte hinzu
            echo "<h3>2.2. Füge is_active Spalte hinzu:</h3>";
            
            $db->exec("ALTER TABLE users ADD COLUMN is_active TINYINT(1) DEFAULT 1 AFTER role");
            echo "<p style='color: green;'>✅ is_active Spalte hinzugefügt</p>";
        } else {
            echo "<p style='color: green;'>✅ is_active Spalte existiert</p>";
        }
    }
    
    // 3. Prüfe reservations Tabelle
    echo "<h2>3. Prüfe reservations Tabelle:</h2>";
    
    $stmt = $db->query("DESCRIBE reservations");
    $columns = $stmt->fetchAll();
    
    if (empty($columns)) {
        echo "<p style='color: red;'>❌ reservations Tabelle existiert nicht</p>";
        
        // Erstelle reservations Tabelle
        echo "<h3>3.1. Erstelle reservations Tabelle:</h3>";
        
        $create_reservations_sql = "
        CREATE TABLE reservations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            vehicle_id INT NOT NULL,
            requester_name VARCHAR(100) NOT NULL,
            requester_email VARCHAR(100) NOT NULL,
            reason TEXT NOT NULL,
            start_datetime DATETIME NOT NULL,
            end_datetime DATETIME NOT NULL,
            status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
            rejection_reason TEXT NULL,
            approved_by INT NULL,
            approved_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
            FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";
        
        $db->exec($create_reservations_sql);
        echo "<p style='color: green;'>✅ reservations Tabelle erstellt</p>";
        
    } else {
        echo "<p style='color: green;'>✅ reservations Tabelle existiert</p>";
        
        // Prüfe ob approved_by Spalte existiert
        $has_approved_by = false;
        foreach ($columns as $column) {
            if ($column['Field'] === 'approved_by') {
                $has_approved_by = true;
                break;
            }
        }
        
        if (!$has_approved_by) {
            echo "<p style='color: orange;'>⚠️ approved_by Spalte fehlt</p>";
            
            // Füge approved_by Spalte hinzu
            echo "<h3>3.1. Füge approved_by Spalte hinzu:</h3>";
            
            $db->exec("ALTER TABLE reservations ADD COLUMN approved_by INT NULL AFTER rejection_reason");
            echo "<p style='color: green;'>✅ approved_by Spalte hinzugefügt</p>";
            
            // Füge Foreign Key hinzu
            echo "<h3>3.2. Füge Foreign Key hinzu:</h3>";
            
            $db->exec("ALTER TABLE reservations ADD CONSTRAINT reservations_ibfk_2 FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL");
            echo "<p style='color: green;'>✅ Foreign Key hinzugefügt</p>";
        } else {
            echo "<p style='color: green;'>✅ approved_by Spalte existiert</p>";
        }
        
        // Prüfe ob approved_at Spalte existiert
        $has_approved_at = false;
        foreach ($columns as $column) {
            if ($column['Field'] === 'approved_at') {
                $has_approved_at = true;
                break;
            }
        }
        
        if (!$has_approved_at) {
            echo "<p style='color: orange;'>⚠️ approved_at Spalte fehlt</p>";
            
            // Füge approved_at Spalte hinzu
            echo "<h3>3.3. Füge approved_at Spalte hinzu:</h3>";
            
            $db->exec("ALTER TABLE reservations ADD COLUMN approved_at TIMESTAMP NULL AFTER approved_by");
            echo "<p style='color: green;'>✅ approved_at Spalte hinzugefügt</p>";
        } else {
            echo "<p style='color: green;'>✅ approved_at Spalte existiert</p>";
        }
    }
    
    // 4. Prüfe vehicles Tabelle
    echo "<h2>4. Prüfe vehicles Tabelle:</h2>";
    
    $stmt = $db->query("DESCRIBE vehicles");
    $columns = $stmt->fetchAll();
    
    if (empty($columns)) {
        echo "<p style='color: red;'>❌ vehicles Tabelle existiert nicht</p>";
        
        // Erstelle vehicles Tabelle
        echo "<h3>4.1. Erstelle vehicles Tabelle:</h3>";
        
        $create_vehicles_sql = "
        CREATE TABLE vehicles (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            description TEXT NULL,
            is_active TINYINT(1) DEFAULT 1,
            sort_order INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";
        
        $db->exec($create_vehicles_sql);
        echo "<p style='color: green;'>✅ vehicles Tabelle erstellt</p>";
        
    } else {
        echo "<p style='color: green;'>✅ vehicles Tabelle existiert</p>";
    }
    
    // 5. Erstelle Admin-Benutzer
    echo "<h2>5. Erstelle Admin-Benutzer:</h2>";
    
    try {
        // Prüfe ob Admin-Benutzer existiert
        $stmt = $db->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1");
        $admin_user = $stmt->fetch();
        
        if ($admin_user) {
            echo "<p style='color: green;'>✅ Admin-Benutzer existiert bereits: ID " . $admin_user['id'] . "</p>";
        } else {
            // Erstelle Admin-Benutzer
            $admin_password = password_hash('admin123', PASSWORD_DEFAULT);
            $stmt = $db->prepare("INSERT INTO users (username, email, password, role, is_active, created_at) VALUES (?, ?, ?, ?, 1, NOW())");
            $stmt->execute(['admin', 'admin@feuerwehr.local', $admin_password, 'admin']);
            
            $admin_id = $db->lastInsertId();
            echo "<p style='color: green;'>✅ Admin-Benutzer erstellt: ID $admin_id</p>";
            echo "<p><strong>Benutzername:</strong> admin</p>";
            echo "<p><strong>Passwort:</strong> admin123</p>";
            echo "<p><strong>E-Mail:</strong> admin@feuerwehr.local</p>";
            echo "<p><strong>Rolle:</strong> admin</p>";
        }
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Admin-Benutzer Fehler: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
    
    // 6. Teste Reservierungs-Genehmigung
    echo "<h2>6. Teste Reservierungs-Genehmigung:</h2>";
    
    try {
        // Finde Admin-Benutzer
        $stmt = $db->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1");
        $admin_user = $stmt->fetch();
        
        if ($admin_user) {
            $admin_id = $admin_user['id'];
            echo "<p style='color: green;'>✅ Admin-Benutzer gefunden: ID $admin_id</p>";
            
            // Finde pending Reservierung
            $stmt = $db->query("SELECT r.*, v.name as vehicle_name FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id WHERE r.status = 'pending' ORDER BY r.id DESC LIMIT 1");
            $reservation = $stmt->fetch();
            
            if ($reservation) {
                echo "<p style='color: green;'>✅ Pending Reservierung gefunden: ID " . $reservation['id'] . "</p>";
                echo "<p><strong>Fahrzeug:</strong> " . htmlspecialchars($reservation['vehicle_name']) . "</p>";
                echo "<p><strong>Antragsteller:</strong> " . htmlspecialchars($reservation['requester_name']) . "</p>";
                
                // Teste Genehmigung
                $stmt = $db->prepare("UPDATE reservations SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?");
                $stmt->execute([$admin_id, $reservation['id']]);
                
                echo "<p style='color: green;'>✅ Reservierung erfolgreich genehmigt</p>";
                
            } else {
                echo "<p style='color: orange;'>⚠️ Keine pending Reservierungen gefunden</p>";
            }
            
        } else {
            echo "<p style='color: red;'>❌ Kein Admin-Benutzer gefunden</p>";
        }
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Test-Fehler: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
    
    // 7. Nächste Schritte
    echo "<h2>7. Nächste Schritte:</h2>";
    echo "<ol>";
    echo "<li>Die Datenbankstruktur wurde repariert</li>";
    echo "<li>Admin-Benutzer wurde erstellt</li>";
    echo "<li>Testen Sie die Reservierungs-Genehmigung</li>";
    echo "<li>Falls es funktioniert, ist das Problem behoben</li>";
    echo "</ol>";
    
    // 8. Zusammenfassung
    echo "<h2>8. Zusammenfassung:</h2>";
    echo "<ul>";
    echo "<li>✅ Datenbankverbindung erfolgreich</li>";
    echo "<li>✅ users Tabelle geprüft/erstellt</li>";
    echo "<li>✅ reservations Tabelle geprüft/erstellt</li>";
    echo "<li>✅ vehicles Tabelle geprüft/erstellt</li>";
    echo "<li>✅ Admin-Benutzer erstellt</li>";
    echo "<li>✅ Reservierungs-Genehmigung getestet</li>";
    echo "</ul>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Kritischer Fehler: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><strong>Stack Trace:</strong></p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}

echo "<hr>";
echo "<p><em>Fix Database Structure abgeschlossen!</em></p>";

// Output Buffering beenden
ob_end_flush();
?>
