<?php
require_once 'config/database.php';

echo "<h2>Feedback-Tabelle erstellen</h2>";

try {
    // SQL-Datei einlesen und ausführen
    $sql = file_get_contents('create-feedback-table-simple.sql');
    
    if ($sql === false) {
        throw new Exception("SQL-Datei konnte nicht gelesen werden");
    }
    
    // SQL in einzelne Statements aufteilen
    $statements = explode(';', $sql);
    
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if (!empty($statement)) {
            $db->exec($statement);
        }
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
