<?php
/**
 * Insert JSON Directly - Browser Version
 * Öffnen Sie diese Datei in Ihrem Browser: http://ihre-domain/insert-json-directly.php
 */

require_once 'config/database.php';

echo "<h1>📝 JSON direkt in Datenbank einfügen</h1>";
echo "<p>Diese Seite fügt den Google Service Account JSON direkt in die Datenbank ein.</p>";

// Der ursprüngliche JSON-Inhalt (ohne Double-Encoding)
$json_content = '{
  "type": "service_account",
  "project_id": "lz-amern",
  "private_key_id": "fe13345562fcbfa3cc64d4cdefd962b491c5a3c7",
  "private_key": "-----BEGIN PRIVATE KEY-----\\nMIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQDL4VavOYmfsaLBnIMyHFV1NFKAWeVLhMShGzWAGOw+hg8esAKxhcKq35Q+xDP+pFVThjPlLElB85/RwnlBAtmmbTmDk5VumxLiKVBmVlQq719W6yXu89QTqSvH7Ac3nkppiqwaV/puNJhJQznxvTDXkBvHpFwn6VrgMHC2w+vKKdPLjFcWs+mdkmxE8piG9Q4asgjNwJaiIBrnjMCn0y5gB8/tWWsCC94os6Vn3txlQ9yJ+cPR+mHHBbUvbPXZ0NojhJkQDWs0XcCtdhtknj1pOppL448Dm3RJHRwl+OrA/Jle4g8+Stvlzay5Jjzy23qkvBTW6rAM2r43RYC0MnaB4EPxG1AgMBAAECggEADxcc7ifFVLVWbVKC6O2vI/Ummzs8I/BaQZFSbeuhrsv8n87FyEN1AuY9B/9INO0PZrj8btY+DtxcJC+sdnm569WbjN2gEMInQY/Te/OV4YzqZnCKlHrmI9Vl6OyCpT55VgH+Vo3T+qO4cNXB65/5rijIb371zVptUXIlfJ+6Y36f+IngcVvYQx6qD2kIR5zTqFMffHd7Dc3nvk30LePj1xdOQwDVXYNklPTcb2MULt/LJ0knoeJ60fQxSTXj+QKyMmTHkcCgn/uJuGYd9y3ecjpwBS2tm35bsou74uEpISUSMcLPn/TRidnF10mO812RKBuLav457Ra9tLotM1U5+XCONcQKBgQD+j7y/AaS3LqxqjcpHnvKUM4XAZeI653brdh0642LdIOFZU+0yvhFAvBwjT9WBEwbKKSZ8/bjNTLzSpgmtenjGfYSjp5BQEiTxzOZ89iSHmsGRma9s1OxKbyMNe1148QUcmTaeZ8WolhQiA0QSUpnFIX+Qy4zSA7oDiLt4GnQN8T50QKBgQDNCEh0mebRE35uSg5eoXLJ4ETP8SdQ7voNnOSixzVn49Ffc29wP0IA8rZkj9TI/DEaUFJm9xhsOFHnw6dANOBdwFzuXwwgkF2alnmDwaYDCcAZhQLOMQ7G4rTFQidU1L3qH4ti4Jjf6djWaaapvHa42NIDcdka3+VZzUnUpFuyh6upQKBgBrJJSM0GRDtaFcN9Gr3/qYMUq9bcCk+m5sT0cTBiQegZfUrPDZ7nxbQtGVC0URzrBM5oUMlr3xqxrOjpQEMCoyqvJNf3HtdtW6qcYcYFukfRnFAiCBhxnuN9jJE+ODw+4i21nh0kufaYuPxVAhZh9AFxw1TuwKWFhm2tMYdX3CFMBAoGALb9+nYz3/yYDfAf7WK/k8Ip0+3WMCkcVw18h8MwgN3kWu4SHRfVnZczCM3gAE4Rp9GQdrnsnNkkASznLSe7oQofqNAccFbrKnoBmTsbDowPm8ArEsHszv97P1P/IxN3fLkExmbnNhiPylnFngjRj3KJGAcrJRbfStORdbKirqS8qd0CgYEAiP6Ql6sPAe/kB0dncuKQn5zEuJZey2A16es9oNA4efmGJigpgva8UL8lyjR/jvwGgxOYSVBN0wR2Hu8oessyQne4n/KVWDebacqPh11odfcuilzwz/OHQ95S8yun2lvruXJu29Zu+XVylwm8c4mcepn0KJyOD8Az9cVgakeFG2qxA0=\\n-----END PRIVATE KEY-----\\n",
  "client_email": "fahrzeugreservierung@lz-amern.iam.gserviceaccount.com",
  "client_id": "116849970899070319393",
  "auth_uri": "https://accounts.google.com/o/oauth2/auth",
  "token_uri": "https://oauth2.googleapis.com/token",
  "auth_provider_x509_cert_url": "https://www.googleapis.com/oauth2/v1/certs",
  "client_x509_cert_url": "https://www.googleapis.com/robot/v1/metadata/x509/fahrzeugreservierung%40lz-amern.iam.gserviceaccount.com",
  "universe_domain": "googleapis.com"
}';

try {
    echo "<h2>1. JSON-Validierung:</h2>";
    
    $json_data = json_decode($json_content, true);
    
    if (!$json_data) {
        echo "<p style='color: red;'>❌ JSON ist ungültig!</p>";
        echo "<p><strong>Fehler:</strong> " . json_last_error_msg() . "</p>";
        exit;
    }
    
    echo "<p style='color: green;'>✅ JSON ist gültig!</p>";
    echo "<p><strong>Type:</strong> " . htmlspecialchars($json_data['type'] ?? 'NICHT GEFUNDEN') . "</p>";
    echo "<p><strong>Project ID:</strong> " . htmlspecialchars($json_data['project_id'] ?? 'NICHT GEFUNDEN') . "</p>";
    echo "<p><strong>Client Email:</strong> " . htmlspecialchars($json_data['client_email'] ?? 'NICHT GEFUNDEN') . "</p>";
    
    echo "<h2>2. JSON in Datenbank speichern:</h2>";
    
    // Zuerst prüfen ob bereits ein Eintrag existiert
    $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'google_calendar_service_account_json'");
    $stmt->execute();
    $existing = $stmt->fetchColumn();
    
    if ($existing) {
        echo "<p style='color: orange;'>⚠️ Eintrag existiert bereits. Wird überschrieben...</p>";
    }
    
    // JSON in Datenbank speichern (INSERT oder UPDATE)
    $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('google_calendar_service_account_json', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
    $result = $stmt->execute([$json_content, $json_content]);
    
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
echo "<p><em>JSON Insert abgeschlossen!</em></p>";
?>
