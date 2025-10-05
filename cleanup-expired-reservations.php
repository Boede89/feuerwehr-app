<?php
/**
 * Automatisches Löschen von überschrittenen Reservierungen
 * 
 * Dieses Skript sollte regelmäßig ausgeführt werden (z.B. via Cron Job)
 * um alte Reservierungen sowohl aus der Datenbank als auch aus dem Google Calendar zu entfernen.
 */

require_once 'config/database.php';
require_once 'includes/functions.php';

echo "<h1>🧹 Cleanup: Überschrittene Reservierungen löschen</h1>";
echo "<p>Zeitstempel: " . date('Y-m-d H:i:s') . "</p>";

try {
    // 1. Finde alle Reservierungen, die bereits beendet sind
    $current_datetime = date('Y-m-d H:i:s');
    
    $stmt = $db->prepare("
        SELECT r.*, v.name as vehicle_name, ce.google_event_id
        FROM reservations r 
        JOIN vehicles v ON r.vehicle_id = v.id 
        LEFT JOIN calendar_events ce ON r.id = ce.reservation_id
        WHERE r.end_datetime < ? 
        AND r.status IN ('approved', 'rejected')
        ORDER BY r.end_datetime ASC
    ");
    
    $stmt->execute([$current_datetime]);
    $expired_reservations = $stmt->fetchAll();
    
    echo "<h2>1. Gefundene überschrittene Reservierungen</h2>";
    
    if (empty($expired_reservations)) {
        echo "<p style='color: green;'>✅ Keine überschrittenen Reservierungen gefunden</p>";
    } else {
        echo "<p style='color: orange;'>⚠️ " . count($expired_reservations) . " überschrittene Reservierungen gefunden:</p>";
        
        echo "<table border='1' cellpadding='5' style='width: 100%;'>";
        echo "<tr><th>ID</th><th>Fahrzeug</th><th>Antragsteller</th><th>Grund</th><th>Ende</th><th>Status</th><th>Google Event ID</th></tr>";
        
        foreach ($expired_reservations as $reservation) {
            echo "<tr>";
            echo "<td>" . $reservation['id'] . "</td>";
            echo "<td>" . htmlspecialchars($reservation['vehicle_name']) . "</td>";
            echo "<td>" . htmlspecialchars($reservation['requester_name']) . "</td>";
            echo "<td>" . htmlspecialchars($reservation['reason']) . "</td>";
            echo "<td>" . $reservation['end_datetime'] . "</td>";
            echo "<td>" . $reservation['status'] . "</td>";
            echo "<td>" . ($reservation['google_event_id'] ?: 'Keine') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        // 2. Lösche die Reservierungen
        echo "<h2>2. Lösche überschrittene Reservierungen</h2>";
        
        $deleted_count = 0;
        $google_deleted_count = 0;
        $errors = [];
        
        foreach ($expired_reservations as $reservation) {
            try {
                // Lösche aus Google Calendar (nur wenn Event ID vorhanden)
                if (!empty($reservation['google_event_id'])) {
                    $google_deleted = delete_google_calendar_event($reservation['google_event_id']);
                    if ($google_deleted) {
                        $google_deleted_count++;
                        echo "<p style='color: green;'>✅ Google Calendar Event gelöscht: " . $reservation['google_event_id'] . "</p>";
                    } else {
                        $errors[] = "Fehler beim Löschen des Google Calendar Events: " . $reservation['google_event_id'];
                        echo "<p style='color: red;'>❌ Fehler beim Löschen des Google Calendar Events: " . $reservation['google_event_id'] . "</p>";
                    }
                }
                
                // Lösche aus lokaler Datenbank
                $stmt = $db->prepare("DELETE FROM calendar_events WHERE reservation_id = ?");
                $stmt->execute([$reservation['id']]);
                
                $stmt = $db->prepare("DELETE FROM reservations WHERE id = ?");
                $stmt->execute([$reservation['id']]);
                
                $deleted_count++;
                echo "<p style='color: green;'>✅ Reservierung gelöscht: ID " . $reservation['id'] . " (" . $reservation['vehicle_name'] . ")</p>";
                
            } catch (Exception $e) {
                $errors[] = "Fehler beim Löschen der Reservierung ID " . $reservation['id'] . ": " . $e->getMessage();
                echo "<p style='color: red;'>❌ Fehler beim Löschen der Reservierung ID " . $reservation['id'] . ": " . $e->getMessage() . "</p>";
            }
        }
        
        // 3. Zusammenfassung
        echo "<h2>3. Zusammenfassung</h2>";
        echo "<ul>";
        echo "<li><strong>Gefundene Reservierungen:</strong> " . count($expired_reservations) . "</li>";
        echo "<li><strong>Gelöschte Reservierungen:</strong> " . $deleted_count . "</li>";
        echo "<li><strong>Gelöschte Google Calendar Events:</strong> " . $google_deleted_count . "</li>";
        echo "<li><strong>Fehler:</strong> " . count($errors) . "</li>";
        echo "</ul>";
        
        if (!empty($errors)) {
            echo "<h3>Fehler-Details:</h3>";
            echo "<ul>";
            foreach ($errors as $error) {
                echo "<li style='color: red;'>" . htmlspecialchars($error) . "</li>";
            }
            echo "</ul>";
        }
        
        if ($deleted_count > 0) {
            echo "<p style='color: green; font-weight: bold;'>✅ Cleanup erfolgreich abgeschlossen!</p>";
        }
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim Cleanup: " . $e->getMessage() . "</p>";
    error_log('Cleanup-Fehler: ' . $e->getMessage());
}

echo "<hr>";
echo "<p><a href='admin/reservations.php'>→ Zur Reservierungen-Übersicht</a></p>";
echo "<p><a href='admin/dashboard.php'>→ Zum Dashboard</a></p>";
echo "<p><small>Cleanup abgeschlossen: " . date('Y-m-d H:i:s') . "</small></p>";
?>
