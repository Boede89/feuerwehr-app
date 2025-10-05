<?php
/**
 * Debug: Warum das Löschen fehlschlägt
 */

require_once 'config/database.php';
require_once 'includes/functions.php';

echo "<h1>🔍 Debug: Lösch-Fehler analysieren</h1>";

// 1. Prüfe die Reservierung ID 159
echo "<h2>1. Reservierung ID 159 prüfen</h2>";

try {
    $stmt = $db->prepare("
        SELECT r.*, v.name as vehicle_name, ce.google_event_id, ce.id as calendar_event_id
        FROM reservations r 
        JOIN vehicles v ON r.vehicle_id = v.id 
        LEFT JOIN calendar_events ce ON r.id = ce.reservation_id
        WHERE r.id = 159
    ");
    $stmt->execute();
    $reservation = $stmt->fetch();
    
    if ($reservation) {
        echo "<p style='color: orange;'>⚠️ Reservierung ID 159 existiert noch:</p>";
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>Feld</th><th>Wert</th></tr>";
        echo "<tr><td>ID</td><td>" . $reservation['id'] . "</td></tr>";
        echo "<tr><td>Fahrzeug</td><td>" . htmlspecialchars($reservation['vehicle_name']) . "</td></tr>";
        echo "<tr><td>Status</td><td>" . $reservation['status'] . "</td></tr>";
        echo "<tr><td>Antragsteller</td><td>" . htmlspecialchars($reservation['requester_name']) . "</td></tr>";
        echo "<tr><td>Grund</td><td>" . htmlspecialchars($reservation['reason']) . "</td></tr>";
        echo "<tr><td>Google Event ID</td><td>" . ($reservation['google_event_id'] ?: 'Keine') . "</td></tr>";
        echo "<tr><td>Calendar Event ID</td><td>" . ($reservation['calendar_event_id'] ?: 'Keine') . "</td></tr>";
        echo "</table>";
    } else {
        echo "<p style='color: green;'>✅ Reservierung ID 159 wurde gelöscht</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim Laden der Reservierung: " . $e->getMessage() . "</p>";
}

// 2. Teste Google Calendar Event löschen direkt
echo "<h2>2. Google Calendar Event direkt löschen testen</h2>";

$google_event_id = '1qcoeid24vdq4lgm0uf9dp231k';
echo "<p><strong>Teste Löschen von Google Event ID:</strong> $google_event_id</p>";

try {
    if (function_exists('delete_google_calendar_event')) {
        echo "<p>Starte delete_google_calendar_event...</p>";
        $result = delete_google_calendar_event($google_event_id);
        
        if ($result) {
            echo "<p style='color: green;'>✅ Google Calendar Event erfolgreich gelöscht!</p>";
        } else {
            echo "<p style='color: red;'>❌ Google Calendar Event konnte nicht gelöscht werden</p>";
        }
    } else {
        echo "<p style='color: red;'>❌ delete_google_calendar_event Funktion nicht verfügbar</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Exception beim Löschen: " . $e->getMessage() . "</p>";
    echo "<p><strong>Stack Trace:</strong></p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}

// 3. Teste Google Calendar Service Account direkt
echo "<h2>3. Google Calendar Service Account direkt testen</h2>";

try {
    if (class_exists('GoogleCalendarServiceAccount')) {
        // Lade Service Account JSON
        $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'google_calendar_service_account'");
        $stmt->execute();
        $service_account_json = $stmt->fetchColumn();
        
        if ($service_account_json) {
            $calendar_service = new GoogleCalendarServiceAccount($service_account_json, 'a3f7e2f57f274ba2fe7d3a62a932a33c78ed468aafa6ac477b58f16495e5677a@group.calendar.google.com', true);
            
            echo "<p style='color: green;'>✅ GoogleCalendarServiceAccount Instanz erstellt</p>";
            
            // Teste deleteEvent direkt
            echo "<p>Teste deleteEvent direkt...</p>";
            $result = $calendar_service->deleteEvent($google_event_id);
            
            if ($result) {
                echo "<p style='color: green;'>✅ deleteEvent erfolgreich!</p>";
            } else {
                echo "<p style='color: red;'>❌ deleteEvent schlägt fehl</p>";
            }
            
        } else {
            echo "<p style='color: red;'>❌ Service Account JSON nicht gefunden</p>";
        }
    } else {
        echo "<p style='color: red;'>❌ GoogleCalendarServiceAccount Klasse nicht verfügbar</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim Service Account Test: " . $e->getMessage() . "</p>";
    echo "<p><strong>Stack Trace:</strong></p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}

// 4. Teste manuelles Löschen aus Datenbank
echo "<h2>4. Manuelles Löschen aus Datenbank testen</h2>";

try {
    echo "<p>Lösche Calendar Event aus Datenbank...</p>";
    $stmt = $db->prepare("DELETE FROM calendar_events WHERE google_event_id = ?");
    $stmt->execute([$google_event_id]);
    $deleted_calendar_events = $stmt->rowCount();
    echo "<p style='color: green;'>✅ $deleted_calendar_events Calendar Event(s) aus Datenbank gelöscht</p>";
    
    echo "<p>Lösche Reservierung aus Datenbank...</p>";
    $stmt = $db->prepare("DELETE FROM reservations WHERE id = 159");
    $stmt->execute();
    $deleted_reservations = $stmt->rowCount();
    echo "<p style='color: green;'>✅ $deleted_reservations Reservierung(en) aus Datenbank gelöscht</p>";
    
    if ($deleted_reservations > 0) {
        echo "<p style='color: green; font-weight: bold;'>✅ Reservierung ID 159 erfolgreich gelöscht!</p>";
    } else {
        echo "<p style='color: orange;'>⚠️ Reservierung ID 159 war bereits gelöscht</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim manuellen Löschen: " . $e->getMessage() . "</p>";
}

// 5. Prüfe Google Calendar Event Status
echo "<h2>5. Google Calendar Event Status prüfen</h2>";

try {
    if (class_exists('GoogleCalendarServiceAccount')) {
        $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'google_calendar_service_account'");
        $stmt->execute();
        $service_account_json = $stmt->fetchColumn();
        
        if ($service_account_json) {
            $calendar_service = new GoogleCalendarServiceAccount($service_account_json, 'a3f7e2f57f274ba2fe7d3a62a932a33c78ed468aafa6ac477b58f16495e5677a@group.calendar.google.com', true);
            
            // Versuche Event abzurufen
            try {
                $event = $calendar_service->getEvent($google_event_id);
                echo "<p style='color: orange;'>⚠️ Google Calendar Event existiert noch:</p>";
                echo "<pre>" . print_r($event, true) . "</pre>";
            } catch (Exception $e) {
                if (strpos($e->getMessage(), '404') !== false) {
                    echo "<p style='color: green;'>✅ Google Calendar Event wurde gelöscht (404 Not Found)</p>";
                } else {
                    echo "<p style='color: red;'>❌ Fehler beim Abrufen des Events: " . $e->getMessage() . "</p>";
                }
            }
        }
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim Event-Status-Check: " . $e->getMessage() . "</p>";
}

echo "<hr>";
echo "<p><a href='fix-google-calendar-simple.php'>→ Zurück zum Fix-Skript</a></p>";
echo "<p><a href='admin/reservations.php'>→ Zur Reservierungen-Übersicht</a></p>";
echo "<p><small>Debug abgeschlossen: " . date('Y-m-d H:i:s') . "</small></p>";
?>
