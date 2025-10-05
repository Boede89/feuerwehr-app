<?php
/**
 * Debug: Dashboard Kalender-Prüfung Problem
 */

require_once 'config/database.php';
require_once 'includes/functions.php';

echo "<h1>🔍 Debug: Dashboard Kalender-Prüfung Problem</h1>";

// 1. Teste AJAX-Endpoint direkt
echo "<h2>1. AJAX-Endpoint Test</h2>";

$url = 'http://192.168.10.150/admin/check-calendar-conflicts-simple.php';
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

// 4. Simuliere Dashboard Modal
echo "<h2>4. Dashboard Modal Simulation</h2>";
echo "<button type='button' class='btn btn-primary' data-bs-toggle='modal' data-bs-target='#testModal'>";
echo "Modal Test";
echo "</button>";

echo "<div class='modal fade' id='testModal' tabindex='-1' data-vehicle-name='MTF' data-start-datetime='2025-01-15 10:00:00' data-end-datetime='2025-01-15 12:00:00'>";
echo "<div class='modal-dialog'>";
echo "<div class='modal-content'>";
echo "<div class='modal-header'>";
echo "<h5 class='modal-title'>Test Modal</h5>";
echo "<button type='button' class='btn-close' data-bs-dismiss='modal'></button>";
echo "</div>";
echo "<div class='modal-body'>";
echo "<div id='calendar-check-test'>";
echo "<div class='text-center'>";
echo "<div class='spinner-border spinner-border-sm text-info' role='status' id='spinner-test'>";
echo "<span class='visually-hidden'>Lädt...</span>";
echo "</div>";
echo "<p class='text-muted mt-2' id='loading-text-test'>Prüfe Kalender-Konflikte...</p>";
echo "</div>";
echo "</div>";
echo "</div>";
echo "<div class='modal-footer'>";
echo "<button type='button' class='btn btn-secondary' data-bs-dismiss='modal'>Schließen</button>";
echo "</div>";
echo "</div>";
echo "</div>";
echo "</div>";

echo "<script src='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js'></script>";
echo "<script>";
echo "// Simuliere checkCalendarConflicts Funktion";
echo "function checkCalendarConflicts(reservationId, vehicleName, startDateTime, endDateTime) {";
echo "  console.log('🔍 checkCalendarConflicts aufgerufen:', { reservationId, vehicleName, startDateTime, endDateTime });";
echo "  ";
echo "  const container = document.getElementById('calendar-check-' + reservationId);";
echo "  const spinner = document.getElementById('spinner-' + reservationId);";
echo "  const loadingText = document.getElementById('loading-text-' + reservationId);";
echo "  ";
echo "  if (!container) {";
echo "    console.error('❌ Container nicht gefunden:', 'calendar-check-' + reservationId);";
echo "    return;";
echo "  }";
echo "  ";
echo "  console.log('✅ Container gefunden:', container);";
echo "  ";
echo "  // AJAX-Anfrage";
echo "  fetch('admin/check-calendar-conflicts-simple.php', {";
echo "    method: 'POST',";
echo "    headers: { 'Content-Type': 'application/json' },";
echo "    body: JSON.stringify({";
echo "      vehicle_name: vehicleName,";
echo "      start_datetime: startDateTime,";
echo "      end_datetime: endDateTime";
echo "    })";
echo "  })";
echo "  .then(response => {";
echo "    console.log('📡 Response Status:', response.status);";
echo "    return response.text();";
echo "  })";
echo "  .then(text => {";
echo "    console.log('📄 Response Text:', text);";
echo "    ";
echo "    try {";
echo "      const data = JSON.parse(text);";
echo "      console.log('📊 Parsed Data:', data);";
echo "      ";
echo "      if (spinner) spinner.style.display = 'none';";
echo "      if (loadingText) loadingText.style.display = 'none';";
echo "      ";
echo "      if (data.success) {";
echo "        if (data.conflicts && data.conflicts.length > 0) {";
echo "          let conflictsHtml = '<div class=\"alert alert-warning mt-2\"><strong>Warnung:</strong> Für dieses Fahrzeug existieren bereits Kalender-Einträge:<ul class=\"mb-0 mt-2\">';";
echo "          data.conflicts.forEach(conflict => {";
echo "            conflictsHtml += '<li><strong>' + conflict.title + '</strong><br><small class=\"text-muted\">' + ";
echo "              new Date(conflict.start).toLocaleString('de-DE') + ' - ' + ";
echo "              new Date(conflict.end).toLocaleString('de-DE') + '</small></li>';";
echo "          });";
echo "          conflictsHtml += '</ul></div>';";
echo "          container.innerHTML = conflictsHtml;";
echo "        } else {";
echo "          container.innerHTML = '<div class=\"alert alert-success mt-2\"><strong>Kein Konflikt:</strong> Der beantragte Zeitraum ist frei.</div>';";
echo "        }";
echo "      } else {";
echo "        container.innerHTML = '<div class=\"alert alert-danger mt-2\"><strong>Fehler:</strong> ' + (data.error || 'Kalender-Prüfung fehlgeschlagen') + '</div>';";
echo "      }";
echo "    } catch (e) {";
echo "      console.error('❌ JSON Parse Error:', e);";
echo "      if (spinner) spinner.style.display = 'none';";
echo "      if (loadingText) loadingText.style.display = 'none';";
echo "      container.innerHTML = '<div class=\"alert alert-danger mt-2\"><strong>Fehler:</strong> Ungültige Server-Antwort</div>';";
echo "    }";
echo "  })";
echo "  .catch(error => {";
echo "    console.error('❌ Fetch Error:', error);";
echo "    if (spinner) spinner.style.display = 'none';";
echo "    if (loadingText) loadingText.style.display = 'none';";
echo "    container.innerHTML = '<div class=\"alert alert-danger mt-2\"><strong>Fehler:</strong> Verbindung zum Server fehlgeschlagen</div>';";
echo "  });";
echo "}";
echo "";
echo "// Event Listener für Modal";
echo "document.addEventListener('DOMContentLoaded', function() {";
echo "  document.querySelectorAll('[data-bs-toggle=\"modal\"][data-bs-target=\"#testModal\"]').forEach(function(button) {";
echo "    button.addEventListener('click', function() {";
echo "      console.log('🔍 Modal Button geklickt');";
echo "      ";
echo "      setTimeout(function() {";
echo "        const modal = document.querySelector('#testModal');";
echo "        if (modal) {";
echo "          const vehicleName = modal.getAttribute('data-vehicle-name') || '';";
echo "          const startDateTime = modal.getAttribute('data-start-datetime') || '';";
echo "          const endDateTime = modal.getAttribute('data-end-datetime') || '';";
echo "          ";
echo "          console.log('📊 Modal-Daten:', { vehicleName, startDateTime, endDateTime });";
echo "          ";
echo "          if (vehicleName && startDateTime && endDateTime) {";
echo "            checkCalendarConflicts('test', vehicleName, startDateTime, endDateTime);";
echo "          }";
echo "        }";
echo "      }, 500);";
echo "    });";
echo "  });";
echo "});";
echo "</script>";

echo "<hr>";
echo "<p><a href='admin/dashboard.php'>→ Zum Dashboard</a></p>";
echo "<p><small>Zeitstempel: " . date('Y-m-d H:i:s') . "</small></p>";
?>
