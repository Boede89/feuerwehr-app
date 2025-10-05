<?php
/**
 * Test: Reservierungs-Konflikt-Behandlung
 */

require_once 'config/database.php';
require_once 'includes/functions.php';

echo "<h1>🧪 Reservierungs-Konflikt Test</h1>";

// 1. Erstelle Test-Reservierung
echo "<h2>1. Erstelle Test-Reservierung</h2>";

try {
    // Prüfe verfügbare Fahrzeuge
    $stmt = $db->prepare("SELECT id, name FROM vehicles LIMIT 1");
    $stmt->execute();
    $vehicle = $stmt->fetch();
    
    if (!$vehicle) {
        echo "<p style='color: red;'>❌ Keine Fahrzeuge gefunden</p>";
        exit;
    }
    
    echo "<p>Verwende Fahrzeug: " . htmlspecialchars($vehicle['name']) . "</p>";
    
    // Erstelle erste Reservierung (wird genehmigt)
    $start_datetime = date('Y-m-d H:i:s', strtotime('+1 day 10:00'));
    $end_datetime = date('Y-m-d H:i:s', strtotime('+1 day 12:00'));
    
    $stmt = $db->prepare("
        INSERT INTO reservations (vehicle_id, requester_name, requester_email, reason, location, start_datetime, end_datetime, status, created_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, 'approved', NOW())
    ");
    
    $result = $stmt->execute([
        $vehicle['id'],
        'Test User 1',
        'test1@example.com',
        'Erste Test-Reservierung',
        'Test-Ort',
        $start_datetime,
        $end_datetime
    ]);
    
    if ($result) {
        echo "<p style='color: green;'>✅ Erste Reservierung erstellt (genehmigt)</p>";
        echo "<p><strong>Zeitraum:</strong> $start_datetime - $end_datetime</p>";
    } else {
        echo "<p style='color: red;'>❌ Fehler beim Erstellen der ersten Reservierung</p>";
        exit;
    }
    
    // 2. Teste Konflikt-Prüfung
    echo "<h2>2. Teste Konflikt-Prüfung</h2>";
    
    // Gleicher Zeitraum (sollte Konflikt ergeben)
    $conflict_start = $start_datetime;
    $conflict_end = $end_datetime;
    
    echo "<p><strong>Teste Konflikt für:</strong> $conflict_start - $conflict_end</p>";
    
    if (function_exists('check_vehicle_conflict')) {
        $has_conflict = check_vehicle_conflict($vehicle['id'], $conflict_start, $conflict_end);
        
        if ($has_conflict) {
            echo "<p style='color: orange;'>⚠️ Konflikt erkannt - Das ist korrekt!</p>";
        } else {
            echo "<p style='color: red;'>❌ Kein Konflikt erkannt - Das ist falsch!</p>";
        }
    } else {
        echo "<p style='color: red;'>❌ check_vehicle_conflict Funktion nicht verfügbar</p>";
    }
    
    // 3. Simuliere Reservierungsformular
    echo "<h2>3. Simuliere Reservierungsformular</h2>";
    
    echo "<form method='POST' action='reservation.php'>";
    echo "<input type='hidden' name='csrf_token' value='" . generate_csrf_token() . "'>";
    echo "<input type='hidden' name='vehicle_id' value='" . $vehicle['id'] . "'>";
    echo "<input type='hidden' name='requester_name' value='Test User 2'>";
    echo "<input type='hidden' name='requester_email' value='test2@example.com'>";
    echo "<input type='hidden' name='reason' value='Zweite Test-Reservierung'>";
    echo "<input type='hidden' name='location' value='Test-Ort'>";
    echo "<input type='hidden' name='start_datetime_0' value='$conflict_start'>";
    echo "<input type='hidden' name='end_datetime_0' value='$conflict_end'>";
    echo "<button type='submit' name='submit_reservation' class='btn btn-primary'>Teste Konflikt-Modal</button>";
    echo "</form>";
    
    echo "<p><small>Klicken Sie auf den Button, um das Konflikt-Modal zu testen.</small></p>";
    
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
echo "<p><small>Zeitstempel: " . date('Y-m-d H:i:s') . "</small></p>";
?>
