<?php
/**
 * Debug: Reservierungen-Löschen Problem
 */

require_once 'config/database.php';
require_once 'includes/functions.php';

echo "<h1>🔍 Debug: Reservierungen-Löschen Problem</h1>";

// 1. Zeige alle Reservierungen mit Google Event IDs
echo "<h2>1. Alle Reservierungen mit Google Event IDs</h2>";

try {
    $stmt = $db->prepare("
        SELECT r.*, v.name as vehicle_name, ce.google_event_id, ce.id as calendar_event_id
        FROM reservations r 
        JOIN vehicles v ON r.vehicle_id = v.id 
        LEFT JOIN calendar_events ce ON r.id = ce.reservation_id
        WHERE r.status IN ('approved', 'rejected')
        ORDER BY r.id DESC
    ");
    $stmt->execute();
    $reservations = $stmt->fetchAll();
    
    if (!empty($reservations)) {
        echo "<table border='1' cellpadding='5' style='width: 100%;'>";
        echo "<tr><th>ID</th><th>Fahrzeug</th><th>Status</th><th>Antragsteller</th><th>Google Event ID</th><th>Test Löschen</th></tr>";
        
        foreach ($reservations as $reservation) {
            echo "<tr>";
            echo "<td>" . $reservation['id'] . "</td>";
            echo "<td>" . htmlspecialchars($reservation['vehicle_name']) . "</td>";
            echo "<td>" . $reservation['status'] . "</td>";
            echo "<td>" . htmlspecialchars($reservation['requester_name']) . "</td>";
            echo "<td>" . ($reservation['google_event_id'] ?: 'Keine') . "</td>";
            echo "<td>";
            
            if ($reservation['google_event_id']) {
                echo "<a href='?action=test_delete&reservation_id=" . $reservation['id'] . "&event_id=" . urlencode($reservation['google_event_id']) . "' class='btn btn-sm btn-warning'>Test Löschen</a>";
            } else {
                echo "-";
            }
            echo "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p style='color: orange;'>⚠️ Keine Reservierungen gefunden</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim Laden der Reservierungen: " . $e->getMessage() . "</p>";
}

// 2. Teste Löschen einer Reservierung
if (isset($_GET['action']) && $_GET['action'] == 'test_delete') {
    $reservation_id = (int)$_GET['reservation_id'];
    $event_id = $_GET['event_id'];
    
    echo "<h2>2. Teste Löschen von Reservierung ID: $reservation_id</h2>";
    echo "<p><strong>Google Event ID:</strong> $event_id</p>";
    
    try {
        // Simuliere den Lösch-Vorgang aus admin/reservations.php
        echo "<h3>2.1 Simuliere Lösch-Vorgang</h3>";
        
        // Hole Google Calendar Event ID vor dem Löschen
        $stmt = $db->prepare("SELECT google_event_id FROM calendar_events WHERE reservation_id = ?");
        $stmt->execute([$reservation_id]);
        $calendar_event = $stmt->fetch();
        
        echo "<p><strong>Calendar Event aus DB:</strong> " . ($calendar_event ? $calendar_event['google_event_id'] : 'Nicht gefunden') . "</p>";
        
        // Lösche aus Google Calendar (nur wenn Event ID vorhanden)
        $google_deleted = false;
        if ($calendar_event && !empty($calendar_event['google_event_id'])) {
            echo "<p>Starte Google Calendar Löschung...</p>";
            $start_time = microtime(true);
            $google_deleted = delete_google_calendar_event($calendar_event['google_event_id']);
            $end_time = microtime(true);
            
            $duration = round(($end_time - $start_time) * 1000, 2);
            echo "<p><strong>Lösch-Dauer:</strong> {$duration}ms</p>";
            
            if ($google_deleted) {
                echo "<p style='color: green;'>✅ Google Calendar Event erfolgreich gelöscht!</p>";
            } else {
                echo "<p style='color: red;'>❌ Google Calendar Event konnte nicht gelöscht werden</p>";
            }
        } else {
            echo "<p style='color: orange;'>⚠️ Keine Google Event ID gefunden</p>";
        }
        
        // Lösche aus lokaler Datenbank
        echo "<h3>2.2 Lösche aus lokaler Datenbank</h3>";
        
        $stmt = $db->prepare("DELETE FROM calendar_events WHERE reservation_id = ?");
        $stmt->execute([$reservation_id]);
        $deleted_calendar_events = $stmt->rowCount();
        echo "<p style='color: green;'>✅ $deleted_calendar_events Calendar Event(s) aus Datenbank gelöscht</p>";
        
        $stmt = $db->prepare("DELETE FROM reservations WHERE id = ?");
        $stmt->execute([$reservation_id]);
        $deleted_reservations = $stmt->rowCount();
        echo "<p style='color: green;'>✅ $deleted_reservations Reservierung(en) aus Datenbank gelöscht</p>";
        
        // Angepasste Meldung basierend auf Google Calendar Erfolg
        if ($google_deleted) {
            $message = "Reservierung erfolgreich gelöscht (sowohl aus Datenbank als auch Google Calendar).";
        } else {
            $message = "Reservierung erfolgreich aus der Datenbank gelöscht.";
            if ($calendar_event && !empty($calendar_event['google_event_id'])) {
                $message .= " <strong>Hinweis:</strong> Der Google Calendar Eintrag muss manuell gelöscht werden (Event ID: " . $calendar_event['google_event_id'] . ").";
            }
        }
        
        echo "<h3>2.3 Ergebnis</h3>";
        echo "<p style='color: green; font-weight: bold;'>$message</p>";
        
        if ($deleted_reservations > 0) {
            echo "<p style='color: green; font-weight: bold;'>🎉 Reservierung erfolgreich gelöscht!</p>";
        } else {
            echo "<p style='color: orange;'>⚠️ Reservierung war bereits gelöscht</p>";
        }
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Fehler beim Löschen: " . $e->getMessage() . "</p>";
        echo "<p><strong>Stack Trace:</strong></p>";
        echo "<pre>" . $e->getTraceAsString() . "</pre>";
    }
    
    echo "<p><a href='debug-reservations-delete.php'>← Zurück zur Übersicht</a></p>";
}

// 3. Prüfe Google Calendar Event Status
echo "<h2>3. Google Calendar Event Status prüfen</h2>";

if (isset($_GET['event_id'])) {
    $event_id = $_GET['event_id'];
    echo "<p><strong>Prüfe Event ID:</strong> $event_id</p>";
    
    try {
        if (class_exists('GoogleCalendarServiceAccount')) {
            $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'google_calendar_service_account'");
            $stmt->execute();
            $service_account_json = $stmt->fetchColumn();
            
            if ($service_account_json) {
                $calendar_service = new GoogleCalendarServiceAccount($service_account_json, 'a3f7e2f57f274ba2fe7d3a62a932a33c78ed468aafa6ac477b58f16495e5677a@group.calendar.google.com', true);
                
                // Versuche Event abzurufen
                try {
                    $event = $calendar_service->getEvent($event_id);
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
}

echo "<hr>";
echo "<p><a href='admin/reservations.php'>→ Zur Reservierungen-Übersicht</a></p>";
echo "<p><a href='test-google-calendar-delete-fixed.php'>→ Google Calendar Test</a></p>";
echo "<p><small>Debug abgeschlossen: " . date('Y-m-d H:i:s') . "</small></p>";
?>
