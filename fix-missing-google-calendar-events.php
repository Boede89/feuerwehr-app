<?php
/**
 * Script um fehlende Google Calendar Events für bereits genehmigte Reservierungen zu erstellen
 */

require_once 'config/database.php';
require_once 'includes/functions.php';

echo "<h1>Fehlende Google Calendar Events reparieren</h1>";
echo "<p>Zeitstempel: " . date('d.m.Y H:i:s') . "</p>";

// 1. Finde genehmigte Reservierungen ohne Google Calendar Events
echo "<h2>1. Genehmigte Reservierungen ohne Google Calendar Events finden</h2>";

try {
    $stmt = $db->prepare("
        SELECT r.*, v.name as vehicle_name 
        FROM reservations r 
        JOIN vehicles v ON r.vehicle_id = v.id 
        LEFT JOIN calendar_events ce ON r.id = ce.reservation_id 
        WHERE r.status = 'approved' 
        AND ce.id IS NULL 
        ORDER BY r.approved_at DESC
    ");
    $stmt->execute();
    $reservations = $stmt->fetchAll();
    
    if (empty($reservations)) {
        echo "<p style='color: green;'>✅ Alle genehmigten Reservierungen haben bereits Google Calendar Events</p>";
    } else {
        echo "<p style='color: orange;'>⚠️ " . count($reservations) . " genehmigte Reservierungen ohne Google Calendar Events gefunden</p>";
        
        foreach ($reservations as $reservation) {
            echo "<div style='border: 1px solid #ddd; padding: 10px; margin: 10px 0;'>";
            echo "<p><strong>ID:</strong> " . htmlspecialchars($reservation['id']) . "</p>";
            echo "<p><strong>Fahrzeug:</strong> " . htmlspecialchars($reservation['vehicle_name']) . "</p>";
            echo "<p><strong>Grund:</strong> " . htmlspecialchars($reservation['reason']) . "</p>";
            echo "<p><strong>Start:</strong> " . htmlspecialchars($reservation['start_datetime']) . "</p>";
            echo "<p><strong>Ende:</strong> " . htmlspecialchars($reservation['end_datetime']) . "</p>";
            echo "<p><strong>Genehmigt am:</strong> " . htmlspecialchars($reservation['approved_at']) . "</p>";
            echo "</div>";
        }
    }
} catch(PDOException $e) {
    echo "<p style='color: red;'>❌ Fehler beim Laden der Reservierungen: " . $e->getMessage() . "</p>";
    exit;
}

// 2. Google Calendar Events für fehlende Reservierungen erstellen
if (!empty($reservations)) {
    echo "<h2>2. Google Calendar Events erstellen</h2>";
    
    $success_count = 0;
    $error_count = 0;
    
    foreach ($reservations as $reservation) {
        echo "<p><strong>Verarbeite Reservierung #" . $reservation['id'] . " - " . htmlspecialchars($reservation['vehicle_name']) . "</strong></p>";
        
        try {
            $event_id = create_google_calendar_event(
                $reservation['vehicle_name'],
                $reservation['reason'],
                $reservation['start_datetime'],
                $reservation['end_datetime'],
                $reservation['id']
            );
            
            if ($event_id) {
                echo "<p style='color: green;'>✅ Event erfolgreich erstellt - ID: " . htmlspecialchars($event_id) . "</p>";
                $success_count++;
            } else {
                echo "<p style='color: red;'>❌ Event konnte nicht erstellt werden</p>";
                $error_count++;
            }
        } catch (Exception $e) {
            echo "<p style='color: red;'>❌ Fehler: " . htmlspecialchars($e->getMessage()) . "</p>";
            $error_count++;
        }
        
        // Kurze Pause zwischen den Requests
        sleep(1);
    }
    
    echo "<h3>Zusammenfassung:</h3>";
    echo "<p style='color: green;'>✅ Erfolgreich erstellt: $success_count Events</p>";
    echo "<p style='color: red;'>❌ Fehler: $error_count Events</p>";
}

// 3. Prüfe das Ergebnis
echo "<h2>3. Ergebnis prüfen</h2>";

try {
    $stmt = $db->prepare("
        SELECT COUNT(*) as total_reservations 
        FROM reservations 
        WHERE status = 'approved'
    ");
    $stmt->execute();
    $total_reservations = $stmt->fetchColumn();
    
    $stmt = $db->prepare("
        SELECT COUNT(*) as total_events 
        FROM calendar_events ce 
        JOIN reservations r ON ce.reservation_id = r.id 
        WHERE r.status = 'approved'
    ");
    $stmt->execute();
    $total_events = $stmt->fetchColumn();
    
    echo "<p><strong>Genehmigte Reservierungen:</strong> $total_reservations</p>";
    echo "<p><strong>Google Calendar Events:</strong> $total_events</p>";
    
    if ($total_reservations == $total_events) {
        echo "<p style='color: green;'>✅ Alle genehmigten Reservierungen haben jetzt Google Calendar Events!</p>";
    } else {
        echo "<p style='color: orange;'>⚠️ " . ($total_reservations - $total_events) . " Reservierungen haben noch keine Google Calendar Events</p>";
    }
} catch(PDOException $e) {
    echo "<p style='color: red;'>❌ Fehler beim Prüfen des Ergebnisses: " . $e->getMessage() . "</p>";
}

echo "<hr>";
echo "<p><strong>Reparatur abgeschlossen um:</strong> " . date('d.m.Y H:i:s') . "</p>";
?>
