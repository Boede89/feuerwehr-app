<?php
/**
 * Copy JSON from File to Database - Browser Version
 * Öffnen Sie diese Datei in Ihrem Browser: http://ihre-domain/copy-json-from-file.php
 */

require_once 'config/database.php';

echo "<h1>📋 JSON von Datei in Datenbank kopieren</h1>";
echo "<p>Diese Seite kopiert den JSON-Inhalt aus der Service Account Datei in die Datenbank.</p>";

try {
    // Service Account Datei-Pfad aus Datenbank laden
    $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'google_calendar_service_account_file'");
    $stmt->execute();
    $file_path = $stmt->fetchColumn();
    
    if (!$file_path) {
        echo "<p style='color: red;'>❌ Kein Service Account Datei-Pfad in der Datenbank gefunden.</p>";
        echo "<p>Bitte konfigurieren Sie zuerst den Datei-Pfad in den Einstellungen.</p>";
        exit;
    }
    
    echo "<h2>1. Service Account Datei-Pfad:</h2>";
    echo "<p><strong>Pfad:</strong> " . htmlspecialchars($file_path) . "</p>";
    
    if (!file_exists($file_path)) {
        echo "<p style='color: red;'>❌ Datei existiert nicht!</p>";
        echo "<p>Bitte überprüfen Sie den Pfad oder laden Sie die Datei hoch.</p>";
        exit;
    }
    
    echo "<p style='color: green;'>✅ Datei existiert</p>";
    
    // Datei-Inhalt laden
    $file_content = file_get_contents($file_path);
    echo "<p><strong>Datei-Größe:</strong> " . strlen($file_content) . " Zeichen</p>";
    
    // JSON validieren
    $json_data = json_decode($file_content, true);
    
    if (!$json_data) {
        echo "<p style='color: red;'>❌ Datei enthält ungültigen JSON!</p>";
        echo "<p><strong>Fehler:</strong> " . json_last_error_msg() . "</p>";
        echo "<p><strong>Inhalt (erste 200 Zeichen):</strong></p>";
        echo "<pre>" . htmlspecialchars(substr($file_content, 0, 200)) . "...</pre>";
        exit;
    }
    
    echo "<p style='color: green;'>✅ Datei enthält gültigen JSON</p>";
    echo "<p><strong>Type:</strong> " . htmlspecialchars($json_data['type'] ?? 'NICHT GEFUNDEN') . "</p>";
    echo "<p><strong>Project ID:</strong> " . htmlspecialchars($json_data['project_id'] ?? 'NICHT GEFUNDEN') . "</p>";
    echo "<p><strong>Client Email:</strong> " . htmlspecialchars($json_data['client_email'] ?? 'NICHT GEFUNDEN') . "</p>";
    
    // JSON in Datenbank speichern
    echo "<h2>2. JSON in Datenbank speichern:</h2>";
    
    $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('google_calendar_service_account_json', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
    $result = $stmt->execute([$file_content, $file_content]);
    
    if ($result) {
        echo "<p style='color: green;'>✅ JSON erfolgreich in Datenbank gespeichert!</p>";
        
        // Verifikation
        echo "<h2>3. Verifikation:</h2>";
        $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'google_calendar_service_account_json'");
        $stmt->execute();
        $saved_json = $stmt->fetchColumn();
        
        $verification_data = json_decode($saved_json, true);
        if ($verification_data) {
            echo "<p style='color: green;'>✅ Verifikation erfolgreich - JSON ist gültig</p>";
            echo "<p><strong>Type:</strong> " . htmlspecialchars($verification_data['type'] ?? 'NICHT GEFUNDEN') . "</p>";
            echo "<p><strong>Project ID:</strong> " . htmlspecialchars($verification_data['project_id'] ?? 'NICHT GEFUNDEN') . "</p>";
            echo "<p><strong>Client Email:</strong> " . htmlspecialchars($verification_data['client_email'] ?? 'NICHT GEFUNDEN') . "</p>";
            
            echo "<h2>4. Nächste Schritte:</h2>";
            echo "<ol>";
            echo "<li>Gehen Sie zu <a href='admin/settings.php'>Admin → Einstellungen</a></li>";
            echo "<li>Überprüfen Sie, ob der JSON-Inhalt korrekt angezeigt wird</li>";
            echo "<li>Testen Sie mit: <a href='test-google-calendar-service-account.php'>Google Calendar Test</a></li>";
            echo "</ol>";
        } else {
            echo "<p style='color: red;'>❌ Verifikation fehlgeschlagen - JSON ist ungültig</p>";
        }
    } else {
        echo "<p style='color: red;'>❌ Fehler beim Speichern in Datenbank</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<hr>";
echo "<p><em>JSON Copy abgeschlossen!</em></p>";
?>
