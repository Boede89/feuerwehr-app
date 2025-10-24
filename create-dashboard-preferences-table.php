<?php
// Erstellt Tabelle für Dashboard-Benutzereinstellungen
require_once 'config/database.php';

try {
    // Tabelle für Dashboard-Einstellungen erstellen
    $db->exec("
        CREATE TABLE IF NOT EXISTS user_dashboard_preferences (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            preference_key VARCHAR(100) NOT NULL,
            preference_value TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_user_preference (user_id, preference_key),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    
    echo "Tabelle user_dashboard_preferences erfolgreich erstellt!\n";
    
    // Standard-Einstellungen für alle Benutzer setzen
    $stmt = $db->prepare("SELECT id FROM users");
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($users as $user) {
        // Standard-Layout: alle Bereiche sichtbar
        $preferences = [
            'dashboard_layout' => 'vertical',
            'show_reservations' => '1',
            'show_atemschutz' => '1',
            'show_users' => '1',
            'show_settings' => '1',
            'reservations_limit' => '10',
            'atemschutz_limit' => '10'
        ];
        
        foreach ($preferences as $key => $value) {
            $stmt = $db->prepare("
                INSERT INTO user_dashboard_preferences (user_id, preference_key, preference_value) 
                VALUES (?, ?, ?) 
                ON DUPLICATE KEY UPDATE preference_value = VALUES(preference_value)
            ");
            $stmt->execute([$user['id'], $key, $value]);
        }
    }
    
    echo "Standard-Einstellungen für alle Benutzer gesetzt!\n";
    
} catch (Exception $e) {
    echo "Fehler: " . $e->getMessage() . "\n";
}
?>
