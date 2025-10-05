<?php
/**
 * Google Calendar Events manuell verwalten
 */

require_once 'config/database.php';
require_once 'includes/functions.php';

echo "<h1>📅 Google Calendar Events manuell verwalten</h1>";

// 1. Zeige alle Google Calendar Events
echo "<h2>1. Alle Google Calendar Events in der Datenbank</h2>";

try {
    $stmt = $db->prepare("
        SELECT ce.*, r.requester_name, r.reason, v.name as vehicle_name
        FROM calendar_events ce
        LEFT JOIN reservations r ON ce.reservation_id = r.id
        LEFT JOIN vehicles v ON r.vehicle_id = v.id
        ORDER BY ce.id DESC
    ");
    $stmt->execute();
    $calendar_events = $stmt->fetchAll();
    
    if (!empty($calendar_events)) {
        echo "<table border='1' cellpadding='5' style='width: 100%;'>";
        echo "<tr><th>ID</th><th>Reservation ID</th><th>Google Event ID</th><th>Fahrzeug</th><th>Antragsteller</th><th>Grund</th><th>Erstellt</th><th>Aktionen</th></tr>";
        
        foreach ($calendar_events as $event) {
            echo "<tr>";
            echo "<td>" . $event['id'] . "</td>";
            echo "<td>" . ($event['reservation_id'] ?: 'Keine') . "</td>";
            echo "<td>" . htmlspecialchars($event['google_event_id']) . "</td>";
            echo "<td>" . htmlspecialchars($event['vehicle_name'] ?: 'Unbekannt') . "</td>";
            echo "<td>" . htmlspecialchars($event['requester_name'] ?: 'Unbekannt') . "</td>";
            echo "<td>" . htmlspecialchars($event['reason'] ?: 'Unbekannt') . "</td>";
            echo "<td>" . $event['created_at'] . "</td>";
            echo "<td>";
            echo "<a href='?action=delete_event&id=" . $event['id'] . "' class='btn btn-sm btn-danger' onclick='return confirm(\"Event aus Datenbank löschen?\")'>Aus DB löschen</a> ";
            echo "<a href='https://calendar.google.com/calendar/u/0/r' target='_blank' class='btn btn-sm btn-info'>Google Calendar öffnen</a>";
            echo "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p style='color: orange;'>⚠️ Keine Google Calendar Events in der Datenbank gefunden</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim Laden der Calendar Events: " . $e->getMessage() . "</p>";
}

// 2. Verarbeite Aktionen
if (isset($_GET['action'])) {
    $action = $_GET['action'];
    $id = (int)$_GET['id'];
    
    echo "<h2>2. Aktion: " . $action . " für ID: " . $id . "</h2>";
    
    try {
        if ($action == 'delete_event') {
            // Lade Event-Daten
            $stmt = $db->prepare("SELECT * FROM calendar_events WHERE id = ?");
            $stmt->execute([$id]);
            $event = $stmt->fetch();
            
            if ($event) {
                echo "<p>Lösche Calendar Event aus Datenbank:</p>";
                echo "<ul>";
                echo "<li><strong>Google Event ID:</strong> " . htmlspecialchars($event['google_event_id']) . "</li>";
                echo "<li><strong>Reservation ID:</strong> " . ($event['reservation_id'] ?: 'Keine') . "</li>";
                echo "</ul>";
                
                // Lösche aus Datenbank
                $stmt = $db->prepare("DELETE FROM calendar_events WHERE id = ?");
                $stmt->execute([$id]);
                
                echo "<p style='color: green;'>✅ Calendar Event aus Datenbank gelöscht</p>";
                echo "<p style='color: orange;'>⚠️ <strong>Wichtig:</strong> Der Google Calendar Eintrag muss manuell gelöscht werden!</p>";
                echo "<p><a href='https://calendar.google.com/calendar/u/0/r' target='_blank'>→ Google Calendar öffnen</a></p>";
                
            } else {
                echo "<p style='color: red;'>❌ Calendar Event nicht gefunden</p>";
            }
        }
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Fehler bei der Aktion: " . $e->getMessage() . "</p>";
    }
    
    echo "<p><a href='manage-google-calendar-events.php'>← Zurück zur Übersicht</a></p>";
}

// 3. Cleanup-Statistiken
echo "<h2>3. Cleanup-Statistiken</h2>";

try {
    // Orphaned Calendar Events (ohne Reservierung)
    $stmt = $db->prepare("
        SELECT COUNT(*) as count 
        FROM calendar_events ce 
        LEFT JOIN reservations r ON ce.reservation_id = r.id 
        WHERE r.id IS NULL
    ");
    $stmt->execute();
    $orphaned_events = $stmt->fetch()['count'];
    
    echo "<p><strong>Verwaiste Calendar Events (ohne Reservierung):</strong> $orphaned_events</p>";
    
    if ($orphaned_events > 0) {
        echo "<p style='color: orange;'>⚠️ Es gibt verwaiste Calendar Events. Diese können gelöscht werden.</p>";
        echo "<a href='?action=cleanup_orphaned' class='btn btn-warning'>Verwaiste Events löschen</a>";
    }
    
    // Reservierungen ohne Calendar Events
    $stmt = $db->prepare("
        SELECT COUNT(*) as count 
        FROM reservations r 
        LEFT JOIN calendar_events ce ON r.id = ce.reservation_id 
        WHERE r.status = 'approved' AND ce.id IS NULL
    ");
    $stmt->execute();
    $reservations_without_events = $stmt->fetch()['count'];
    
    echo "<p><strong>Genehmigte Reservierungen ohne Calendar Events:</strong> $reservations_without_events</p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim Laden der Statistiken: " . $e->getMessage() . "</p>";
}

// 4. Cleanup verwaiste Events
if (isset($_GET['action']) && $_GET['action'] == 'cleanup_orphaned') {
    echo "<h2>4. Cleanup verwaiste Events</h2>";
    
    try {
        $stmt = $db->prepare("
            DELETE ce FROM calendar_events ce 
            LEFT JOIN reservations r ON ce.reservation_id = r.id 
            WHERE r.id IS NULL
        ");
        $stmt->execute();
        $deleted_count = $stmt->rowCount();
        
        echo "<p style='color: green;'>✅ $deleted_count verwaiste Calendar Events gelöscht</p>";
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Fehler beim Cleanup: " . $e->getMessage() . "</p>";
    }
}

// 5. Anleitung für manuelles Google Calendar Löschen
echo "<h2>5. Anleitung: Google Calendar Events manuell löschen</h2>";

echo "<div style='background: #e7f3ff; padding: 15px; border: 1px solid #b3d9ff; border-radius: 5px;'>";
echo "<h3 style='color: #0066cc; margin-top: 0;'>📅 Google Calendar Events manuell löschen</h3>";
echo "<ol style='color: #0066cc;'>";
echo "<li>Öffnen Sie <a href='https://calendar.google.com/calendar/u/0/r' target='_blank'>Google Calendar</a></li>";
echo "<li>Wechseln Sie zu dem Kalender: <code>a3f7e2f57f274ba2fe7d3a62a932a33c78ed468aafa6ac477b58f16495e5677a@group.calendar.google.com</code></li>";
echo "<li>Suchen Sie nach den Events mit den IDs aus der Tabelle oben</li>";
echo "<li>Klicken Sie auf jedes Event</li>";
echo "<li>Klicken Sie auf 'Löschen' oder 'Delete'</li>";
echo "<li>Bestätigen Sie das Löschen</li>";
echo "</ol>";
echo "<p style='color: #0066cc;'><strong>Tipp:</strong> Sie können auch mehrere Events gleichzeitig auswählen und löschen.</p>";
echo "</div>";

echo "<hr>";
echo "<p><a href='admin/reservations.php'>→ Zur Reservierungen-Übersicht</a></p>";
echo "<p><a href='admin/dashboard.php'>→ Zum Dashboard</a></p>";
echo "<p><small>Google Calendar Events Management: " . date('Y-m-d H:i:s') . "</small></p>";
?>
