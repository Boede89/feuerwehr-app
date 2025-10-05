<?php
/**
 * Debug: Weiterleitungs-Problem
 */

require_once 'config/database.php';
require_once 'includes/functions.php';

echo "<h1>🔍 Debug: Weiterleitungs-Problem</h1>";

// Hole eine echte vehicle_id
$stmt = $db->prepare("SELECT id FROM vehicles LIMIT 1");
$stmt->execute();
$vehicle = $stmt->fetch();
$vehicle_id = $vehicle ? $vehicle['id'] : 1;

// Simuliere Force Submit POST
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST['force_submit_reservation'] = '1';
$_POST['csrf_token'] = generate_csrf_token();
$_POST['conflict_vehicle_id'] = $vehicle_id;
$_POST['conflict_start_datetime'] = '2025-01-15 10:00:00';
$_POST['conflict_end_datetime'] = '2025-01-15 12:00:00';
$_POST['requester_name'] = 'Test User';
$_POST['requester_email'] = 'test@example.com';
$_POST['reason'] = 'Test Debug';
$_POST['location'] = 'Test-Ort';

echo "<h2>1. POST-Daten simulieren</h2>";
echo "<pre>";
print_r($_POST);
echo "</pre>";

echo "<h2>2. Teste Force Submit Logik</h2>";

$vehicle_id = (int)($_POST['conflict_vehicle_id'] ?? 0);
$requester_name = sanitize_input($_POST['requester_name'] ?? '');
$requester_email = sanitize_input($_POST['requester_email'] ?? '');
$reason = sanitize_input($_POST['reason'] ?? '');
$location = sanitize_input($_POST['location'] ?? '');
$start_datetime = sanitize_input($_POST['conflict_start_datetime'] ?? '');
$end_datetime = sanitize_input($_POST['conflict_end_datetime'] ?? '');

echo "<p><strong>Vehicle ID:</strong> $vehicle_id</p>";
echo "<p><strong>Requester Name:</strong> $requester_name</p>";
echo "<p><strong>Requester Email:</strong> $requester_email</p>";
echo "<p><strong>Reason:</strong> $reason</p>";
echo "<p><strong>Location:</strong> $location</p>";
echo "<p><strong>Start DateTime:</strong> $start_datetime</p>";
echo "<p><strong>End DateTime:</strong> $end_datetime</p>";

if ($vehicle_id && $requester_name && $requester_email && $reason && $start_datetime && $end_datetime) {
    echo "<p style='color: green;'>✅ Alle Daten sind vorhanden</p>";
    
    try {
        $stmt = $db->prepare("INSERT INTO reservations (vehicle_id, requester_name, requester_email, reason, location, start_datetime, end_datetime, calendar_conflicts) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$vehicle_id, $requester_name, $requester_email, $reason, $location, $start_datetime, $end_datetime, json_encode([])]);
        
        echo "<p style='color: green;'>✅ Reservierung erfolgreich gespeichert</p>";
        $message = "Reservierung wurde trotz Konflikt erfolgreich eingereicht.";
        $redirect_to_home = true;
        
        echo "<p style='color: green;'>✅ redirect_to_home Flag gesetzt: " . ($redirect_to_home ? 'true' : 'false') . "</p>";
        
    } catch(PDOException $e) {
        echo "<p style='color: red;'>❌ Fehler beim Speichern: " . $e->getMessage() . "</p>";
        $error = "Fehler beim Speichern der Reservierung: " . $e->getMessage();
        $redirect_to_home = true;
        
        echo "<p style='color: orange;'>⚠️ redirect_to_home Flag auch bei Fehler gesetzt: " . ($redirect_to_home ? 'true' : 'false') . "</p>";
    }
} else {
    echo "<p style='color: red;'>❌ Ungültige Daten</p>";
    $error = "Ungültige Daten für die Konflikt-Reservierung.";
    $redirect_to_home = true;
    
    echo "<p style='color: orange;'>⚠️ redirect_to_home Flag auch bei ungültigen Daten gesetzt: " . ($redirect_to_home ? 'true' : 'false') . "</p>";
}

echo "<h2>3. JavaScript Test</h2>";

if (isset($redirect_to_home) && $redirect_to_home) {
    echo "<p style='color: green;'>✅ redirect_to_home ist gesetzt - JavaScript sollte ausgeführt werden</p>";
    echo "<div id='countdown-test'>Countdown Test läuft...</div>";
    
    echo "<script>";
    echo "let countdown = 3;";
    echo "const countdownInterval = setInterval(function() {";
    echo "  document.getElementById('countdown-test').innerHTML = 'Weiterleitung zur Startseite in ' + countdown + ' Sekunden...';";
    echo "  countdown--;";
    echo "  if (countdown < 0) {";
    echo "    clearInterval(countdownInterval);";
    echo "    document.getElementById('countdown-test').innerHTML = '✅ Weiterleitung würde jetzt erfolgen: <a href=\"index.php\">index.php</a>';";
    echo "  }";
    echo "}, 1000);";
    echo "</script>";
} else {
    echo "<p style='color: red;'>❌ redirect_to_home ist NICHT gesetzt</p>";
}

echo "<hr>";
echo "<p><a href='test-force-submit-redirect.php'>→ Zurück zum Force Submit Test</a></p>";
echo "<p><small>Zeitstempel: " . date('Y-m-d H:i:s') . "</small></p>";
?>
