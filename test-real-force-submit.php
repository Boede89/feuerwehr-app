<?php
/**
 * Test: Echter Force Submit Test
 */

require_once 'config/database.php';
require_once 'includes/functions.php';

echo "<h1>🧪 Echter Force Submit Test</h1>";

// 1. Erstelle Test-Konflikt mit echten Daten
echo "<h2>1. Erstelle Test-Konflikt</h2>";

try {
    // Prüfe verfügbare Fahrzeuge
    $stmt = $db->prepare("SELECT id, name FROM vehicles LIMIT 1");
    $stmt->execute();
    $vehicle = $stmt->fetch();
    
    if (!$vehicle) {
        echo "<p style='color: red;'>❌ Keine Fahrzeuge gefunden</p>";
        exit;
    }
    
    echo "<p>Verwende Fahrzeug: " . htmlspecialchars($vehicle['name']) . " (ID: " . $vehicle['id'] . ")</p>";
    
    // Erstelle erste Reservierung (wird genehmigt)
    $start_datetime = date('Y-m-d H:i:s', strtotime('+1 day 15:00'));
    $end_datetime = date('Y-m-d H:i:s', strtotime('+1 day 17:00'));
    
    $stmt = $db->prepare("
        INSERT INTO reservations (vehicle_id, requester_name, requester_email, reason, location, start_datetime, end_datetime, status, created_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, 'approved', NOW())
    ");
    
    $result = $stmt->execute([
        $vehicle['id'],
        'Max Mustermann',
        'max.mustermann@example.com',
        'Übung: Brandbekämpfung',
        'Feuerwehrhaus',
        $start_datetime,
        $end_datetime
    ]);
    
    if ($result) {
        echo "<p style='color: green;'>✅ Test-Reservierung erstellt</p>";
        echo "<p><strong>Zeitraum:</strong> $start_datetime - $end_datetime</p>";
    } else {
        echo "<p style='color: red;'>❌ Fehler beim Erstellen der Test-Reservierung</p>";
        exit;
    }
    
    // 2. Teste Force Submit mit echten Daten
    echo "<h2>2. Teste Force Submit mit echten Daten</h2>";
    
    echo "<form method='POST' action='reservation.php' id='forceSubmitForm'>";
    echo "<input type='hidden' name='csrf_token' value='" . generate_csrf_token() . "'>";
    echo "<input type='hidden' name='conflict_vehicle_id' value='" . $vehicle['id'] . "'>";
    echo "<input type='hidden' name='conflict_start_datetime' value='$start_datetime'>";
    echo "<input type='hidden' name='conflict_end_datetime' value='$end_datetime'>";
    echo "<input type='hidden' name='requester_name' value='Test User 2'>";
    echo "<input type='hidden' name='requester_email' value='test2@example.com'>";
    echo "<input type='hidden' name='reason' value='Test Force Submit'>";
    echo "<input type='hidden' name='location' value='Test-Ort'>";
    echo "<button type='submit' name='force_submit_reservation' class='btn btn-warning'>";
    echo "<i class='fas fa-exclamation-triangle'></i> Teste Force Submit (echte Daten)";
    echo "</button>";
    echo "</form>";
    
    echo "<p><small>Klicken Sie auf den Button, um den echten Force Submit zu testen.</small></p>";
    
    // 3. JavaScript für Debug
    echo "<h2>3. Debug-Informationen</h2>";
    echo "<div id='debug-info'></div>";
    
    echo "<script>";
    echo "document.getElementById('forceSubmitForm').addEventListener('submit', function(e) {";
    echo "  console.log('🔍 Force Submit Form wird abgesendet...');";
    echo "  console.log('Vehicle ID:', " . $vehicle['id'] . ");";
    echo "  console.log('Start DateTime:', '$start_datetime');";
    echo "  console.log('End DateTime:', '$end_datetime');";
    echo "});";
    echo "</script>";
    
    // 4. Zeige bestehende Reservierungen
    echo "<h2>4. Bestehende Reservierungen</h2>";
    
    $stmt = $db->prepare("
        SELECT r.*, v.name as vehicle_name 
        FROM reservations r 
        JOIN vehicles v ON r.vehicle_id = v.id 
        WHERE v.id = ? 
        ORDER BY r.created_at DESC
    ");
    $stmt->execute([$vehicle['id']]);
    $reservations = $stmt->fetchAll();
    
    if (!empty($reservations)) {
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>ID</th><th>Status</th><th>Antragsteller</th><th>Start</th><th>Ende</th><th>Grund</th></tr>";
        foreach ($reservations as $reservation) {
            $status_color = $reservation['status'] === 'approved' ? 'green' : ($reservation['status'] === 'pending' ? 'orange' : 'red');
            echo "<tr>";
            echo "<td>" . $reservation['id'] . "</td>";
            echo "<td style='color: $status_color;'><strong>" . strtoupper($reservation['status']) . "</strong></td>";
            echo "<td>" . htmlspecialchars($reservation['requester_name']) . "</td>";
            echo "<td>" . $reservation['start_datetime'] . "</td>";
            echo "<td>" . $reservation['end_datetime'] . "</td>";
            echo "<td>" . htmlspecialchars($reservation['reason']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p style='color: orange;'>⚠️ Keine Reservierungen gefunden</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler: " . $e->getMessage() . "</p>";
}

echo "<hr>";
echo "<p><a href='reservation.php'>→ Zur Reservierungsseite</a></p>";
echo "<p><a href='index.php'>→ Zur Startseite</a></p>";
echo "<p><small>Zeitstempel: " . date('Y-m-d H:i:s') . "</small></p>";
?>
