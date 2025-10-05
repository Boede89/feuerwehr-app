<?php
/**
 * Test: Google Calendar Integration
 */

require_once 'config/database.php';
require_once 'includes/functions.php';
require_once 'includes/google_calendar_service_account.php';
require_once 'includes/google_calendar.php';

echo "<h1>🔍 Google Calendar Integration Test</h1>";

// 1. Teste Google Calendar Funktionen
echo "<h2>1. Google Calendar Funktionen prüfen</h2>";

if (function_exists('check_calendar_conflicts')) {
    echo "<p style='color: green;'>✅ check_calendar_conflicts Funktion verfügbar</p>";
} else {
    echo "<p style='color: red;'>❌ check_calendar_conflicts Funktion NICHT verfügbar</p>";
}

if (class_exists('GoogleCalendarService')) {
    echo "<p style='color: green;'>✅ GoogleCalendarService Klasse verfügbar</p>";
} else {
    echo "<p style='color: red;'>❌ GoogleCalendarService Klasse NICHT verfügbar</p>";
}

// 2. Teste Google Calendar Konflikt-Prüfung
echo "<h2>2. Google Calendar Konflikt-Prüfung testen</h2>";

$test_vehicle = 'MTF';
$test_start = '2025-01-15 10:00:00';
$test_end = '2025-01-15 12:00:00';

echo "<p><strong>Test-Parameter:</strong></p>";
echo "<ul>";
echo "<li>Fahrzeug: $test_vehicle</li>";
echo "<li>Start: $test_start</li>";
echo "<li>Ende: $test_end</li>";
echo "</ul>";

if (function_exists('check_calendar_conflicts')) {
    try {
        echo "<p>Prüfe Google Calendar Konflikte...</p>";
        $conflicts = check_calendar_conflicts($test_vehicle, $test_start, $test_end);
        
        if (!empty($conflicts)) {
            echo "<p style='color: orange;'>⚠️ Konflikte gefunden:</p>";
            echo "<ul>";
            foreach ($conflicts as $conflict) {
                echo "<li>";
                echo "<strong>Titel:</strong> " . htmlspecialchars($conflict['title']) . "<br>";
                echo "<strong>Start:</strong> " . $conflict['start'] . "<br>";
                echo "<strong>Ende:</strong> " . $conflict['end'] . "<br>";
                if (isset($conflict['source'])) {
                    echo "<strong>Quelle:</strong> " . $conflict['source'] . "<br>";
                }
                echo "</li>";
            }
            echo "</ul>";
        } else {
            echo "<p style='color: green;'>✅ Keine Konflikte gefunden</p>";
        }
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Fehler bei Google Calendar Prüfung: " . $e->getMessage() . "</p>";
    }
} else {
    echo "<p style='color: red;'>❌ check_calendar_conflicts Funktion nicht verfügbar</p>";
}

// 3. Teste lokale Datenbank Konflikt-Prüfung
echo "<h2>3. Lokale Datenbank Konflikt-Prüfung testen</h2>";

try {
    $stmt = $db->prepare("
        SELECT r.*, v.name as vehicle_name 
        FROM reservations r 
        JOIN vehicles v ON r.vehicle_id = v.id 
        WHERE v.name = ? 
        AND r.status = 'approved' 
        AND (
            (r.start_datetime <= ? AND r.end_datetime >= ?) OR
            (r.start_datetime <= ? AND r.end_datetime >= ?) OR
            (r.start_datetime >= ? AND r.end_datetime <= ?)
        )
    ");
    
    $stmt->execute([
        $test_vehicle, 
        $test_start, $test_start,
        $test_end, $test_end,
        $test_start, $test_end
    ]);
    
    $local_conflicts = $stmt->fetchAll();
    
    if (!empty($local_conflicts)) {
        echo "<p style='color: orange;'>⚠️ Lokale Konflikte gefunden:</p>";
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>ID</th><th>Antragsteller</th><th>Grund</th><th>Start</th><th>Ende</th><th>Status</th></tr>";
        foreach ($local_conflicts as $conflict) {
            echo "<tr>";
            echo "<td>" . $conflict['id'] . "</td>";
            echo "<td>" . htmlspecialchars($conflict['requester_name']) . "</td>";
            echo "<td>" . htmlspecialchars($conflict['reason']) . "</td>";
            echo "<td>" . $conflict['start_datetime'] . "</td>";
            echo "<td>" . $conflict['end_datetime'] . "</td>";
            echo "<td>" . $conflict['status'] . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p style='color: green;'>✅ Keine lokalen Konflikte gefunden</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler bei lokaler Prüfung: " . $e->getMessage() . "</p>";
}

// 4. Teste AJAX-Endpoint
echo "<h2>4. AJAX-Endpoint Test</h2>";

$url = 'http://192.168.10.150/admin/check-calendar-conflicts.php';
$data = [
    'vehicle_name' => $test_vehicle,
    'start_datetime' => $test_start,
    'end_datetime' => $test_end
];

$options = [
    'http' => [
        'header' => "Content-Type: application/json\r\n",
        'method' => 'POST',
        'content' => json_encode($data)
    ]
];

$context = stream_context_create($options);
$result = file_get_contents($url, false, $context);

echo "<h3>4.1 HTTP Response</h3>";
echo "<pre>" . htmlspecialchars($result) . "</pre>";

echo "<h3>4.2 JSON Decode Test</h3>";
$json_result = json_decode($result, true);
if ($json_result) {
    echo "<p style='color: green;'>✅ JSON erfolgreich dekodiert</p>";
    echo "<pre>" . print_r($json_result, true) . "</pre>";
    
    if (isset($json_result['conflicts']) && !empty($json_result['conflicts'])) {
        echo "<p style='color: orange;'>⚠️ AJAX-Endpoint meldet Konflikte</p>";
        foreach ($json_result['conflicts'] as $conflict) {
            echo "<p><strong>Konflikt:</strong> " . htmlspecialchars($conflict['title']) . " (" . $conflict['start'] . " - " . $conflict['end'] . ")</p>";
        }
    } else {
        echo "<p style='color: green;'>✅ AJAX-Endpoint meldet keine Konflikte</p>";
    }
} else {
    echo "<p style='color: red;'>❌ JSON-Dekodierung fehlgeschlagen</p>";
    echo "<p>JSON-Fehler: " . json_last_error_msg() . "</p>";
}

echo "<hr>";
echo "<p><a href='admin/dashboard.php'>→ Zum Dashboard</a></p>";
echo "<p><a href='debug-dashboard-calendar-check.php'>→ Dashboard Debug</a></p>";
echo "<p><small>Zeitstempel: " . date('Y-m-d H:i:s') . "</small></p>";
?>
