<?php
/**
 * Umfassender Google Calendar Test
 * Testet sowohl Service Account als auch API Key Authentifizierung
 */

require_once 'config/database.php';
require_once 'includes/google_calendar.php';
require_once 'includes/google_calendar_service_account.php';

echo "<h1>Google Calendar Integration Test</h1>";
echo "<p>Zeitstempel: " . date('d.m.Y H:i:s') . "</p>";

// Einstellungen aus der Datenbank laden
$settings = [];
try {
    $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings");
    $stmt->execute();
    $settings_data = $stmt->fetchAll();
    
    foreach ($settings_data as $setting) {
        $settings[$setting['setting_key']] = $setting['setting_value'];
    }
} catch(PDOException $e) {
    echo "<div style='color: red;'>❌ Fehler beim Laden der Einstellungen: " . $e->getMessage() . "</div>";
    exit;
}

echo "<h2>1. Konfiguration prüfen</h2>";

// Service Account Konfiguration prüfen
echo "<h3>Service Account Konfiguration:</h3>";
$service_account_json = $settings['google_calendar_service_account_json'] ?? '';
$service_account_file = $settings['google_calendar_service_account_file'] ?? '';
$calendar_id = $settings['google_calendar_id'] ?? '';
$auth_type = $settings['google_calendar_auth_type'] ?? 'service_account';

echo "<p><strong>Authentifizierungstyp:</strong> " . htmlspecialchars($auth_type) . "</p>";
echo "<p><strong>Kalender ID:</strong> " . htmlspecialchars($calendar_id) . "</p>";

if ($auth_type === 'service_account') {
    echo "<p><strong>Service Account JSON:</strong> ";
    if (!empty($service_account_json)) {
        $json_data = json_decode($service_account_json, true);
        if ($json_data && isset($json_data['type']) && $json_data['type'] === 'service_account') {
            echo "✅ Gültig (Länge: " . strlen($service_account_json) . " Zeichen)";
            echo "<br><strong>Client Email:</strong> " . htmlspecialchars($json_data['client_email'] ?? 'Nicht gefunden');
            echo "<br><strong>Project ID:</strong> " . htmlspecialchars($json_data['project_id'] ?? 'Nicht gefunden');
        } else {
            echo "❌ Ungültig";
        }
    } else {
        echo "❌ Nicht konfiguriert";
    }
    echo "</p>";
    
    echo "<p><strong>Service Account Datei:</strong> ";
    if (!empty($service_account_file)) {
        if (file_exists($service_account_file)) {
            echo "✅ Datei gefunden";
        } else {
            echo "❌ Datei nicht gefunden";
        }
    } else {
        echo "❌ Nicht konfiguriert";
    }
    echo "</p>";
} else {
    $api_key = $settings['google_calendar_api_key'] ?? '';
    echo "<p><strong>API Key:</strong> ";
    if (!empty($api_key)) {
        echo "✅ Konfiguriert (Länge: " . strlen($api_key) . " Zeichen)";
    } else {
        echo "❌ Nicht konfiguriert";
    }
    echo "</p>";
}

echo "<h2>2. Service Account Test</h2>";

if ($auth_type === 'service_account' && (!empty($service_account_json) || !empty($service_account_file))) {
    try {
        // Service Account Klasse initialisieren
        if (!empty($service_account_json)) {
            $google_calendar = new GoogleCalendarServiceAccount($service_account_json, $calendar_id, true);
            echo "<p>✅ Service Account mit JSON-Inhalt initialisiert</p>";
        } else {
            $google_calendar = new GoogleCalendarServiceAccount($service_account_file, $calendar_id, false);
            echo "<p>✅ Service Account mit Datei initialisiert</p>";
        }
        
        // Test Event erstellen
        $test_title = "Feuerwehr App Test Event - " . date('d.m.Y H:i:s');
        $test_start = date('Y-m-d H:i:s', strtotime('+1 hour'));
        $test_end = date('Y-m-d H:i:s', strtotime('+2 hours'));
        $test_description = "Dies ist ein Test-Event der Feuerwehr App";
        
        echo "<p><strong>Test Event Details:</strong></p>";
        echo "<ul>";
        echo "<li><strong>Titel:</strong> " . htmlspecialchars($test_title) . "</li>";
        echo "<li><strong>Start:</strong> " . htmlspecialchars($test_start) . "</li>";
        echo "<li><strong>Ende:</strong> " . htmlspecialchars($test_end) . "</li>";
        echo "<li><strong>Beschreibung:</strong> " . htmlspecialchars($test_description) . "</li>";
        echo "</ul>";
        
        echo "<p><strong>Versuche Event zu erstellen...</strong></p>";
        
        $event_id = $google_calendar->createEvent($test_title, $test_start, $test_end, $test_description);
        
        if ($event_id) {
            echo "<p style='color: green;'>✅ Event erfolgreich erstellt! Event ID: " . htmlspecialchars($event_id) . "</p>";
            
            // Event abrufen zur Bestätigung
            echo "<p><strong>Event abrufen zur Bestätigung...</strong></p>";
            $event_data = $google_calendar->getEvent($event_id);
            
            if ($event_data) {
                echo "<p style='color: green;'>✅ Event erfolgreich abgerufen!</p>";
                echo "<p><strong>Event Details:</strong></p>";
                echo "<ul>";
                echo "<li><strong>ID:</strong> " . htmlspecialchars($event_data['id'] ?? 'N/A') . "</li>";
                echo "<li><strong>Titel:</strong> " . htmlspecialchars($event_data['summary'] ?? 'N/A') . "</li>";
                echo "<li><strong>Start:</strong> " . htmlspecialchars($event_data['start']['dateTime'] ?? 'N/A') . "</li>";
                echo "<li><strong>Ende:</strong> " . htmlspecialchars($event_data['end']['dateTime'] ?? 'N/A') . "</li>";
                echo "<li><strong>Beschreibung:</strong> " . htmlspecialchars($event_data['description'] ?? 'N/A') . "</li>";
                echo "</ul>";
                
                // Event löschen
                echo "<p><strong>Test Event löschen...</strong></p>";
                if ($google_calendar->deleteEvent($event_id)) {
                    echo "<p style='color: green;'>✅ Test Event erfolgreich gelöscht!</p>";
                } else {
                    echo "<p style='color: orange;'>⚠️ Test Event konnte nicht gelöscht werden (muss manuell entfernt werden)</p>";
                }
            } else {
                echo "<p style='color: red;'>❌ Event konnte nicht abgerufen werden</p>";
            }
        } else {
            echo "<p style='color: red;'>❌ Event konnte nicht erstellt werden</p>";
        }
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Fehler beim Service Account Test: " . htmlspecialchars($e->getMessage()) . "</p>";
        
        // Detaillierte Fehleranalyse
        echo "<h3>Fehleranalyse:</h3>";
        $error_message = $e->getMessage();
        
        if (strpos($error_message, 'HTTP 401') !== false) {
            echo "<p style='color: red;'>🔍 Authentifizierungsfehler (401): Service Account ist möglicherweise nicht korrekt konfiguriert oder der Kalender ist nicht freigegeben</p>";
        } elseif (strpos($error_message, 'HTTP 403') !== false) {
            echo "<p style='color: red;'>🔍 Berechtigungsfehler (403): Service Account hat keine Berechtigung für diesen Kalender</p>";
        } elseif (strpos($error_message, 'HTTP 404') !== false) {
            echo "<p style='color: red;'>🔍 Kalender nicht gefunden (404): Kalender ID ist möglicherweise falsch</p>";
        } elseif (strpos($error_message, 'JWT') !== false) {
            echo "<p style='color: red;'>🔍 JWT-Fehler: Private Key ist möglicherweise beschädigt oder falsch formatiert</p>";
        } elseif (strpos($error_message, 'JSON') !== false) {
            echo "<p style='color: red;'>🔍 JSON-Fehler: Service Account JSON ist möglicherweise beschädigt</p>";
        }
    }
} else {
    echo "<p style='color: orange;'>⚠️ Service Account nicht konfiguriert - Test übersprungen</p>";
}

echo "<h2>3. API Key Test</h2>";

if ($auth_type === 'api_key' && !empty($settings['google_calendar_api_key'])) {
    try {
        $google_calendar = new GoogleCalendar($settings['google_calendar_api_key'], $calendar_id);
        
        // Verbindung testen
        echo "<p><strong>Verbindung testen...</strong></p>";
        if ($google_calendar->testConnection()) {
            echo "<p style='color: green;'>✅ Verbindung erfolgreich!</p>";
            
            // Test Event erstellen
            $test_title = "Feuerwehr App Test Event (API Key) - " . date('d.m.Y H:i:s');
            $test_start = date('Y-m-d H:i:s', strtotime('+1 hour'));
            $test_end = date('Y-m-d H:i:s', strtotime('+2 hours'));
            $test_description = "Dies ist ein Test-Event der Feuerwehr App (API Key)";
            
            echo "<p><strong>Test Event Details:</strong></p>";
            echo "<ul>";
            echo "<li><strong>Titel:</strong> " . htmlspecialchars($test_title) . "</li>";
            echo "<li><strong>Start:</strong> " . htmlspecialchars($test_start) . "</li>";
            echo "<li><strong>Ende:</strong> " . htmlspecialchars($test_end) . "</li>";
            echo "<li><strong>Beschreibung:</strong> " . htmlspecialchars($test_description) . "</li>";
            echo "</ul>";
            
            echo "<p><strong>Versuche Event zu erstellen...</strong></p>";
            
            $event_id = $google_calendar->createEvent($test_title, $test_start, $test_end, $test_description);
            
            if ($event_id) {
                echo "<p style='color: green;'>✅ Event erfolgreich erstellt! Event ID: " . htmlspecialchars($event_id) . "</p>";
                
                // Event löschen
                echo "<p><strong>Test Event löschen...</strong></p>";
                if ($google_calendar->deleteEvent($event_id)) {
                    echo "<p style='color: green;'>✅ Test Event erfolgreich gelöscht!</p>";
                } else {
                    echo "<p style='color: orange;'>⚠️ Test Event konnte nicht gelöscht werden (muss manuell entfernt werden)</p>";
                }
            } else {
                echo "<p style='color: red;'>❌ Event konnte nicht erstellt werden</p>";
            }
        } else {
            echo "<p style='color: red;'>❌ Verbindung fehlgeschlagen</p>";
        }
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Fehler beim API Key Test: " . htmlspecialchars($e->getMessage()) . "</p>";
        
        // Detaillierte Fehleranalyse
        echo "<h3>Fehleranalyse:</h3>";
        $error_message = $e->getMessage();
        
        if (strpos($error_message, 'HTTP 401') !== false) {
            echo "<p style='color: red;'>🔍 Authentifizierungsfehler (401): API Key ist möglicherweise ungültig oder abgelaufen</p>";
        } elseif (strpos($error_message, 'HTTP 403') !== false) {
            echo "<p style='color: red;'>🔍 Berechtigungsfehler (403): API Key hat keine Berechtigung für Google Calendar API</p>";
        } elseif (strpos($error_message, 'HTTP 404') !== false) {
            echo "<p style='color: red;'>🔍 Kalender nicht gefunden (404): Kalender ID ist möglicherweise falsch</p>";
        }
    }
} else {
    echo "<p style='color: orange;'>⚠️ API Key nicht konfiguriert - Test übersprungen</p>";
}

echo "<h2>4. Zusammenfassung</h2>";

if ($auth_type === 'service_account') {
    if (!empty($service_account_json) || !empty($service_account_file)) {
        echo "<p style='color: green;'>✅ Service Account ist konfiguriert</p>";
    } else {
        echo "<p style='color: red;'>❌ Service Account ist nicht konfiguriert</p>";
    }
} else {
    if (!empty($settings['google_calendar_api_key'])) {
        echo "<p style='color: green;'>✅ API Key ist konfiguriert</p>";
    } else {
        echo "<p style='color: red;'>❌ API Key ist nicht konfiguriert</p>";
    }
}

if (!empty($calendar_id)) {
    echo "<p style='color: green;'>✅ Kalender ID ist konfiguriert: " . htmlspecialchars($calendar_id) . "</p>";
} else {
    echo "<p style='color: red;'>❌ Kalender ID ist nicht konfiguriert</p>";
}

echo "<h2>5. Nächste Schritte</h2>";

if ($auth_type === 'service_account') {
    if (empty($service_account_json) && empty($service_account_file)) {
        echo "<p style='color: red;'>❌ Service Account JSON oder Datei muss konfiguriert werden</p>";
    } elseif (empty($calendar_id)) {
        echo "<p style='color: red;'>❌ Kalender ID muss konfiguriert werden</p>";
    } else {
        echo "<p style='color: green;'>✅ Konfiguration scheint korrekt zu sein</p>";
        echo "<p><strong>Hinweise:</strong></p>";
        echo "<ul>";
        echo "<li>Stellen Sie sicher, dass der Service Account Zugriff auf den Kalender hat</li>";
        echo "<li>Der Kalender muss für den Service Account freigegeben werden</li>";
        echo "<li>Die Google Calendar API muss in der Google Cloud Console aktiviert sein</li>";
        echo "</ul>";
    }
} else {
    if (empty($settings['google_calendar_api_key'])) {
        echo "<p style='color: red;'>❌ API Key muss konfiguriert werden</p>";
    } elseif (empty($calendar_id)) {
        echo "<p style='color: red;'>❌ Kalender ID muss konfiguriert werden</p>";
    } else {
        echo "<p style='color: green;'>✅ Konfiguration scheint korrekt zu sein</p>";
        echo "<p><strong>Hinweise:</strong></p>";
        echo "<ul>";
        echo "<li>Der API Key muss für die Google Calendar API aktiviert sein</li>";
        echo "<li>Die Google Calendar API muss in der Google Cloud Console aktiviert sein</li>";
        echo "<li>Der Kalender muss öffentlich zugänglich sein oder der API Key muss Berechtigung haben</li>";
        echo "</ul>";
    }
}

echo "<hr>";
echo "<p><strong>Test abgeschlossen um:</strong> " . date('d.m.Y H:i:s') . "</p>";
?>
