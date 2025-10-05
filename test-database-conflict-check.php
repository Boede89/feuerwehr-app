<?php
/**
 * Test: Datenbank-basierte Konflikt-Prüfung
 */

require_once 'config/database.php';
require_once 'includes/functions.php';

echo "<h1>🔍 Datenbank-basierte Konflikt-Prüfung Test</h1>";

// 1. Zeige alle genehmigten Reservierungen
echo "<h2>1. Alle genehmigten Reservierungen in der Datenbank</h2>";

try {
    $stmt = $db->prepare("
        SELECT r.*, v.name as vehicle_name 
        FROM reservations r 
        JOIN vehicles v ON r.vehicle_id = v.id 
        WHERE r.status = 'approved'
        ORDER BY r.start_datetime DESC
    ");
    $stmt->execute();
    $approved_reservations = $stmt->fetchAll();
    
    if (!empty($approved_reservations)) {
        echo "<table border='1' cellpadding='5' style='width: 100%;'>";
        echo "<tr><th>ID</th><th>Fahrzeug</th><th>Antragsteller</th><th>Grund</th><th>Start</th><th>Ende</th><th>Status</th></tr>";
        foreach ($approved_reservations as $reservation) {
            echo "<tr>";
            echo "<td>" . $reservation['id'] . "</td>";
            echo "<td>" . htmlspecialchars($reservation['vehicle_name']) . "</td>";
            echo "<td>" . htmlspecialchars($reservation['requester_name']) . "</td>";
            echo "<td>" . htmlspecialchars($reservation['reason']) . "</td>";
            echo "<td>" . $reservation['start_datetime'] . "</td>";
            echo "<td>" . $reservation['end_datetime'] . "</td>";
            echo "<td>" . $reservation['status'] . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p style='color: orange;'>⚠️ Keine genehmigten Reservierungen gefunden</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim Laden der Reservierungen: " . $e->getMessage() . "</p>";
}

// 2. Teste Konflikt-Prüfung für verschiedene Zeiträume
echo "<h2>2. Konflikt-Prüfung für verschiedene Zeiträume</h2>";

$test_cases = [
    [
        'vehicle' => 'MTF',
        'start' => '2025-01-15 10:00:00',
        'end' => '2025-01-15 12:00:00',
        'description' => 'MTF 15.01.2025 10:00-12:00'
    ],
    [
        'vehicle' => 'LF',
        'start' => '2025-01-20 14:00:00',
        'end' => '2025-01-20 16:00:00',
        'description' => 'LF 20.01.2025 14:00-16:00'
    ],
    [
        'vehicle' => 'MTF',
        'start' => '2025-12-25 08:00:00',
        'end' => '2025-12-25 18:00:00',
        'description' => 'MTF 25.12.2025 08:00-18:00 (Weihnachten)'
    ]
];

foreach ($test_cases as $i => $test_case) {
    echo "<h3>2." . ($i + 1) . " Test: " . $test_case['description'] . "</h3>";
    
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
            $test_case['vehicle'], 
            $test_case['start'], $test_case['start'],
            $test_case['end'], $test_case['end'],
            $test_case['start'], $test_case['end']
        ]);
        
        $conflicts = $stmt->fetchAll();
        
        if (!empty($conflicts)) {
            echo "<p style='color: orange;'>⚠️ Konflikte gefunden:</p>";
            echo "<ul>";
            foreach ($conflicts as $conflict) {
                echo "<li>";
                echo "<strong>ID:</strong> " . $conflict['id'] . "<br>";
                echo "<strong>Antragsteller:</strong> " . htmlspecialchars($conflict['requester_name']) . "<br>";
                echo "<strong>Grund:</strong> " . htmlspecialchars($conflict['reason']) . "<br>";
                echo "<strong>Zeitraum:</strong> " . $conflict['start_datetime'] . " - " . $conflict['end_datetime'] . "<br>";
                echo "</li>";
            }
            echo "</ul>";
        } else {
            echo "<p style='color: green;'>✅ Keine Konflikte gefunden</p>";
        }
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Fehler bei Konflikt-Prüfung: " . $e->getMessage() . "</p>";
    }
}

// 3. Teste AJAX-Endpoint
echo "<h2>3. AJAX-Endpoint Test</h2>";

$test_vehicle = 'MTF';
$test_start = '2025-01-15 10:00:00';
$test_end = '2025-01-15 12:00:00';

echo "<p><strong>Test-Parameter:</strong></p>";
echo "<ul>";
echo "<li>Fahrzeug: $test_vehicle</li>";
echo "<li>Start: $test_start</li>";
echo "<li>Ende: $test_end</li>";
echo "</ul>";

$url = 'http://192.168.10.150/admin/check-calendar-conflicts-simple.php';
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

echo "<h3>3.1 HTTP Response</h3>";
echo "<pre>" . htmlspecialchars($result) . "</pre>";

echo "<h3>3.2 JSON Decode Test</h3>";
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

// 4. JavaScript Test
echo "<h2>4. JavaScript Test</h2>";
echo "<button type='button' class='btn btn-primary' onclick='testAjax()'>AJAX Test</button>";
echo "<div id='ajax-result'></div>";

echo "<script>";
echo "function testAjax() {";
echo "  const resultDiv = document.getElementById('ajax-result');";
echo "  resultDiv.innerHTML = '<p>Teste AJAX...</p>';";
echo "  ";
echo "  fetch('admin/check-calendar-conflicts-simple.php', {";
echo "    method: 'POST',";
echo "    headers: { 'Content-Type': 'application/json' },";
echo "    body: JSON.stringify({";
echo "      vehicle_name: 'MTF',";
echo "      start_datetime: '2025-01-15 10:00:00',";
echo "      end_datetime: '2025-01-15 12:00:00'";
echo "    })";
echo "  })";
echo "  .then(response => {";
echo "    resultDiv.innerHTML += '<p><strong>Response Status:</strong> ' + response.status + '</p>';";
echo "    return response.text();";
echo "  })";
echo "  .then(text => {";
echo "    resultDiv.innerHTML += '<p><strong>Response Text:</strong></p><pre>' + text + '</pre>';";
echo "    try {";
echo "      const json = JSON.parse(text);";
echo "      resultDiv.innerHTML += '<p style=\"color: green;\">✅ JSON erfolgreich geparst</p>';";
echo "      resultDiv.innerHTML += '<pre>' + JSON.stringify(json, null, 2) + '</pre>';";
echo "    } catch (e) {";
echo "      resultDiv.innerHTML += '<p style=\"color: red;\">❌ JSON Parse Error: ' + e.message + '</p>';";
echo "    }";
echo "  })";
echo "  .catch(error => {";
echo "    resultDiv.innerHTML += '<p style=\"color: red;\">❌ Fetch Error: ' + error.message + '</p>';";
echo "  });";
echo "}";
echo "</script>";

echo "<hr>";
echo "<p><a href='admin/dashboard.php'>→ Zum Dashboard</a></p>";
echo "<p><a href='debug-dashboard-calendar-check.php'>→ Dashboard Debug</a></p>";
echo "<p><small>Zeitstempel: " . date('Y-m-d H:i:s') . "</small></p>";
?>
