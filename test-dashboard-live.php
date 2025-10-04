<?php
/**
 * Test: Dashboard Live - Teste das Dashboard live mit echten Daten
 */

echo "<h1>Test: Dashboard Live - Teste das Dashboard live mit echten Daten</h1>";

// Lade Funktionen
require_once 'config/database.php';

echo "<h2>1. Teste Dashboard-Logik live</h2>";

try {
    // Alle Reservierungen (ausstehend + bearbeitet)
    $stmt = $db->prepare("
        SELECT r.*, v.name as vehicle_name
        FROM reservations r
        JOIN vehicles v ON r.vehicle_id = v.id
        ORDER BY r.created_at DESC
    ");
    $stmt->execute();
    $all_reservations = $stmt->fetchAll();
    
    echo "<p>✅ Alle Reservierungen geladen: " . count($all_reservations) . "</p>";
    
    // Trenne in ausstehend und bearbeitet
    $pending_reservations = array_filter($all_reservations, function($r) {
        return $r['status'] === 'pending';
    });
    
    $processed_reservations = array_filter($all_reservations, function($r) {
        return in_array($r['status'], ['approved', 'rejected']);
    });
    
    echo "<p>✅ Ausstehende Reservierungen: " . count($pending_reservations) . "</p>";
    echo "<p>✅ Bearbeitete Reservierungen: " . count($processed_reservations) . "</p>";
    
    echo "<h2>2. Simuliere Dashboard-HTML</h2>";
    
    // Simuliere den Dashboard-HTML-Bereich
    echo "<div style='border: 2px solid #007bff; padding: 20px; margin: 20px 0; background: #f8f9fa;'>";
    echo "<h3>Offene Anträge (" . count($pending_reservations) . ")</h3>";
    
    if (empty($pending_reservations)) {
        echo "<div style='text-align: center; padding: 20px;'>";
        echo "<p style='color: red; font-size: 18px;'>❌ Keine ausstehenden Anträge</p>";
        echo "<p>Alle Anträge wurden bearbeitet.</p>";
        echo "</div>";
    } else {
        echo "<div style='color: green; font-size: 18px; margin-bottom: 20px;'>";
        echo "✅ Zeige " . count($pending_reservations) . " ausstehende Anträge:";
        echo "</div>";
        
        // Mobile-optimierte Karten-Ansicht
        echo "<div style='display: block;'>";
        foreach ($pending_reservations as $reservation) {
            echo "<div style='border: 1px solid #ddd; padding: 15px; margin: 10px 0; background: white; border-radius: 5px;'>";
            echo "<div style='display: flex; justify-content: space-between; align-items: start; margin-bottom: 10px;'>";
            echo "<h6 style='margin: 0;'>";
            echo "🚛 <strong>" . htmlspecialchars($reservation['vehicle_name']) . "</strong>";
            echo "</h6>";
            echo "<span style='background: #ffc107; color: #000; padding: 2px 8px; border-radius: 3px; font-size: 12px;'>AUSSTEHEND</span>";
            echo "</div>";
            echo "<p style='margin: 5px 0;'><strong>Grund:</strong> " . htmlspecialchars($reservation['reason']) . "</p>";
            echo "<p style='margin: 5px 0;'><strong>Zeitraum:</strong> " . $reservation['start_datetime'] . " - " . $reservation['end_datetime'] . "</p>";
            echo "<p style='margin: 5px 0;'><strong>Antragsteller:</strong> " . htmlspecialchars($reservation['requester_name']) . "</p>";
            echo "<div style='margin-top: 15px;'>";
            echo "<button style='background: #28a745; color: white; border: none; padding: 8px 16px; margin-right: 10px; border-radius: 3px;'>Genehmigen</button>";
            echo "<button style='background: #dc3545; color: white; border: none; padding: 8px 16px; margin-right: 10px; border-radius: 3px;'>Ablehnen</button>";
            echo "<button style='background: #007bff; color: white; border: none; padding: 8px 16px; border-radius: 3px;'>Details</button>";
            echo "</div>";
            echo "</div>";
        }
        echo "</div>";
        
        // Desktop-optimierte Tabellen-Ansicht
        echo "<div style='display: none;'>";
        echo "<table style='width: 100%; border-collapse: collapse;'>";
        echo "<thead style='background: #f8f9fa;'>";
        echo "<tr>";
        echo "<th style='padding: 10px; border: 1px solid #ddd;'>Fahrzeug</th>";
        echo "<th style='padding: 10px; border: 1px solid #ddd;'>Datum/Zeit</th>";
        echo "<th style='padding: 10px; border: 1px solid #ddd;'>Grund</th>";
        echo "<th style='padding: 10px; border: 1px solid #ddd;'>Antragsteller</th>";
        echo "<th style='padding: 10px; border: 1px solid #ddd;'>Aktion</th>";
        echo "</tr>";
        echo "</thead>";
        echo "<tbody>";
        foreach ($pending_reservations as $reservation) {
            echo "<tr>";
            echo "<td style='padding: 10px; border: 1px solid #ddd;'>";
            echo "🚛 <strong>" . htmlspecialchars($reservation['vehicle_name']) . "</strong>";
            echo "</td>";
            echo "<td style='padding: 10px; border: 1px solid #ddd;'>";
            echo $reservation['start_datetime'] . "<br><small>" . $reservation['end_datetime'] . "</small>";
            echo "</td>";
            echo "<td style='padding: 10px; border: 1px solid #ddd;'>" . htmlspecialchars($reservation['reason']) . "</td>";
            echo "<td style='padding: 10px; border: 1px solid #ddd;'>" . htmlspecialchars($reservation['requester_name']) . "</td>";
            echo "<td style='padding: 10px; border: 1px solid #ddd;'>";
            echo "<button style='background: #28a745; color: white; border: none; padding: 5px 10px; margin-right: 5px; border-radius: 3px;'>Genehmigen</button>";
            echo "<button style='background: #dc3545; color: white; border: none; padding: 5px 10px; margin-right: 5px; border-radius: 3px;'>Ablehnen</button>";
            echo "<button style='background: #007bff; color: white; border: none; padding: 5px 10px; border-radius: 3px;'>Details</button>";
            echo "</td>";
            echo "</tr>";
        }
        echo "</tbody>";
        echo "</table>";
        echo "</div>";
    }
    echo "</div>";
    
    echo "<h2>3. Debug-Informationen</h2>";
    
    echo "<h3>3.1 Variable-Details:</h3>";
    echo "<p>gettype(\$pending_reservations): " . gettype($pending_reservations) . "</p>";
    echo "<p>is_array(\$pending_reservations): " . (is_array($pending_reservations) ? 'JA' : 'NEIN') . "</p>";
    echo "<p>count(\$pending_reservations): " . count($pending_reservations) . "</p>";
    echo "<p>empty(\$pending_reservations): " . (empty($pending_reservations) ? 'JA' : 'NEIN') . "</p>";
    
    echo "<h3>3.2 Array-Inhalt:</h3>";
    echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ddd; overflow-x: auto;'>";
    print_r($pending_reservations);
    echo "</pre>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler: " . $e->getMessage() . "</p>";
}

echo "<h2>4. Nächste Schritte</h2>";
echo "<p>1. <a href='admin/dashboard.php'>Teste das Dashboard</a> - Prüfe die Debug-Logs</p>";
echo "<p>2. <a href='debug-google-calendar-fixed.php'>Prüfe die Logs</a></p>";

echo "<hr>";
echo "<p><small>Zeitstempel: " . date('Y-m-d H:i:s') . "</small></p>";
?>
