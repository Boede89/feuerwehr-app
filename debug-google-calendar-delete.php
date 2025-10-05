<?php
/**
 * Debug: Google Calendar Lösch-Funktionalität
 */

require_once 'config/database.php';
require_once 'includes/functions.php';

echo "<h1>🔍 Debug: Google Calendar Lösch-Funktionalität</h1>";

// 1. Prüfe Google Calendar Funktionen
echo "<h2>1. Google Calendar Funktionen prüfen</h2>";

if (function_exists('delete_google_calendar_event')) {
    echo "<p style='color: green;'>✅ delete_google_calendar_event Funktion verfügbar</p>";
} else {
    echo "<p style='color: red;'>❌ delete_google_calendar_event Funktion NICHT verfügbar</p>";
}

if (class_exists('GoogleCalendarServiceAccount')) {
    echo "<p style='color: green;'>✅ GoogleCalendarServiceAccount Klasse verfügbar</p>";
} else {
    echo "<p style='color: red;'>❌ GoogleCalendarServiceAccount Klasse NICHT verfügbar</p>";
}

// 2. Prüfe calendar_events Tabelle
echo "<h2>2. Calendar Events Tabelle prüfen</h2>";

try {
    // Prüfe ob Tabelle existiert
    $stmt = $db->prepare("SHOW TABLES LIKE 'calendar_events'");
    $stmt->execute();
    $table_exists = $stmt->fetch();
    
    if ($table_exists) {
        echo "<p style='color: green;'>✅ calendar_events Tabelle existiert</p>";
        
        // Zeige alle Einträge
        $stmt = $db->prepare("SELECT * FROM calendar_events ORDER BY id DESC LIMIT 10");
        $stmt->execute();
        $calendar_events = $stmt->fetchAll();
        
        if (!empty($calendar_events)) {
            echo "<p><strong>Letzte 10 Calendar Events:</strong></p>";
            echo "<table border='1' cellpadding='5' style='width: 100%;'>";
            echo "<tr><th>ID</th><th>Reservation ID</th><th>Google Event ID</th><th>Erstellt</th></tr>";
            foreach ($calendar_events as $event) {
                echo "<tr>";
                echo "<td>" . $event['id'] . "</td>";
                echo "<td>" . $event['reservation_id'] . "</td>";
                echo "<td>" . ($event['google_event_id'] ?: 'Keine') . "</td>";
                echo "<td>" . $event['created_at'] . "</td>";
                echo "</tr>";
            }
            echo "</table>";
        } else {
            echo "<p style='color: orange;'>⚠️ Keine Calendar Events gefunden</p>";
        }
    } else {
        echo "<p style='color: red;'>❌ calendar_events Tabelle existiert NICHT</p>";
        echo "<p>Das ist das Problem! Die Tabelle muss erstellt werden.</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim Prüfen der Tabelle: " . $e->getMessage() . "</p>";
}

// 3. Prüfe Reservierungen mit Google Calendar Events
echo "<h2>3. Reservierungen mit Google Calendar Events</h2>";

try {
    $stmt = $db->prepare("
        SELECT r.*, v.name as vehicle_name, ce.google_event_id, ce.id as calendar_event_id
        FROM reservations r 
        JOIN vehicles v ON r.vehicle_id = v.id 
        LEFT JOIN calendar_events ce ON r.id = ce.reservation_id
        WHERE r.status IN ('approved', 'rejected')
        ORDER BY r.id DESC
        LIMIT 10
    ");
    $stmt->execute();
    $reservations = $stmt->fetchAll();
    
    if (!empty($reservations)) {
        echo "<table border='1' cellpadding='5' style='width: 100%;'>";
        echo "<tr><th>Reservation ID</th><th>Fahrzeug</th><th>Status</th><th>Calendar Event ID</th><th>Google Event ID</th><th>Test Löschen</th></tr>";
        
        foreach ($reservations as $reservation) {
            echo "<tr>";
            echo "<td>" . $reservation['id'] . "</td>";
            echo "<td>" . htmlspecialchars($reservation['vehicle_name']) . "</td>";
            echo "<td>" . $reservation['status'] . "</td>";
            echo "<td>" . ($reservation['calendar_event_id'] ?: 'Keine') . "</td>";
            echo "<td>" . ($reservation['google_event_id'] ?: 'Keine') . "</td>";
            
            if ($reservation['google_event_id']) {
                echo "<td><button onclick='testDelete(\"" . $reservation['google_event_id'] . "\")'>Test Löschen</button></td>";
            } else {
                echo "<td>-</td>";
            }
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p style='color: orange;'>⚠️ Keine Reservierungen gefunden</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim Laden der Reservierungen: " . $e->getMessage() . "</p>";
}

// 4. Teste Google Calendar Lösch-Funktion
echo "<h2>4. Google Calendar Lösch-Funktion testen</h2>";

if (function_exists('delete_google_calendar_event')) {
    echo "<p>Teste mit einer Dummy Event ID:</p>";
    
    $test_event_id = 'test_event_123';
    echo "<p><strong>Test Event ID:</strong> $test_event_id</p>";
    
    $result = delete_google_calendar_event($test_event_id);
    
    if ($result) {
        echo "<p style='color: green;'>✅ Lösch-Funktion funktioniert (auch wenn Event nicht existiert)</p>";
    } else {
        echo "<p style='color: red;'>❌ Lösch-Funktion schlägt fehl</p>";
    }
} else {
    echo "<p style='color: red;'>❌ delete_google_calendar_event Funktion nicht verfügbar</p>";
}

// 5. JavaScript für Test-Löschen
echo "<script>";
echo "function testDelete(googleEventId) {";
echo "  const resultDiv = document.getElementById('test-result');";
echo "  if (!resultDiv) {";
echo "    const div = document.createElement('div');";
echo "    div.id = 'test-result';";
echo "    div.style.marginTop = '20px';";
echo "    document.body.appendChild(div);";
echo "  }";
echo "  ";
echo "  const resultDiv = document.getElementById('test-result');";
echo "  resultDiv.innerHTML = '<p>Teste Löschen von Event: ' + googleEventId + '</p>';";
echo "  ";
echo "  fetch('test-google-calendar-delete.php', {";
echo "    method: 'POST',";
echo "    headers: { 'Content-Type': 'application/json' },";
echo "    body: JSON.stringify({ event_id: googleEventId })";
echo "  })";
echo "  .then(response => response.text())";
echo "  .then(text => {";
echo "    resultDiv.innerHTML += '<pre>' + text + '</pre>';";
echo "  })";
echo "  .catch(error => {";
echo "    resultDiv.innerHTML += '<p style=\"color: red;\">Fehler: ' + error.message + '</p>';";
echo "  });";
echo "}";
echo "</script>";

echo "<hr>";
echo "<p><a href='admin/reservations.php'>→ Zur Reservierungen-Übersicht</a></p>";
echo "<p><a href='admin/dashboard.php'>→ Zum Dashboard</a></p>";
echo "<p><small>Debug abgeschlossen: " . date('Y-m-d H:i:s') . "</small></p>";
?>
