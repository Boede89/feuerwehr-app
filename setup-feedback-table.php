<?php
require_once 'config/database.php';

echo "<h2>Feedback-Tabelle Setup</h2>";

try {
    // Prüfen ob Tabelle bereits existiert
    $check = $db->query("SHOW TABLES LIKE 'feedback'");
    if ($check->rowCount() > 0) {
        echo "<p style='color: green;'>✓ Feedback-Tabelle existiert bereits</p>";
        
        // Tabellenstruktur anzeigen
        $stmt = $db->query("DESCRIBE feedback");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "<h3>Tabellenstruktur:</h3>";
        echo "<table border='1' cellpadding='5' cellspacing='0'>";
        echo "<tr><th>Feld</th><th>Typ</th><th>Null</th><th>Schlüssel</th></tr>";
        foreach ($columns as $column) {
            echo "<tr>";
            echo "<td>{$column['Field']}</td>";
            echo "<td>{$column['Type']}</td>";
            echo "<td>{$column['Null']}</td>";
            echo "<td>{$column['Key']}</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        // Testeintrag erstellen
        try {
            $stmt = $db->prepare("
                INSERT INTO feedback (feedback_type, subject, message, email, user_id, ip_address) 
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute(['general', 'Test Feedback', 'Dies ist ein Test-Feedback', 'test@example.com', null, '127.0.0.1']);
            echo "<p style='color: green;'>✓ Test-Eintrag erfolgreich erstellt</p>";
        } catch (Exception $e) {
            echo "<p style='color: orange;'>⚠ Test-Eintrag konnte nicht erstellt werden: " . htmlspecialchars($e->getMessage()) . "</p>";
        }
        
    } else {
        echo "<p style='color: blue;'>ℹ Erstelle Feedback-Tabelle...</p>";
        
        // SQL-Datei einlesen und ausführen
        $sql = file_get_contents('create-feedback-table.sql');
        
        if ($sql === false) {
            throw new Exception("SQL-Datei konnte nicht gelesen werden");
        }
        
        $db->exec($sql);
        echo "<p style='color: green;'>✓ Feedback-Tabelle erfolgreich erstellt!</p>";
        
        // Prüfen ob Tabelle existiert
        $stmt = $db->query("SHOW TABLES LIKE 'feedback'");
        if ($stmt->rowCount() > 0) {
            echo "<p style='color: green;'>✓ Tabelle 'feedback' existiert</p>";
            
            // Tabellenstruktur anzeigen
            $stmt = $db->query("DESCRIBE feedback");
            $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo "<h3>Tabellenstruktur:</h3>";
            echo "<table border='1' cellpadding='5' cellspacing='0'>";
            echo "<tr><th>Feld</th><th>Typ</th><th>Null</th><th>Schlüssel</th></tr>";
            foreach ($columns as $column) {
                echo "<tr>";
                echo "<td>{$column['Field']}</td>";
                echo "<td>{$column['Type']}</td>";
                echo "<td>{$column['Null']}</td>";
                echo "<td>{$column['Key']}</td>";
                echo "</tr>";
            }
            echo "</table>";
            
            // Testeintrag erstellen
            $stmt = $db->prepare("
                INSERT INTO feedback (feedback_type, subject, message, email, user_id, ip_address) 
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute(['general', 'Test Feedback', 'Dies ist ein Test-Feedback', 'test@example.com', null, '127.0.0.1']);
            echo "<p style='color: green;'>✓ Test-Eintrag erfolgreich erstellt</p>";
            
        } else {
            echo "<p style='color: red;'>✗ Fehler: Tabelle 'feedback' wurde nicht erstellt</p>";
        }
    }
    
    // Admin-E-Mails prüfen
    echo "<h3>Admin-E-Mails prüfen:</h3>";
    $stmt = $db->query("
        SELECT DISTINCT email, username, first_name, last_name 
        FROM users 
        WHERE (user_role = 'admin' OR is_admin = 1) 
        AND email IS NOT NULL 
        AND email != ''
    ");
    $admin_emails = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($admin_emails)) {
        echo "<p style='color: red;'>✗ Keine Admin-E-Mails gefunden</p>";
    } else {
        echo "<p style='color: green;'>✓ " . count($admin_emails) . " Admin-E-Mails gefunden:</p>";
        echo "<ul>";
        foreach ($admin_emails as $admin) {
            echo "<li>" . htmlspecialchars($admin['first_name'] . ' ' . $admin['last_name']) . " (" . htmlspecialchars($admin['email']) . ")</li>";
        }
        echo "</ul>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Fehler: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<hr>";
echo "<p><a href='index.php'>← Zurück zur Startseite</a></p>";
echo "<p><a href='admin/dashboard.php'>← Zum Dashboard</a></p>";
?>
