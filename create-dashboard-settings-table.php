<?php
/**
 * Dashboard-Einstellungen Tabelle erstellen
 * Speichert die individuellen Dashboard-Layouts der Benutzer
 */

require_once 'includes/functions.php';

// Session starten
session_start();

// Prüfen ob Benutzer eingeloggt ist
if (!isset($_SESSION['user_id'])) {
    die('Nicht autorisiert');
}

try {
    // SQL für Dashboard-Einstellungen Tabelle
    $sql = "
    CREATE TABLE IF NOT EXISTS dashboard_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        section_id VARCHAR(50) NOT NULL,
        is_collapsed BOOLEAN DEFAULT FALSE,
        sort_order INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_user_section (user_id, section_id),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    
    // Tabelle erstellen
    $pdo = getDBConnection();
    $pdo->exec($sql);
    
    echo "<h1>Dashboard-Einstellungen Tabelle erstellt!</h1>";
    echo "<p style='color: green;'>✓ Tabelle 'dashboard_settings' wurde erfolgreich erstellt.</p>";
    
    // Standard-Einstellungen für alle Benutzer erstellen
    $sections = [
        'reservations' => ['name' => 'Offene Reservierungen', 'order' => 1],
        'atemschutz' => ['name' => 'Atemschutz-Übungen', 'order' => 2],
        'feedback' => ['name' => 'Feedback-Übersicht', 'order' => 3],
        'recent_activities' => ['name' => 'Letzte Aktivitäten', 'order' => 4]
    ];
    
    // Alle Benutzer abrufen
    $stmt = $pdo->query("SELECT id FROM users");
    $users = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $insertStmt = $pdo->prepare("
        INSERT IGNORE INTO dashboard_settings (user_id, section_id, is_collapsed, sort_order) 
        VALUES (?, ?, ?, ?)
    ");
    
    $inserted = 0;
    foreach ($users as $userId) {
        foreach ($sections as $sectionId => $sectionData) {
            $insertStmt->execute([$userId, $sectionId, false, $sectionData['order']]);
            $inserted++;
        }
    }
    
    echo "<p style='color: green;'>✓ Standard-Einstellungen für " . count($users) . " Benutzer erstellt ($inserted Einträge).</p>";
    
    echo "<h3>Verfügbare Dashboard-Bereiche:</h3>";
    echo "<ul>";
    foreach ($sections as $sectionId => $sectionData) {
        echo "<li><strong>$sectionId</strong>: " . $sectionData['name'] . " (Reihenfolge: " . $sectionData['order'] . ")</li>";
    }
    echo "</ul>";
    
    echo "<h3>Nächste Schritte:</h3>";
    echo "<p>1. <a href='admin/dashboard.php'>Dashboard testen</a></p>";
    echo "<p>2. <a href='index.php'>Zurück zur Startseite</a></p>";
    
} catch (Exception $e) {
    echo "<h1>Fehler beim Erstellen der Tabelle</h1>";
    echo "<p style='color: red;'>Fehler: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><a href='index.php'>Zurück zur Startseite</a></p>";
}
?>
