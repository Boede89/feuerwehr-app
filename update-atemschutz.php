<?php
/**
 * Update-Script für Atemschutztauglichkeits-Überwachung
 * 
 * Dieses Script fügt die Atemschutz-Funktionalität zu einer bestehenden Installation hinzu.
 * Führe es aus, nachdem du die neuen Dateien hochgeladen hast.
 */

require_once 'includes/db.php';

echo "🔄 Atemschutztauglichkeits-Überwachung wird eingerichtet...\n\n";

try {
    // 1. Tabelle für Atemschutzgeräteträger erstellen
    echo "1. Erstelle Tabelle 'atemschutz_traeger'...\n";
    
    $sql = "CREATE TABLE IF NOT EXISTS atemschutz_traeger (
        id INT AUTO_INCREMENT PRIMARY KEY,
        vorname VARCHAR(100) NOT NULL,
        nachname VARCHAR(100) NOT NULL,
        email VARCHAR(255) NULL,
        geburtsdatum DATE NOT NULL,
        alter_jahre INT NOT NULL,
        strecke_am DATE NULL,
        strecke_bis DATE NULL,
        g263_am DATE NULL,
        g263_bis DATE NULL,
        uebung_am DATE NULL,
        uebung_bis DATE NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    $db->exec($sql);
    echo "   ✅ Tabelle 'atemschutz_traeger' erfolgreich erstellt!\n\n";
    
    // 2. Berechtigung für Atemschutztauglichkeit hinzufügen
    echo "2. Füge Berechtigung 'can_atemschutz' hinzu...\n";
    
    // Prüfe ob Spalte bereits existiert
    $stmt = $db->prepare("SHOW COLUMNS FROM users LIKE 'can_atemschutz'");
    $stmt->execute();
    $column_exists = $stmt->fetch();
    
    if (!$column_exists) {
        $sql = "ALTER TABLE users ADD COLUMN can_atemschutz BOOLEAN DEFAULT FALSE";
        $db->exec($sql);
        echo "   ✅ Spalte 'can_atemschutz' zu users-Tabelle hinzugefügt!\n\n";
    } else {
        echo "   ℹ️ Spalte 'can_atemschutz' existiert bereits.\n\n";
    }
    
    // 3. Prüfe ob alle erforderlichen Dateien existieren
    echo "3. Prüfe erforderliche Dateien...\n";
    
    $required_files = [
        'admin/atemschutz.php' => 'Admin-Interface für Atemschutzgeräteträger',
        'setup-atemschutz-database.sql' => 'Datenbank-Setup-Script',
        'ATEMSCHUTZ_README.md' => 'Dokumentation'
    ];
    
    $all_files_exist = true;
    foreach ($required_files as $file => $description) {
        if (file_exists($file)) {
            echo "   ✅ $file - $description\n";
        } else {
            echo "   ❌ $file - $description (FEHLT!)\n";
            $all_files_exist = false;
        }
    }
    
    if (!$all_files_exist) {
        echo "\n⚠️ Einige Dateien fehlen! Bitte lade alle Dateien hoch.\n";
        exit(1);
    }
    
    echo "\n4. Prüfe Berechtigungssystem...\n";
    
    // Prüfe ob has_permission Funktion existiert
    if (function_exists('has_permission')) {
        echo "   ✅ Berechtigungssystem verfügbar\n";
    } else {
        echo "   ⚠️ Berechtigungssystem nicht vollständig verfügbar\n";
    }
    
    echo "\n🎉 Atemschutztauglichkeits-Überwachung erfolgreich eingerichtet!\n\n";
    
    echo "📋 Nächste Schritte:\n";
    echo "1. Gehe zu Admin → Benutzer\n";
    echo "2. Aktiviere 'Atemschutztauglichkeits-Überwachung' für gewünschte Benutzer\n";
    echo "3. Melde dich neu an, um die Berechtigungen zu laden\n";
    echo "4. Gehe zu Admin → Atemschutz oder nutze den Link auf der Hauptseite\n\n";
    
    echo "📖 Dokumentation: Siehe ATEMSCHUTZ_README.md\n";
    
} catch (PDOException $e) {
    echo "❌ Datenbankfehler: " . $e->getMessage() . "\n";
    exit(1);
} catch (Exception $e) {
    echo "❌ Fehler: " . $e->getMessage() . "\n";
    exit(1);
}
?>
