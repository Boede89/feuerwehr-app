<?php
/**
 * Debug-Datei für Benachrichtigungseinstellungen
 */

session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';

if (!isset($_SESSION['user_id']) || !hasAdminPermission()) {
    die("Keine Berechtigung");
}

echo "<h1>🔍 Benachrichtigungseinstellungen Debug</h1>";

// 1. Aktuelle Benutzer mit Benachrichtigungseinstellungen
echo "<h2>1. Aktuelle Benutzer mit Benachrichtigungseinstellungen:</h2>";
try {
    $stmt = $db->prepare("
        SELECT id, first_name, last_name, email, is_admin, user_role, 
               COALESCE(atemschutz_notifications, 0) as atemschutz_notifications
        FROM users 
        ORDER BY last_name, first_name
    ");
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<p><strong>Anzahl Benutzer:</strong> " . count($users) . "</p>";
    
    echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr><th>ID</th><th>Name</th><th>E-Mail</th><th>Rolle</th><th>Benachrichtigungen</th></tr>";
    
    foreach ($users as $user) {
        $status = $user['atemschutz_notifications'] ? '✅ Aktiviert' : '❌ Deaktiviert';
        $bgColor = $user['atemschutz_notifications'] ? '#d4edda' : '#f8d7da';
        
        echo "<tr style='background-color: $bgColor;'>";
        echo "<td>" . htmlspecialchars($user['id']) . "</td>";
        echo "<td>" . htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) . "</td>";
        echo "<td>" . htmlspecialchars($user['email']) . "</td>";
        echo "<td>" . htmlspecialchars($user['user_role']) . "</td>";
        echo "<td>" . $status . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Fehler beim Laden der Benutzer: " . htmlspecialchars($e->getMessage()) . "</p>";
}

// 2. POST-Daten simulieren (falls vorhanden)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    echo "<h2>2. POST-Daten empfangen:</h2>";
    echo "<pre>" . htmlspecialchars(print_r($_POST, true)) . "</pre>";
    
    // Benachrichtigungseinstellungen speichern
    if (isset($_POST['action']) && $_POST['action'] === 'update_atemschutz_notifications') {
        echo "<h3>Benachrichtigungseinstellungen speichern:</h3>";
        
        try {
            // Stelle sicher, dass die Spalte existiert
            $db->exec("ALTER TABLE users ADD COLUMN atemschutz_notifications TINYINT(1) DEFAULT 0");
            echo "<p>✅ Spalte atemschutz_notifications sichergestellt</p>";
            
            // Alle Benutzer auf 0 setzen
            $stmt = $db->prepare("UPDATE users SET atemschutz_notifications = 0");
            $result = $stmt->execute();
            echo "<p>✅ Alle Benutzer auf 0 gesetzt (Betroffene Zeilen: " . $stmt->rowCount() . ")</p>";
            
            // Ausgewählte Benutzer auf 1 setzen
            if (isset($_POST['atemschutz_notifications']) && is_array($_POST['atemschutz_notifications'])) {
                $selectedUsers = $_POST['atemschutz_notifications'];
                echo "<p><strong>Ausgewählte Benutzer:</strong> " . implode(', ', $selectedUsers) . "</p>";
                
                $placeholders = str_repeat('?,', count($selectedUsers) - 1) . '?';
                $stmt = $db->prepare("UPDATE users SET atemschutz_notifications = 1 WHERE id IN ($placeholders)");
                $result = $stmt->execute($selectedUsers);
                echo "<p>✅ Ausgewählte Benutzer aktiviert (Betroffene Zeilen: " . $stmt->rowCount() . ")</p>";
            } else {
                echo "<p>⚠️ Keine Benutzer ausgewählt</p>";
            }
            
            echo "<p style='color: green;'><strong>✅ Benachrichtigungseinstellungen erfolgreich gespeichert!</strong></p>";
            
        } catch (Exception $e) {
            echo "<p style='color: red;'>❌ Fehler beim Speichern: " . htmlspecialchars($e->getMessage()) . "</p>";
        }
    }
}

// 3. Test-Formular
echo "<h2>3. Test-Formular:</h2>";
echo "<form method='post'>";
echo "<input type='hidden' name='csrf_token' value='" . htmlspecialchars(generate_csrf_token()) . "'>";
echo "<input type='hidden' name='action' value='update_atemschutz_notifications'>";

echo "<p><strong>Wählen Sie die Benutzer aus, die Benachrichtigungen erhalten sollen:</strong></p>";

foreach ($users as $user) {
    $checked = $user['atemschutz_notifications'] ? 'checked' : '';
    echo "<div style='margin: 5px 0;'>";
    echo "<input type='checkbox' name='atemschutz_notifications[]' value='" . htmlspecialchars($user['id']) . "' $checked> ";
    echo "<label>" . htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) . " (" . htmlspecialchars($user['email']) . ")</label>";
    echo "</div>";
}

echo "<br><button type='submit' style='background: #007bff; color: white; padding: 10px 20px; border: none; border-radius: 5px;'>Benachrichtigungseinstellungen speichern</button>";
echo "</form>";

// 4. Datenbank-Struktur prüfen
echo "<h2>4. Datenbank-Struktur (users Tabelle):</h2>";
try {
    $stmt = $db->query("DESCRIBE users");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' style='border-collapse: collapse;'>";
    echo "<tr><th>Feld</th><th>Typ</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
    foreach ($columns as $column) {
        $highlight = $column['Field'] === 'atemschutz_notifications' ? 'background-color: #ffffcc;' : '';
        echo "<tr style='$highlight'>";
        echo "<td>" . htmlspecialchars($column['Field']) . "</td>";
        echo "<td>" . htmlspecialchars($column['Type']) . "</td>";
        echo "<td>" . htmlspecialchars($column['Null']) . "</td>";
        echo "<td>" . htmlspecialchars($column['Key']) . "</td>";
        echo "<td>" . htmlspecialchars($column['Default']) . "</td>";
        echo "<td>" . htmlspecialchars($column['Extra']) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} catch (Exception $e) {
    echo "<p style='color: red;'>Fehler beim Abfragen der Struktur: " . htmlspecialchars($e->getMessage()) . "</p>";
}

// 5. SQL-Statements testen
echo "<h2>5. SQL-Statements testen:</h2>";
try {
    echo "<h3>Alle Benutzer mit Benachrichtigungen:</h3>";
    $stmt = $db->query("SELECT COUNT(*) as count FROM users WHERE atemschutz_notifications = 1");
    $result = $stmt->fetch();
    echo "<p><strong>Anzahl aktivierte Benachrichtigungen:</strong> " . $result['count'] . "</p>";
    
    echo "<h3>Alle Benutzer ohne Benachrichtigungen:</h3>";
    $stmt = $db->query("SELECT COUNT(*) as count FROM users WHERE atemschutz_notifications = 0 OR atemschutz_notifications IS NULL");
    $result = $stmt->fetch();
    echo "<p><strong>Anzahl deaktivierte Benachrichtigungen:</strong> " . $result['count'] . "</p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Fehler bei SQL-Tests: " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>
