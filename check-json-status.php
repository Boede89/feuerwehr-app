<?php
/**
 * JSON Status Check - Browser Version
 * Öffnen Sie diese Datei in Ihrem Browser: http://ihre-domain/check-json-status.php
 */

require_once 'config/database.php';

echo "<h1>🔍 JSON Status Check</h1>";
echo "<p>Diese Seite überprüft den aktuellen Status der Google Calendar JSON-Einstellungen.</p>";

try {
    // Alle Google Calendar Einstellungen laden
    $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'google_calendar_%'");
    $stmt->execute();
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    echo "<h2>1. Alle Google Calendar Einstellungen:</h2>";
    echo "<table border='1' cellpadding='5' cellspacing='0'>";
    echo "<tr><th>Einstellung</th><th>Wert (erste 100 Zeichen)</th><th>Länge</th></tr>";
    
    foreach ($settings as $key => $value) {
        $preview = strlen($value) > 100 ? substr($value, 0, 100) . '...' : $value;
        $length = strlen($value);
        echo "<tr>";
        echo "<td><strong>" . htmlspecialchars($key) . "</strong></td>";
        echo "<td><pre>" . htmlspecialchars($preview) . "</pre></td>";
        echo "<td>" . $length . " Zeichen</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    echo "<h2>2. JSON-Validierung:</h2>";
    
    // Prüfe google_calendar_service_account_json
    if (isset($settings['google_calendar_service_account_json']) && !empty($settings['google_calendar_service_account_json'])) {
        $json_content = $settings['google_calendar_service_account_json'];
        $json_data = json_decode($json_content, true);
        
        if ($json_data) {
            echo "<p style='color: green;'>✅ google_calendar_service_account_json ist gültig!</p>";
            echo "<p><strong>Type:</strong> " . htmlspecialchars($json_data['type'] ?? 'NICHT GEFUNDEN') . "</p>";
            echo "<p><strong>Project ID:</strong> " . htmlspecialchars($json_data['project_id'] ?? 'NICHT GEFUNDEN') . "</p>";
            echo "<p><strong>Client Email:</strong> " . htmlspecialchars($json_data['client_email'] ?? 'NICHT GEFUNDEN') . "</p>";
        } else {
            echo "<p style='color: red;'>❌ google_calendar_service_account_json ist ungültig!</p>";
            echo "<p><strong>Fehler:</strong> " . json_last_error_msg() . "</p>";
            echo "<p><strong>Inhalt (erste 200 Zeichen):</strong></p>";
            echo "<pre>" . htmlspecialchars(substr($json_content, 0, 200)) . "...</pre>";
        }
    } else {
        echo "<p style='color: orange;'>⚠️ google_calendar_service_account_json ist leer oder nicht vorhanden</p>";
    }
    
    // Prüfe google_calendar_service_account_file
    if (isset($settings['google_calendar_service_account_file']) && !empty($settings['google_calendar_service_account_file'])) {
        $file_path = $settings['google_calendar_service_account_file'];
        echo "<p><strong>Service Account Datei:</strong> " . htmlspecialchars($file_path) . "</p>";
        
        if (file_exists($file_path)) {
            echo "<p style='color: green;'>✅ Datei existiert</p>";
            
            $file_content = file_get_contents($file_path);
            $file_json = json_decode($file_content, true);
            
            if ($file_json) {
                echo "<p style='color: green;'>✅ Datei enthält gültigen JSON</p>";
                echo "<p><strong>Type:</strong> " . htmlspecialchars($file_json['type'] ?? 'NICHT GEFUNDEN') . "</p>";
                echo "<p><strong>Project ID:</strong> " . htmlspecialchars($file_json['project_id'] ?? 'NICHT GEFUNDEN') . "</p>";
            } else {
                echo "<p style='color: red;'>❌ Datei enthält ungültigen JSON</p>";
            }
        } else {
            echo "<p style='color: red;'>❌ Datei existiert nicht</p>";
        }
    } else {
        echo "<p style='color: orange;'>⚠️ google_calendar_service_account_file ist leer oder nicht vorhanden</p>";
    }
    
    // Prüfe google_calendar_api_key (Fallback)
    if (isset($settings['google_calendar_api_key']) && !empty($settings['google_calendar_api_key'])) {
        $api_key = $settings['google_calendar_api_key'];
        
        // Prüfe ob es ein JSON ist (durch Double-Encoding)
        if (strpos($api_key, '&quot;') !== false || strpos($api_key, '&amp;') !== false) {
            echo "<p style='color: red;'>❌ google_calendar_api_key enthält beschädigten JSON (Double-Encoding)</p>";
            echo "<p><strong>Inhalt (erste 200 Zeichen):</strong></p>";
            echo "<pre>" . htmlspecialchars(substr($api_key, 0, 200)) . "...</pre>";
        } else {
            echo "<p style='color: blue;'>ℹ️ google_calendar_api_key ist gesetzt (API Key)</p>";
        }
    } else {
        echo "<p style='color: orange;'>⚠️ google_calendar_api_key ist leer oder nicht vorhanden</p>";
    }
    
    echo "<h2>3. Empfohlene Aktionen:</h2>";
    
    if (isset($settings['google_calendar_service_account_json']) && !empty($settings['google_calendar_service_account_json'])) {
        $json_data = json_decode($settings['google_calendar_service_account_json'], true);
        if ($json_data) {
            echo "<p style='color: green;'>✅ Alles in Ordnung! Google Calendar Service Account ist korrekt konfiguriert.</p>";
            echo "<p>Sie können jetzt <a href='test-google-calendar-service-account.php'>Google Calendar testen</a>.</p>";
        } else {
            echo "<p style='color: red;'>❌ JSON ist beschädigt. Bitte geben Sie ihn neu ein.</p>";
        }
    } elseif (isset($settings['google_calendar_service_account_file']) && !empty($settings['google_calendar_service_account_file'])) {
        $file_path = $settings['google_calendar_service_account_file'];
        if (file_exists($file_path)) {
            $file_content = file_get_contents($file_path);
            $file_json = json_decode($file_content, true);
            if ($file_json) {
                echo "<p style='color: green;'>✅ Service Account Datei ist korrekt. Sie können jetzt <a href='test-google-calendar-service-account.php'>Google Calendar testen</a>.</p>";
            } else {
                echo "<p style='color: red;'>❌ Service Account Datei enthält ungültigen JSON.</p>";
            }
        } else {
            echo "<p style='color: red;'>❌ Service Account Datei existiert nicht.</p>";
        }
    } else {
        echo "<p style='color: orange;'>⚠️ Keine Google Calendar Konfiguration gefunden.</p>";
        echo "<ol>";
        echo "<li>Gehen Sie zu <a href='admin/settings.php'>Admin → Einstellungen</a></li>";
        echo "<li>Scrollen Sie zu Google Calendar Einstellungen</li>";
        echo "<li>Geben Sie den JSON-Inhalt ein</li>";
        echo "<li>Speichern Sie die Einstellungen</li>";
        echo "</ol>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<hr>";
echo "<p><em>JSON Status Check abgeschlossen!</em></p>";
?>
