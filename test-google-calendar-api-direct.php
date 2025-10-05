<?php
/**
 * Direkter Test der Google Calendar API
 */

require_once 'config/database.php';
require_once 'includes/functions.php';

echo "<h1>🧪 Direkter Google Calendar API Test</h1>";

// 1. Lade Google Calendar Einstellungen
echo "<h2>1. Google Calendar Einstellungen laden</h2>";

try {
    $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'google_calendar_%'");
    $stmt->execute();
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    $auth_type = $settings['google_calendar_auth_type'] ?? 'service_account';
    $calendar_id = $settings['google_calendar_id'] ?? 'primary';
    $service_account_json = $settings['google_calendar_service_account'] ?? '';
    
    echo "<p><strong>Auth Type:</strong> $auth_type</p>";
    echo "<p><strong>Calendar ID:</strong> $calendar_id</p>";
    echo "<p><strong>Service Account JSON:</strong> " . (empty($service_account_json) ? 'Nicht gefunden' : 'Gefunden') . "</p>";
    
    if (empty($service_account_json)) {
        echo "<p style='color: red;'>❌ Keine Service Account JSON - das ist das Problem!</p>";
        echo "<p>Gehen Sie zu den Einstellungen und konfigurieren Sie die Google Calendar Integration.</p>";
        exit;
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim Laden der Einstellungen: " . $e->getMessage() . "</p>";
    exit;
}

// 2. Teste Service Account JSON
echo "<h2>2. Service Account JSON validieren</h2>";

$service_account_data = json_decode($service_account_json, true);
if ($service_account_data) {
    echo "<p style='color: green;'>✅ Service Account JSON ist gültig</p>";
    echo "<p><strong>Client Email:</strong> " . ($service_account_data['client_email'] ?? 'Nicht gefunden') . "</p>";
    echo "<p><strong>Project ID:</strong> " . ($service_account_data['project_id'] ?? 'Nicht gefunden') . "</p>";
    echo "<p><strong>Private Key:</strong> " . (isset($service_account_data['private_key']) ? 'Vorhanden' : 'Nicht gefunden') . "</p>";
} else {
    echo "<p style='color: red;'>❌ Service Account JSON ist ungültig</p>";
    exit;
}

// 3. Teste Google Calendar Service Account Klasse
echo "<h2>3. Google Calendar Service Account Klasse testen</h2>";

try {
    if (!class_exists('GoogleCalendarServiceAccount')) {
        echo "<p style='color: red;'>❌ GoogleCalendarServiceAccount Klasse nicht verfügbar</p>";
        exit;
    }
    
    echo "<p style='color: green;'>✅ GoogleCalendarServiceAccount Klasse verfügbar</p>";
    
    // Erstelle Instanz
    $calendar_service = new GoogleCalendarServiceAccount($service_account_json, $calendar_id, true);
    echo "<p style='color: green;'>✅ GoogleCalendarServiceAccount Instanz erstellt</p>";
    
    // Teste Methoden
    $methods = ['createEvent', 'deleteEvent', 'getEvent', 'listEvents'];
    foreach ($methods as $method) {
        if (method_exists($calendar_service, $method)) {
            echo "<p style='color: green;'>✅ Methode $method verfügbar</p>";
        } else {
            echo "<p style='color: red;'>❌ Methode $method NICHT verfügbar</p>";
        }
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim Erstellen der Service Account Instanz: " . $e->getMessage() . "</p>";
    exit;
}

// 4. Teste Event löschen direkt
echo "<h2>4. Event löschen direkt testen</h2>";

try {
    // Hole eine Event ID aus der Datenbank
    $stmt = $db->prepare("SELECT google_event_id FROM calendar_events WHERE google_event_id IS NOT NULL LIMIT 1");
    $stmt->execute();
    $event = $stmt->fetch();
    
    if ($event && !empty($event['google_event_id'])) {
        $event_id = $event['google_event_id'];
        echo "<p><strong>Teste Löschen von Event:</strong> $event_id</p>";
        
        // Teste deleteEvent direkt
        $result = $calendar_service->deleteEvent($event_id);
        
        if ($result) {
            echo "<p style='color: green;'>✅ Event erfolgreich gelöscht!</p>";
        } else {
            echo "<p style='color: red;'>❌ Event konnte nicht gelöscht werden</p>";
        }
        
    } else {
        echo "<p style='color: orange;'>⚠️ Keine Event ID für Test gefunden</p>";
        
        // Erstelle ein Test-Event
        echo "<p>Erstelle Test-Event...</p>";
        $test_event_id = $calendar_service->createEvent(
            'Test Event - ' . date('Y-m-d H:i:s'),
            date('Y-m-d H:i:s'),
            date('Y-m-d H:i:s', strtotime('+1 hour')),
            'Test Event für Lösch-Test'
        );
        
        if ($test_event_id) {
            echo "<p style='color: green;'>✅ Test-Event erstellt: $test_event_id</p>";
            
            // Lösche Test-Event
            echo "<p>Lösche Test-Event...</p>";
            $delete_result = $calendar_service->deleteEvent($test_event_id);
            
            if ($delete_result) {
                echo "<p style='color: green;'>✅ Test-Event erfolgreich gelöscht!</p>";
            } else {
                echo "<p style='color: red;'>❌ Test-Event konnte nicht gelöscht werden</p>";
            }
        } else {
            echo "<p style='color: red;'>❌ Test-Event konnte nicht erstellt werden</p>";
        }
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim Event-Löschen: " . $e->getMessage() . "</p>";
    echo "<p><strong>Stack Trace:</strong></p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}

// 5. Teste delete_google_calendar_event Funktion
echo "<h2>5. delete_google_calendar_event Funktion testen</h2>";

if (function_exists('delete_google_calendar_event')) {
    echo "<p style='color: green;'>✅ delete_google_calendar_event Funktion verfügbar</p>";
    
    // Teste mit einer Event ID
    $stmt = $db->prepare("SELECT google_event_id FROM calendar_events WHERE google_event_id IS NOT NULL LIMIT 1");
    $stmt->execute();
    $event = $stmt->fetch();
    
    if ($event && !empty($event['google_event_id'])) {
        $event_id = $event['google_event_id'];
        echo "<p><strong>Teste delete_google_calendar_event mit:</strong> $event_id</p>";
        
        $result = delete_google_calendar_event($event_id);
        
        if ($result) {
            echo "<p style='color: green;'>✅ delete_google_calendar_event erfolgreich!</p>";
        } else {
            echo "<p style='color: red;'>❌ delete_google_calendar_event schlägt fehl</p>";
        }
    } else {
        echo "<p style='color: orange;'>⚠️ Keine Event ID für Test verfügbar</p>";
    }
} else {
    echo "<p style='color: red;'>❌ delete_google_calendar_event Funktion nicht verfügbar</p>";
}

echo "<hr>";
echo "<p><a href='debug-google-calendar-delete-detailed.php'>→ Detailliertes Debug</a></p>";
echo "<p><a href='fix-google-calendar-simple.php'>→ Zurück zum Fix-Skript</a></p>";
echo "<p><small>API Test abgeschlossen: " . date('Y-m-d H:i:s') . "</small></p>";
?>
