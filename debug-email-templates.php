<?php
/**
 * Debug-Datei für E-Mail-Vorlagen Speicherung
 */

session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';

if (!isset($_SESSION['user_id']) || !hasAdminPermission()) {
    die("Keine Berechtigung");
}

echo "<h1>🔍 E-Mail-Vorlagen Debug</h1>";

// 1. Aktuelle E-Mail-Vorlagen aus der Datenbank laden
echo "<h2>1. Aktuelle E-Mail-Vorlagen aus der Datenbank:</h2>";
try {
    $stmt = $db->prepare("SELECT * FROM email_templates ORDER BY template_key");
    $stmt->execute();
    $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<p><strong>Anzahl Vorlagen:</strong> " . count($templates) . "</p>";
    
    foreach ($templates as $template) {
        echo "<div style='border: 1px solid #ccc; margin: 10px; padding: 10px;'>";
        echo "<h4>" . htmlspecialchars($template['template_name']) . " (" . htmlspecialchars($template['template_key']) . ")</h4>";
        echo "<p><strong>Betreff:</strong> " . htmlspecialchars($template['subject']) . "</p>";
        echo "<p><strong>Nachricht:</strong></p>";
        echo "<pre style='background: #f5f5f5; padding: 10px;'>" . htmlspecialchars($template['body']) . "</pre>";
        echo "<p><strong>Letzte Aktualisierung:</strong> " . htmlspecialchars($template['updated_at']) . "</p>";
        echo "</div>";
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>Fehler beim Laden der Vorlagen: " . htmlspecialchars($e->getMessage()) . "</p>";
}

// 2. POST-Daten simulieren (falls vorhanden)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    echo "<h2>2. POST-Daten empfangen:</h2>";
    echo "<pre>" . htmlspecialchars(print_r($_POST, true)) . "</pre>";
    
    // E-Mail-Vorlagen speichern
    if (isset($_POST['email_templates']) && is_array($_POST['email_templates'])) {
        echo "<h3>E-Mail-Vorlagen speichern:</h3>";
        
        try {
            $stmt = $db->prepare("UPDATE email_templates SET subject = ?, body = ?, updated_at = CURRENT_TIMESTAMP WHERE template_key = ?");
            $updatedCount = 0;
            
            foreach ($_POST['email_templates'] as $templateKey => $templateData) {
                echo "<p><strong>Verarbeite Template:</strong> $templateKey</p>";
                echo "<pre>" . htmlspecialchars(print_r($templateData, true)) . "</pre>";
                
                if (is_array($templateData) && isset($templateData['subject']) && isset($templateData['body'])) {
                    $subject = trim($templateData['subject']);
                    $body = trim($templateData['body']);
                    
                    echo "<p><strong>Subject:</strong> " . htmlspecialchars($subject) . "</p>";
                    echo "<p><strong>Body:</strong> " . htmlspecialchars(substr($body, 0, 100)) . "...</p>";
                    
                    $result = $stmt->execute([$subject, $body, $templateKey]);
                    
                    if ($result) {
                        $updatedCount++;
                        echo "<p style='color: green;'>✅ Template '$templateKey' erfolgreich gespeichert</p>";
                    } else {
                        echo "<p style='color: red;'>❌ Template '$templateKey' Speicherung fehlgeschlagen</p>";
                    }
                } else {
                    echo "<p style='color: red;'>❌ Template '$templateKey' hat ungültige Daten</p>";
                }
            }
            
            echo "<p><strong>Gesamt aktualisiert:</strong> $updatedCount Vorlagen</p>";
            
        } catch (Exception $e) {
            echo "<p style='color: red;'>Fehler beim Speichern: " . htmlspecialchars($e->getMessage()) . "</p>";
        }
    }
}

// 3. Test-Formular
echo "<h2>3. Test-Formular:</h2>";
echo "<form method='post'>";
echo "<input type='hidden' name='csrf_token' value='" . htmlspecialchars(generate_csrf_token()) . "'>";

foreach ($templates as $template) {
    echo "<div style='border: 1px solid #ddd; margin: 10px; padding: 10px;'>";
    echo "<h4>" . htmlspecialchars($template['template_name']) . "</h4>";
    echo "<p><strong>Betreff:</strong><br>";
    echo "<input type='text' name='email_templates[" . htmlspecialchars($template['template_key']) . "][subject]' value='" . htmlspecialchars($template['subject']) . "' style='width: 100%;'>";
    echo "</p>";
    echo "<p><strong>Nachricht:</strong><br>";
    echo "<textarea name='email_templates[" . htmlspecialchars($template['template_key']) . "][body]' rows='6' style='width: 100%;'>" . htmlspecialchars($template['body']) . "</textarea>";
    echo "</p>";
    echo "</div>";
}

echo "<button type='submit' style='background: #007bff; color: white; padding: 10px 20px; border: none; border-radius: 5px;'>E-Mail-Vorlagen speichern</button>";
echo "</form>";

// 4. Datenbank-Struktur prüfen
echo "<h2>4. Datenbank-Struktur:</h2>";
try {
    $stmt = $db->query("DESCRIBE email_templates");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' style='border-collapse: collapse;'>";
    echo "<tr><th>Feld</th><th>Typ</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
    foreach ($columns as $column) {
        echo "<tr>";
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
?>
