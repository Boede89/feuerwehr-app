<?php
require_once 'config/database.php';

try {
    echo "<h2>Atemschutz Workflow Test</h2>";
    
    // 1. Prüfe ob atemschutz_entries Tabelle existiert und Daten hat
    $stmt = $db->query("SELECT COUNT(*) as count FROM atemschutz_entries");
    $count = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "<p>Atemschutz-Einträge in DB: {$count['count']}</p>";
    
    if ($count['count'] > 0) {
        $stmt = $db->query("SELECT * FROM atemschutz_entries ORDER BY created_at DESC LIMIT 3");
        $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<h3>Letzte 3 Einträge:</h3>";
        echo "<ul>";
        foreach ($entries as $entry) {
            echo "<li>ID: {$entry['id']}, Typ: {$entry['entry_type']}, Datum: {$entry['entry_date']}, Status: {$entry['status']}</li>";
        }
        echo "</ul>";
    }
    
    // 2. Prüfe atemschutz_entry_traeger Verknüpfungen
    $stmt = $db->query("SELECT COUNT(*) as count FROM atemschutz_entry_traeger");
    $count = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "<p>Atemschutz-Entry-Traeger Verknüpfungen: {$count['count']}</p>";
    
    if ($count['count'] > 0) {
        $stmt = $db->query("SELECT aet.*, ae.entry_type, ae.entry_date, at.first_name, at.last_name 
                           FROM atemschutz_entry_traeger aet 
                           JOIN atemschutz_entries ae ON aet.entry_id = ae.id 
                           JOIN atemschutz_traeger at ON aet.traeger_id = at.id 
                           ORDER BY aet.created_at DESC LIMIT 5");
        $links = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<h3>Letzte 5 Verknüpfungen:</h3>";
        echo "<ul>";
        foreach ($links as $link) {
            echo "<li>Entry {$link['entry_id']} ({$link['entry_type']}, {$link['entry_date']}) → {$link['first_name']} {$link['last_name']}</li>";
        }
        echo "</ul>";
    }
    
    // 3. Prüfe atemschutz_traeger Tabelle
    $stmt = $db->query("SELECT COUNT(*) as count FROM atemschutz_traeger");
    $count = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "<p>Atemschutz-Traeger in DB: {$count['count']}</p>";
    
    if ($count['count'] > 0) {
        $stmt = $db->query("SELECT id, first_name, last_name, strecke_am, g263_am, uebung_am FROM atemschutz_traeger LIMIT 3");
        $traeger = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<h3>Erste 3 Geräteträger:</h3>";
        echo "<ul>";
        foreach ($traeger as $t) {
            echo "<li>{$t['first_name']} {$t['last_name']} - Strecke: {$t['strecke_am']}, G26.3: {$t['g263_am']}, Übung: {$t['uebung_am']}</li>";
        }
        echo "</ul>";
    }
    
    // 4. Teste Dashboard Query
    $stmt = $db->prepare("
        SELECT ae.*, u.first_name, u.last_name,
               GROUP_CONCAT(CONCAT(at.first_name, ' ', at.last_name) ORDER BY at.last_name, at.first_name SEPARATOR ', ') as traeger_names
        FROM atemschutz_entries ae
        JOIN users u ON ae.requester_id = u.id
        LEFT JOIN atemschutz_entry_traeger aet ON ae.id = aet.entry_id
        LEFT JOIN atemschutz_traeger at ON aet.traeger_id = at.id
        WHERE ae.status = 'pending'
        GROUP BY ae.id
        ORDER BY ae.created_at DESC
    ");
    $stmt->execute();
    $pending_entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<h3>Offene Anträge (Dashboard Query):</h3>";
    echo "<p>Anzahl: " . count($pending_entries) . "</p>";
    
    if (count($pending_entries) > 0) {
        echo "<ul>";
        foreach ($pending_entries as $entry) {
            echo "<li>ID: {$entry['id']}, Typ: {$entry['entry_type']}, Datum: {$entry['entry_date']}, Antragsteller: {$entry['first_name']} {$entry['last_name']}, Geräteträger: {$entry['traeger_names']}</li>";
        }
        echo "</ul>";
    }
    
} catch (Exception $e) {
    echo "<p>❌ Fehler: " . $e->getMessage() . "</p>";
}
?>
