<?php
require_once 'config/database.php';

echo "<h2>Feedback-Tabelle erstellen</h2>";

try {
    // SQL direkt in der Datei
    $sql_statements = [
        "CREATE TABLE IF NOT EXISTS feedback (
            id INT AUTO_INCREMENT PRIMARY KEY,
            feedback_type ENUM('bug', 'feature', 'improvement', 'general') NOT NULL,
            subject VARCHAR(255) NOT NULL,
            message TEXT NOT NULL,
            email VARCHAR(255) NULL,
            user_id INT NULL,
            ip_address VARCHAR(45) NULL,
            status ENUM('new', 'in_progress', 'resolved', 'closed') DEFAULT 'new',
            admin_notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_feedback_type (feedback_type),
            INDEX idx_status (status),
            INDEX idx_created_at (created_at),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        
        "INSERT INTO feedback (feedback_type, subject, message, email, user_id, ip_address) 
         VALUES ('general', 'Test Feedback', 'Dies ist ein Test-Feedback', 'test@example.com', NULL, '127.0.0.1')"
    ];
    
    // SQL-Statements ausführen
    foreach ($sql_statements as $sql) {
        $db->exec($sql);
    }
    
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
        
        // Testeinträge prüfen
        $stmt = $db->query("SELECT COUNT(*) as count FROM feedback");
        $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        echo "<p style='color: green;'>✓ {$count} Einträge in der Feedback-Tabelle</p>";
        
    } else {
        echo "<p style='color: red;'>✗ Fehler: Tabelle 'feedback' wurde nicht erstellt</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Fehler beim Erstellen der Feedback-Tabelle: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<hr>";
echo "<p><a href='debug-feedback.php'>← Debug-Seite erneut aufrufen</a></p>";
echo "<p><a href='index.php'>← Zurück zur Startseite</a></p>";
echo "<p><a href='admin/dashboard.php'>← Zum Dashboard</a></p>";
?>
