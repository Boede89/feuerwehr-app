<?php
/**
 * Fix: Google Calendar Lösch-Problem beheben
 */

require_once 'config/database.php';
require_once 'includes/functions.php';

echo "<h1>🔧 Fix: Google Calendar Lösch-Problem</h1>";

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

// 2. Prüfe Reservierungen ohne Google Calendar Events
echo "<h2>2. Reservierungen ohne Google Calendar Events</h2>";

try {
    $stmt = $db->prepare("
        SELECT r.*, v.name as vehicle_name
        FROM reservations r 
        JOIN vehicles v ON r.vehicle_id = v.id 
        LEFT JOIN calendar_events ce ON r.id = ce.reservation_id
        WHERE r.status = 'approved' 
        AND ce.id IS NULL
        ORDER BY r.id DESC
        LIMIT 10
    ");
    $stmt->execute();
    $reservations_without_events = $stmt->fetchAll();
    
    if (!empty($reservations_without_events)) {
        echo "<p style='color: orange;'>⚠️ " . count($reservations_without_events) . " genehmigte Reservierungen ohne Google Calendar Events:</p>";
        
        echo "<table border='1' cellpadding='5' style='width: 100%;'>";
        echo "<tr><th>ID</th><th>Fahrzeug</th><th>Antragsteller</th><th>Grund</th><th>Start</th><th>Ende</th><th>Aktion</th></tr>";
        
        foreach ($reservations_without_events as $reservation) {
            echo "<tr>";
            echo "<td>" . $reservation['id'] . "</td>";
            echo "<td>" . htmlspecialchars($reservation['vehicle_name']) . "</td>";
            echo "<td>" . htmlspecialchars($reservation['requester_name']) . "</td>";
            echo "<td>" . htmlspecialchars($reservation['reason']) . "</td>";
            echo "<td>" . $reservation['start_datetime'] . "</td>";
            echo "<td>" . $reservation['end_datetime'] . "</td>";
            echo "<td><button onclick='createEvent(" . $reservation['id'] . ")'>Event erstellen</button></td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p style='color: green;'>✅ Alle genehmigten Reservierungen haben Google Calendar Events</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim Laden der Reservierungen: " . $e->getMessage() . "</p>";
}

// 3. Prüfe Reservierungen mit Google Calendar Events
echo "<h2>3. Reservierungen mit Google Calendar Events</h2>";

try {
    $stmt = $db->prepare("
        SELECT r.*, v.name as vehicle_name, ce.google_event_id, ce.id as calendar_event_id
        FROM reservations r 
        JOIN vehicles v ON r.vehicle_id = v.id 
        JOIN calendar_events ce ON r.id = ce.reservation_id
        WHERE r.status IN ('approved', 'rejected')
        ORDER BY r.id DESC
        LIMIT 10
    ");
    $stmt->execute();
    $reservations_with_events = $stmt->fetchAll();
    
    if (!empty($reservations_with_events)) {
        echo "<p style='color: green;'>✅ " . count($reservations_with_events) . " Reservierungen mit Google Calendar Events:</p>";
        
        echo "<table border='1' cellpadding='5' style='width: 100%;'>";
        echo "<tr><th>Reservation ID</th><th>Fahrzeug</th><th>Status</th><th>Google Event ID</th><th>Test Löschen</th></tr>";
        
        foreach ($reservations_with_events as $reservation) {
            echo "<tr>";
            echo "<td>" . $reservation['id'] . "</td>";
            echo "<td>" . htmlspecialchars($reservation['vehicle_name']) . "</td>";
            echo "<td>" . $reservation['status'] . "</td>";
            echo "<td>" . htmlspecialchars($reservation['google_event_id']) . "</td>";
            echo "<td><button onclick='testDelete(" . $reservation['id'] . ", \"" . $reservation['google_event_id'] . "\")'>Test Löschen</button></td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p style='color: orange;'>⚠️ Keine Reservierungen mit Google Calendar Events gefunden</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim Laden der Reservierungen: " . $e->getMessage() . "</p>";
}

// 4. JavaScript für Tests
?>
<script>
function createEvent(reservationId) {
    const resultDiv = document.getElementById('result');
    if (!resultDiv) {
        const div = document.createElement('div');
        div.id = 'result';
        div.style.marginTop = '20px';
        div.style.padding = '10px';
        div.style.border = '1px solid #ddd';
        div.style.backgroundColor = '#f9f9f9';
        document.body.appendChild(div);
    }
    
    const resultDiv = document.getElementById('result');
    resultDiv.innerHTML = '<p>Erstelle Google Calendar Event für Reservation ID: ' + reservationId + '</p>';
    
    fetch('test-create-google-event.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ reservation_id: reservationId })
    })
    .then(response => response.text())
    .then(text => {
        resultDiv.innerHTML += '<pre>' + text + '</pre>';
        setTimeout(() => location.reload(), 3000);
    })
    .catch(error => {
        resultDiv.innerHTML += '<p style="color: red;">Fehler: ' + error.message + '</p>';
    });
}

function testDelete(reservationId, googleEventId) {
    const resultDiv = document.getElementById('result');
    if (!resultDiv) {
        const div = document.createElement('div');
        div.id = 'result';
        div.style.marginTop = '20px';
        div.style.padding = '10px';
        div.style.border = '1px solid #ddd';
        div.style.backgroundColor = '#f9f9f9';
        document.body.appendChild(div);
    }
    
    const resultDiv = document.getElementById('result');
    resultDiv.innerHTML = '<p>Teste Löschen von Reservation ID: ' + reservationId + ', Google Event ID: ' + googleEventId + '</p>';
    
    fetch('test-delete-reservation.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ reservation_id: reservationId, google_event_id: googleEventId })
    })
    .then(response => response.text())
    .then(text => {
        resultDiv.innerHTML += '<pre>' + text + '</pre>';
        setTimeout(() => location.reload(), 3000);
    })
    .catch(error => {
        resultDiv.innerHTML += '<p style="color: red;">Fehler: ' + error.message + '</p>';
    });
}
</script>
<?php

echo "<hr>";
echo "<p><a href='debug-google-calendar-delete.php'>→ Google Calendar Delete Debug</a></p>";
echo "<p><a href='admin/reservations.php'>→ Zur Reservierungen-Übersicht</a></p>";
echo "<p><small>Fix abgeschlossen: " . date('Y-m-d H:i:s') . "</small></p>";
?>
