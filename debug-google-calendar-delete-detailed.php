<?php
/**
 * Detailliertes Debug: Google Calendar Lösch-Funktionalität
 */

require_once 'config/database.php';
require_once 'includes/functions.php';

echo "<h1>🔍 Detailliertes Debug: Google Calendar Löschen</h1>";

// 1. Teste Google Calendar Service direkt
echo "<h2>1. Google Calendar Service Test</h2>";

if (class_exists('GoogleCalendarServiceAccount')) {
    echo "<p style='color: green;'>✅ GoogleCalendarServiceAccount Klasse verfügbar</p>";
    
    try {
        // Teste Service Account Erstellung
        $calendar_service = new GoogleCalendarServiceAccount();
        echo "<p style='color: green;'>✅ GoogleCalendarServiceAccount Instanz erstellt</p>";
        
        // Prüfe ob deleteEvent Methode existiert
        if (method_exists($calendar_service, 'deleteEvent')) {
            echo "<p style='color: green;'>✅ deleteEvent Methode verfügbar</p>";
        } else {
            echo "<p style='color: red;'>❌ deleteEvent Methode NICHT verfügbar</p>";
        }
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Fehler beim Erstellen der Service Account Instanz: " . $e->getMessage() . "</p>";
    }
} else {
    echo "<p style='color: red;'>❌ GoogleCalendarServiceAccount Klasse NICHT verfügbar</p>";
}

// 2. Teste delete_google_calendar_event Funktion direkt
echo "<h2>2. delete_google_calendar_event Funktion Test</h2>";

if (function_exists('delete_google_calendar_event')) {
    echo "<p style='color: green;'>✅ delete_google_calendar_event Funktion verfügbar</p>";
    
    // Teste mit einer echten Event ID aus der Datenbank
    try {
        $stmt = $db->prepare("SELECT google_event_id FROM calendar_events WHERE google_event_id IS NOT NULL LIMIT 1");
        $stmt->execute();
        $event = $stmt->fetch();
        
        if ($event && !empty($event['google_event_id'])) {
            $test_event_id = $event['google_event_id'];
            echo "<p><strong>Teste mit echter Event ID:</strong> $test_event_id</p>";
            
            echo "<p>Starte Lösch-Test...</p>";
            $result = delete_google_calendar_event($test_event_id);
            
            if ($result) {
                echo "<p style='color: green;'>✅ Lösch-Funktion erfolgreich (auch wenn Event nicht existiert)</p>";
            } else {
                echo "<p style='color: red;'>❌ Lösch-Funktion schlägt fehl</p>";
            }
        } else {
            echo "<p style='color: orange;'>⚠️ Keine Google Event IDs in der Datenbank gefunden</p>";
        }
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Exception beim Testen: " . $e->getMessage() . "</p>";
    }
} else {
    echo "<p style='color: red;'>❌ delete_google_calendar_event Funktion NICHT verfügbar</p>";
}

// 3. Prüfe Google Calendar Einstellungen
echo "<h2>3. Google Calendar Einstellungen</h2>";

try {
    $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'google_calendar_%'");
    $stmt->execute();
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    if (!empty($settings)) {
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>Einstellung</th><th>Wert</th></tr>";
        foreach ($settings as $key => $value) {
            if (strpos($key, 'service_account') !== false || strpos($key, 'credentials') !== false) {
                $display_value = strlen($value) > 50 ? substr($value, 0, 50) . '...' : $value;
            } else {
                $display_value = $value;
            }
            echo "<tr><td>$key</td><td>" . htmlspecialchars($display_value) . "</td></tr>";
        }
        echo "</table>";
    } else {
        echo "<p style='color: red;'>❌ Keine Google Calendar Einstellungen gefunden</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim Laden der Einstellungen: " . $e->getMessage() . "</p>";
}

// 4. Teste Google Calendar Service Account direkt
echo "<h2>4. Google Calendar Service Account Direkt-Test</h2>";

try {
    if (class_exists('GoogleCalendarServiceAccount')) {
        // Lade Einstellungen
        $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'google_calendar_service_account'");
        $stmt->execute();
        $service_account_json = $stmt->fetchColumn();
        
        if ($service_account_json) {
            echo "<p style='color: green;'>✅ Service Account JSON gefunden</p>";
            
            // Teste Service Account Erstellung mit echten Daten
            $service_account_data = json_decode($service_account_json, true);
            if ($service_account_data) {
                echo "<p style='color: green;'>✅ Service Account JSON ist gültig</p>";
                echo "<p><strong>Client Email:</strong> " . ($service_account_data['client_email'] ?? 'Nicht gefunden') . "</p>";
                echo "<p><strong>Project ID:</strong> " . ($service_account_data['project_id'] ?? 'Nicht gefunden') . "</p>";
            } else {
                echo "<p style='color: red;'>❌ Service Account JSON ist ungültig</p>";
            }
        } else {
            echo "<p style='color: red;'>❌ Keine Service Account JSON gefunden</p>";
        }
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim Service Account Test: " . $e->getMessage() . "</p>";
}

// 5. Teste manuelles Löschen mit cURL
echo "<h2>5. Manueller cURL Test</h2>";

try {
    $stmt = $db->prepare("SELECT google_event_id FROM calendar_events WHERE google_event_id IS NOT NULL LIMIT 1");
    $stmt->execute();
    $event = $stmt->fetch();
    
    if ($event && !empty($event['google_event_id'])) {
        $event_id = $event['google_event_id'];
        echo "<p><strong>Teste manuelles Löschen von Event:</strong> $event_id</p>";
        
        // Lade Service Account Daten
        $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'google_calendar_service_account'");
        $stmt->execute();
        $service_account_json = $stmt->fetchColumn();
        
        if ($service_account_json) {
            $service_account = json_decode($service_account_json, true);
            $calendar_id = 'primary'; // oder aus Einstellungen laden
            
            echo "<p>Teste cURL DELETE Request...</p>";
            
            // Hier würde der cURL Test stehen, aber das ist komplex
            echo "<p style='color: orange;'>⚠️ cURL Test würde hier stehen (zu komplex für Debug-Skript)</p>";
        } else {
            echo "<p style='color: red;'>❌ Keine Service Account Daten für cURL Test</p>";
        }
    } else {
        echo "<p style='color: orange;'>⚠️ Keine Event ID für cURL Test gefunden</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim cURL Test: " . $e->getMessage() . "</p>";
}

// 6. Zeige alle Calendar Events
echo "<h2>6. Alle Calendar Events in der Datenbank</h2>";

try {
    $stmt = $db->prepare("SELECT * FROM calendar_events ORDER BY id DESC LIMIT 10");
    $stmt->execute();
    $events = $stmt->fetchAll();
    
    if (!empty($events)) {
        echo "<table border='1' cellpadding='5' style='width: 100%;'>";
        echo "<tr><th>ID</th><th>Reservation ID</th><th>Google Event ID</th><th>Titel</th><th>Erstellt</th></tr>";
        foreach ($events as $event) {
            echo "<tr>";
            echo "<td>" . $event['id'] . "</td>";
            echo "<td>" . $event['reservation_id'] . "</td>";
            echo "<td>" . htmlspecialchars($event['google_event_id']) . "</td>";
            echo "<td>" . htmlspecialchars($event['title'] ?? 'Kein Titel') . "</td>";
            echo "<td>" . $event['created_at'] . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p style='color: orange;'>⚠️ Keine Calendar Events in der Datenbank</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim Laden der Calendar Events: " . $e->getMessage() . "</p>";
}

echo "<hr>";
echo "<p><a href='fix-google-calendar-simple.php'>→ Zurück zum Fix-Skript</a></p>";
echo "<p><a href='admin/reservations.php'>→ Zur Reservierungen-Übersicht</a></p>";
echo "<p><small>Debug abgeschlossen: " . date('Y-m-d H:i:s') . "</small></p>";
?>
