<?php
session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';

echo "<h2>Feedback-System Debug</h2>";

// 1. Session prüfen
echo "<h3>1. Session-Status:</h3>";
if (isset($_SESSION['user_id'])) {
    echo "<p style='color: green;'>✓ Session aktiv - User ID: " . $_SESSION['user_id'] . "</p>";
} else {
    echo "<p style='color: red;'>✗ Keine aktive Session</p>";
}

// 2. Datenbankverbindung prüfen
echo "<h3>2. Datenbankverbindung:</h3>";
try {
    $test_query = $db->query("SELECT 1");
    echo "<p style='color: green;'>✓ Datenbankverbindung funktioniert</p>";
} catch (Exception $e) {
    echo "<p style='color: red;'>✗ Datenbankfehler: " . htmlspecialchars($e->getMessage()) . "</p>";
}

// 3. Feedback-Tabelle prüfen
echo "<h3>3. Feedback-Tabelle:</h3>";
try {
    $table_check = $db->query("SHOW TABLES LIKE 'feedback'");
    if ($table_check->rowCount() > 0) {
        echo "<p style='color: green;'>✓ Feedback-Tabelle existiert</p>";
        
        // Tabellenstruktur anzeigen
        $stmt = $db->query("DESCRIBE feedback");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "<p>Spalten: " . implode(', ', array_column($columns, 'Field')) . "</p>";
    } else {
        echo "<p style='color: red;'>✗ Feedback-Tabelle existiert nicht</p>";
        echo "<p><a href='setup-feedback-table.php'>→ Tabelle erstellen</a></p>";
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>✗ Fehler beim Prüfen der Tabelle: " . htmlspecialchars($e->getMessage()) . "</p>";
}

// 4. Admin-Benutzer prüfen
echo "<h3>4. Admin-Benutzer:</h3>";
try {
    $stmt = $db->query("
        SELECT id, username, first_name, last_name, email, user_role, is_admin, can_settings 
        FROM users 
        WHERE user_role = 'admin' OR is_admin = 1 OR can_settings = 1
    ");
    $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($admins)) {
        echo "<p style='color: red;'>✗ Keine Admin-Benutzer gefunden</p>";
    } else {
        echo "<p style='color: green;'>✓ " . count($admins) . " Admin-Benutzer gefunden:</p>";
        echo "<ul>";
        foreach ($admins as $admin) {
            echo "<li>ID: {$admin['id']}, Name: {$admin['first_name']} {$admin['last_name']}, E-Mail: {$admin['email']}</li>";
        }
        echo "</ul>";
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>✗ Fehler beim Laden der Admins: " . htmlspecialchars($e->getMessage()) . "</p>";
}

// 5. API-Endpunkt testen
echo "<h3>5. API-Endpunkt testen:</h3>";
echo "<form method='POST' action='api/submit-feedback.php' id='testForm'>";
echo "<input type='hidden' name='feedback_type' value='general'>";
echo "<input type='hidden' name='subject' value='Test Feedback'>";
echo "<input type='hidden' name='message' value='Dies ist ein Test-Feedback'>";
echo "<input type='hidden' name='email' value='test@example.com'>";
echo "<button type='submit'>Test Feedback senden</button>";
echo "</form>";

// 6. JavaScript-Konsole prüfen
echo "<h3>6. JavaScript-Debug:</h3>";
echo "<p>Öffnen Sie die Browser-Konsole (F12) und prüfen Sie auf Fehler.</p>";

// 7. E-Mail-Einstellungen prüfen
echo "<h3>7. E-Mail-Einstellungen:</h3>";
try {
    $stmt = $db->query("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'smtp_%'");
    $email_settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    if (empty($email_settings)) {
        echo "<p style='color: orange;'>⚠ Keine SMTP-Einstellungen gefunden</p>";
    } else {
        echo "<p style='color: green;'>✓ SMTP-Einstellungen gefunden:</p>";
        echo "<ul>";
        foreach ($email_settings as $key => $value) {
            $display_value = (strpos($key, 'password') !== false) ? '***' : $value;
            echo "<li>{$key}: {$display_value}</li>";
        }
        echo "</ul>";
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>✗ Fehler beim Laden der E-Mail-Einstellungen: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<hr>";
echo "<p><a href='index.php'>← Zurück zur Startseite</a></p>";
echo "<p><a href='admin/dashboard.php'>← Zum Dashboard</a></p>";
?>
