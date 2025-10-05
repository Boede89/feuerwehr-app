<?php
/**
 * Einfache Lösung für Google Calendar Lösch-Problem
 */

require_once 'config/database.php';
require_once 'includes/functions.php';

echo "<h1>🔧 Einfache Lösung: Google Calendar Lösch-Problem</h1>";

// 1. Erstelle calendar_events Tabelle falls nötig
echo "<h2>1. Calendar Events Tabelle prüfen/erstellen</h2>";

try {
    $stmt = $db->prepare("SHOW TABLES LIKE 'calendar_events'");
    $stmt->execute();
    $table_exists = $stmt->fetch();
    
    if (!$table_exists) {
        echo "<p style='color: orange;'>⚠️ Tabelle existiert nicht - erstelle sie...</p>";
        
        $sql = "
        CREATE TABLE calendar_events (
            id INT AUTO_INCREMENT PRIMARY KEY,
            reservation_id INT NOT NULL,
            google_event_id VARCHAR(255) NOT NULL,
            title VARCHAR(255),
            start_datetime DATETIME,
            end_datetime DATETIME,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE CASCADE,
            INDEX idx_reservation_id (reservation_id),
            INDEX idx_google_event_id (google_event_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";
        
        $db->exec($sql);
        echo "<p style='color: green;'>✅ calendar_events Tabelle erstellt</p>";
    } else {
        echo "<p style='color: green;'>✅ calendar_events Tabelle existiert</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim Erstellen der Tabelle: " . $e->getMessage() . "</p>";
}

// 2. Zeige alle Reservierungen
echo "<h2>2. Alle Reservierungen</h2>";

try {
    $stmt = $db->prepare("
        SELECT r.*, v.name as vehicle_name, ce.google_event_id, ce.id as calendar_event_id
        FROM reservations r 
        JOIN vehicles v ON r.vehicle_id = v.id 
        LEFT JOIN calendar_events ce ON r.id = ce.reservation_id
        ORDER BY r.id DESC
        LIMIT 20
    ");
    $stmt->execute();
    $reservations = $stmt->fetchAll();
    
    if (!empty($reservations)) {
        echo "<table border='1' cellpadding='5' style='width: 100%;'>";
        echo "<tr><th>ID</th><th>Fahrzeug</th><th>Status</th><th>Antragsteller</th><th>Google Event ID</th><th>Aktionen</th></tr>";
        
        foreach ($reservations as $reservation) {
            echo "<tr>";
            echo "<td>" . $reservation['id'] . "</td>";
            echo "<td>" . htmlspecialchars($reservation['vehicle_name']) . "</td>";
            echo "<td>" . $reservation['status'] . "</td>";
            echo "<td>" . htmlspecialchars($reservation['requester_name']) . "</td>";
            echo "<td>" . ($reservation['google_event_id'] ?: 'Keine') . "</td>";
            echo "<td>";
            
            if ($reservation['status'] == 'approved' && !$reservation['google_event_id']) {
                echo "<a href='?action=create_event&id=" . $reservation['id'] . "' class='btn btn-sm btn-primary'>Event erstellen</a> ";
            }
            
            if (in_array($reservation['status'], ['approved', 'rejected'])) {
                echo "<a href='?action=delete&id=" . $reservation['id'] . "' class='btn btn-sm btn-danger' onclick='return confirm(\"Wirklich löschen?\")'>Löschen</a>";
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

// 3. Verarbeite Aktionen
if (isset($_GET['action'])) {
    $action = $_GET['action'];
    $id = (int)$_GET['id'];
    
    echo "<h2>3. Aktion: " . $action . " für ID: " . $id . "</h2>";
    
    try {
        if ($action == 'create_event') {
            // Lade Reservierungsdaten
            $stmt = $db->prepare("
                SELECT r.*, v.name as vehicle_name 
                FROM reservations r 
                JOIN vehicles v ON r.vehicle_id = v.id 
                WHERE r.id = ?
            ");
            $stmt->execute([$id]);
            $reservation = $stmt->fetch();
            
            if ($reservation) {
                echo "<p>Erstelle Google Calendar Event für: " . $reservation['vehicle_name'] . " - " . $reservation['reason'] . "</p>";
                
                $event_id = create_google_calendar_event(
                    $reservation['vehicle_name'],
                    $reservation['reason'],
                    $reservation['start_datetime'],
                    $reservation['end_datetime'],
                    $reservation['id'],
                    $reservation['location']
                );
                
                if ($event_id) {
                    echo "<p style='color: green;'>✅ Google Calendar Event erfolgreich erstellt: " . $event_id . "</p>";
                } else {
                    echo "<p style='color: red;'>❌ Fehler beim Erstellen des Google Calendar Events</p>";
                }
            } else {
                echo "<p style='color: red;'>❌ Reservierung nicht gefunden</p>";
            }
            
        } elseif ($action == 'delete') {
            // Lade Reservierungsdaten
            $stmt = $db->prepare("
                SELECT r.*, v.name as vehicle_name, ce.google_event_id
                FROM reservations r 
                JOIN vehicles v ON r.vehicle_id = v.id 
                LEFT JOIN calendar_events ce ON r.id = ce.reservation_id
                WHERE r.id = ?
            ");
            $stmt->execute([$id]);
            $reservation = $stmt->fetch();
            
            if ($reservation) {
                echo "<p>Lösche Reservierung: " . $reservation['vehicle_name'] . " - " . $reservation['reason'] . "</p>";
                
                // Lösche aus Google Calendar
                if (!empty($reservation['google_event_id'])) {
                    echo "<p>Lösche Google Calendar Event: " . $reservation['google_event_id'] . "</p>";
                    $google_deleted = delete_google_calendar_event($reservation['google_event_id']);
                    
                    if ($google_deleted) {
                        echo "<p style='color: green;'>✅ Google Calendar Event gelöscht</p>";
                    } else {
                        echo "<p style='color: red;'>❌ Fehler beim Löschen des Google Calendar Events</p>";
                    }
                } else {
                    echo "<p style='color: orange;'>⚠️ Keine Google Event ID vorhanden</p>";
                }
                
                // Lösche aus lokaler Datenbank
                $stmt = $db->prepare("DELETE FROM calendar_events WHERE reservation_id = ?");
                $stmt->execute([$id]);
                echo "<p style='color: green;'>✅ calendar_events Eintrag gelöscht</p>";
                
                $stmt = $db->prepare("DELETE FROM reservations WHERE id = ?");
                $stmt->execute([$id]);
                echo "<p style='color: green;'>✅ Reservierung gelöscht</p>";
                
                echo "<p style='color: green; font-weight: bold;'>✅ Lösch-Vorgang erfolgreich abgeschlossen</p>";
            } else {
                echo "<p style='color: red;'>❌ Reservierung nicht gefunden</p>";
            }
        }
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Fehler: " . $e->getMessage() . "</p>";
    }
    
    echo "<p><a href='fix-google-calendar-simple.php'>← Zurück zur Übersicht</a></p>";
}

echo "<hr>";
echo "<p><a href='admin/reservations.php'>→ Zur Reservierungen-Übersicht</a></p>";
echo "<p><a href='admin/dashboard.php'>→ Zum Dashboard</a></p>";
echo "<p><small>Fix abgeschlossen: " . date('Y-m-d H:i:s') . "</small></p>";
?>
