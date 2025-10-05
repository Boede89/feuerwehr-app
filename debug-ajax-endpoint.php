<?php
/**
 * Debug: AJAX-Endpoint testen
 */

echo "<h1>🔍 AJAX-Endpoint Debug</h1>";

// 1. Teste den Endpoint direkt
echo "<h2>1. Direkter Test des Endpoints</h2>";

$url = 'http://192.168.10.150/admin/check-calendar-conflicts.php';
$data = [
    'vehicle_name' => 'MTF',
    'start_datetime' => '2025-01-15 10:00:00',
    'end_datetime' => '2025-01-15 12:00:00'
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

echo "<h3>1.1 HTTP Response</h3>";
echo "<pre>" . htmlspecialchars($result) . "</pre>";

echo "<h3>1.2 JSON Decode Test</h3>";
$json_result = json_decode($result, true);
if ($json_result) {
    echo "<p style='color: green;'>✅ JSON erfolgreich dekodiert</p>";
    echo "<pre>" . print_r($json_result, true) . "</pre>";
} else {
    echo "<p style='color: red;'>❌ JSON-Dekodierung fehlgeschlagen</p>";
    echo "<p>JSON-Fehler: " . json_last_error_msg() . "</p>";
}

// 2. Teste mit cURL
echo "<h2>2. cURL Test</h2>";

if (function_exists('curl_init')) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_VERBOSE, true);
    
    $curl_result = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);
    
    echo "<h3>2.1 cURL Response</h3>";
    echo "<p><strong>HTTP Code:</strong> $http_code</p>";
    if ($curl_error) {
        echo "<p style='color: red;'><strong>cURL Error:</strong> $curl_error</p>";
    }
    echo "<pre>" . htmlspecialchars($curl_result) . "</pre>";
} else {
    echo "<p style='color: orange;'>⚠️ cURL nicht verfügbar</p>";
}

// 3. JavaScript Test
echo "<h2>3. JavaScript Test</h2>";
echo "<button type='button' class='btn btn-primary' onclick='testAjax()'>AJAX Test</button>";
echo "<div id='ajax-result'></div>";

echo "<script>";
echo "function testAjax() {";
echo "  const resultDiv = document.getElementById('ajax-result');";
echo "  resultDiv.innerHTML = '<p>Teste AJAX...</p>';";
echo "  ";
echo "  fetch('admin/check-calendar-conflicts.php', {";
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
echo "<p><small>Zeitstempel: " . date('Y-m-d H:i:s') . "</small></p>";
?>
