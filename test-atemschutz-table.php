<?php
require_once 'config/database.php';

try {
    echo "<h2>Atemschutz Tabelle Test</h2>";
    
    // Prüfe ob Tabelle existiert
    $stmt = $db->query("SHOW TABLES LIKE 'atemschutz_traeger'");
    $table_exists = $stmt->fetch();
    
    if ($table_exists) {
        echo "<p>✅ Tabelle 'atemschutz_traeger' existiert</p>";
        
        // Prüfe Struktur
        $stmt = $db->query("DESCRIBE atemschutz_traeger");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<h3>Tabellen-Struktur:</h3>";
        echo "<ul>";
        foreach ($columns as $column) {
            echo "<li>{$column['Field']} - {$column['Type']} - {$column['Null']} - {$column['Default']}</li>";
        }
        echo "</ul>";
        
        // Prüfe Daten
        $stmt = $db->query("SELECT COUNT(*) as count FROM atemschutz_traeger");
        $count = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "<p>Anzahl Einträge: {$count['count']}</p>";
        
        if ($count['count'] > 0) {
            $stmt = $db->query("SELECT id, first_name, last_name, status FROM atemschutz_traeger LIMIT 5");
            $traeger = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo "<h3>Erste 5 Einträge:</h3>";
            echo "<ul>";
            foreach ($traeger as $t) {
                echo "<li>ID: {$t['id']}, Name: {$t['first_name']} {$t['last_name']}, Status: {$t['status']}</li>";
            }
            echo "</ul>";
        }
        
        // Test der API-Query
        $stmt = $db->prepare("
            SELECT id, first_name, last_name
            FROM atemschutz_traeger 
            WHERE status = 'Aktiv' 
            ORDER BY last_name, first_name
        ");
        $stmt->execute();
        $active_traeger = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<h3>Aktive Geräteträger (API-Query):</h3>";
        echo "<p>Anzahl: " . count($active_traeger) . "</p>";
        if (count($active_traeger) > 0) {
            echo "<ul>";
            foreach ($active_traeger as $t) {
                echo "<li>ID: {$t['id']}, Name: {$t['first_name']} {$t['last_name']}</li>";
            }
            echo "</ul>";
        }
        
    } else {
        echo "<p>❌ Tabelle 'atemschutz_traeger' existiert nicht</p>";
    }
    
} catch (Exception $e) {
    echo "<p>❌ Fehler: " . $e->getMessage() . "</p>";
}
?>
