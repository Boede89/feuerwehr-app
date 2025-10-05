<?php
/**
 * Test: Kalender-Konflikt-Prüfung
 */

require_once 'config/database.php';
require_once 'includes/functions.php';
require_once 'includes/google_calendar_service_account.php';
require_once 'includes/google_calendar.php';

echo "<h1>🔍 Kalender-Konflikt-Prüfung Test</h1>";

// 1. Prüfe Funktion
echo "<h2>1. Funktion prüfen</h2>";

if (function_exists('check_calendar_conflicts')) {
    echo "<p style='color: green;'>✅ check_calendar_conflicts Funktion verfügbar</p>";
} else {
    echo "<p style='color: red;'>❌ check_calendar_conflicts Funktion NICHT verfügbar</p>";
    exit;
}

// 2. Teste mit einer echten Reservierung
echo "<h2>2. Teste mit echter Reservierung</h2>";

try {
    $stmt = $db->prepare("SELECT r.*, v.name as vehicle_name FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id WHERE r.status = 'pending' ORDER BY r.created_at DESC LIMIT 1");
    $stmt->execute();
    $reservation = $stmt->fetch();
    
    if ($reservation) {
        echo "<p style='color: green;'>✅ Test-Reservierung gefunden:</p>";
        echo "<ul>";
        echo "<li><strong>ID:</strong> " . $reservation['id'] . "</li>";
        echo "<li><strong>Fahrzeug:</strong> " . htmlspecialchars($reservation['vehicle_name']) . "</li>";
        echo "<li><strong>Start:</strong> " . $reservation['start_datetime'] . "</li>";
        echo "<li><strong>Ende:</strong> " . $reservation['end_datetime'] . "</li>";
        echo "</ul>";
        
        // Teste Kalender-Konflikt-Prüfung
        echo "<h3>2.1 Kalender-Konflikt-Prüfung</h3>";
        
        echo "<p>Prüfe Konflikte für Fahrzeug '{$reservation['vehicle_name']}' im Zeitraum {$reservation['start_datetime']} - {$reservation['end_datetime']}...</p>";
        
        $conflicts = check_calendar_conflicts(
            $reservation['vehicle_name'],
            $reservation['start_datetime'],
            $reservation['end_datetime']
        );
        
        if (!empty($conflicts)) {
            echo "<div style='background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; border-radius: 5px; margin: 10px 0;'>";
            echo "<h4 style='color: #856404; margin-top: 0;'>⚠️ Konflikte gefunden!</h4>";
            echo "<p><strong>Anzahl Konflikte:</strong> " . count($conflicts) . "</p>";
            echo "<ul>";
            foreach ($conflicts as $conflict) {
                echo "<li>";
                echo "<strong>Titel:</strong> " . htmlspecialchars($conflict['title']) . "<br>";
                echo "<strong>Start:</strong> " . date('d.m.Y H:i', strtotime($conflict['start'])) . "<br>";
                echo "<strong>Ende:</strong> " . date('d.m.Y H:i', strtotime($conflict['end']));
                echo "</li>";
            }
            echo "</ul>";
            echo "</div>";
        } else {
            echo "<div style='background: #d4edda; border: 1px solid #c3e6cb; padding: 15px; border-radius: 5px; margin: 10px 0;'>";
            echo "<h4 style='color: #155724; margin-top: 0;'>✅ Keine Konflikte</h4>";
            echo "<p>Der beantragte Zeitraum ist frei.</p>";
            echo "</div>";
        }
        
    } else {
        echo "<p style='color: orange;'>⚠️ Keine ausstehenden Reservierungen gefunden</p>";
        echo "<p><a href='create-test-reservation.php'>Test-Reservierung erstellen</a></p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler: " . $e->getMessage() . "</p>";
}

// 3. Teste mit verschiedenen Zeiträumen
echo "<h2>3. Teste verschiedene Zeiträume</h2>";

$test_vehicles = ['MTF', 'LF', 'GWL', 'HLF', 'ELW'];
$test_dates = [
    ['start' => '2025-01-15 10:00:00', 'end' => '2025-01-15 12:00:00'],
    ['start' => '2025-01-20 14:00:00', 'end' => '2025-01-20 16:00:00'],
    ['start' => '2025-02-01 09:00:00', 'end' => '2025-02-01 17:00:00']
];

foreach ($test_vehicles as $vehicle) {
    echo "<h3>3.1 Teste Fahrzeug: $vehicle</h3>";
    
    foreach ($test_dates as $date) {
        echo "<p><strong>Zeitraum:</strong> {$date['start']} - {$date['end']}</p>";
        
        $conflicts = check_calendar_conflicts($vehicle, $date['start'], $date['end']);
        
        if (!empty($conflicts)) {
            echo "<p style='color: orange;'>⚠️ " . count($conflicts) . " Konflikt(e) gefunden</p>";
        } else {
            echo "<p style='color: green;'>✅ Kein Konflikt</p>";
        }
    }
}

echo "<hr>";
echo "<p><a href='admin/dashboard.php'>← Zurück zum Dashboard</a></p>";
echo "<p><small>Zeitstempel: " . date('Y-m-d H:i:s') . "</small></p>";
?>
