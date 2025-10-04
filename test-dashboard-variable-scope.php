<?php
/**
 * Test: Dashboard Variable Scope - Teste ob Variablen im HTML-Bereich verfügbar sind
 */

echo "<h1>Test: Dashboard Variable Scope - Teste ob Variablen im HTML-Bereich verfügbar sind</h1>";

// Lade Funktionen
require_once 'config/database.php';

echo "<h2>1. Teste Variable Scope</h2>";

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
    
    // Trenne in ausstehend und bearbeitet
    $pending_reservations = array_filter($all_reservations, function($r) {
        return $r['status'] === 'pending';
    });
    
    $processed_reservations = array_filter($all_reservations, function($r) {
        return in_array($r['status'], ['approved', 'rejected']);
    });
    
    echo "<p>✅ Variablen gesetzt - all_reservations: " . count($all_reservations) . ", pending: " . count($pending_reservations) . "</p>";
    
    echo "<h2>2. Teste Variable Scope in verschiedenen Bereichen</h2>";
    
    echo "<h3>2.1 Vor HTML-Bereich:</h3>";
    echo "<p>all_reservations: " . count($all_reservations) . "</p>";
    echo "<p>pending_reservations: " . count($pending_reservations) . "</p>";
    echo "<p>processed_reservations: " . count($processed_reservations) . "</p>";
    
    // Simuliere HTML-Bereich
    echo "<h3>2.2 In HTML-Bereich (simuliert):</h3>";
    
    // Simuliere den Dashboard-HTML-Bereich
    echo "<div style='border: 2px solid #007bff; padding: 20px; margin: 20px 0; background: #f8f9fa;'>";
    echo "<h4>Offene Anträge (" . count($pending_reservations) . ")</h4>";
    
    // Prüfe ob Variable verfügbar ist
    if (!isset($pending_reservations)) {
        echo "<p style='color: red;'>❌ pending_reservations ist NICHT gesetzt!</p>";
        $pending_reservations = [];
    } else {
        echo "<p style='color: green;'>✅ pending_reservations ist gesetzt</p>";
    }
    
    if (empty($pending_reservations)) {
        echo "<p style='color: red;'>❌ Keine ausstehenden Anträge</p>";
    } else {
        echo "<p style='color: green;'>✅ Zeige " . count($pending_reservations) . " ausstehende Anträge</p>";
        
        // Zeige erste 3 Reservierungen
        $count = 0;
        foreach ($pending_reservations as $reservation) {
            if ($count >= 3) break;
            echo "<div style='border: 1px solid #ddd; padding: 10px; margin: 5px 0; background: white;'>";
            echo "<strong>ID " . $reservation['id'] . ":</strong> " . htmlspecialchars($reservation['vehicle_name']) . " - " . htmlspecialchars($reservation['reason']);
            echo "</div>";
            $count++;
        }
        if (count($pending_reservations) > 3) {
            echo "<p>... und " . (count($pending_reservations) - 3) . " weitere</p>";
        }
    }
    echo "</div>";
    
    echo "<h2>3. Teste Variable Scope nach HTML-Bereich</h2>";
    echo "<p>all_reservations: " . count($all_reservations) . "</p>";
    echo "<p>pending_reservations: " . count($pending_reservations) . "</p>";
    echo "<p>processed_reservations: " . count($processed_reservations) . "</p>";
    
    echo "<h2>4. Teste verschiedene Variable-Zugriffe</h2>";
    
    echo "<h3>4.1 isset() Tests:</h3>";
    echo "<p>isset(\$all_reservations): " . (isset($all_reservations) ? 'JA' : 'NEIN') . "</p>";
    echo "<p>isset(\$pending_reservations): " . (isset($pending_reservations) ? 'JA' : 'NEIN') . "</p>";
    echo "<p>isset(\$processed_reservations): " . (isset($processed_reservations) ? 'JA' : 'NEIN') . "</p>";
    
    echo "<h3>4.2 gettype() Tests:</h3>";
    echo "<p>gettype(\$all_reservations): " . gettype($all_reservations) . "</p>";
    echo "<p>gettype(\$pending_reservations): " . gettype($pending_reservations) . "</p>";
    echo "<p>gettype(\$processed_reservations): " . gettype($processed_reservations) . "</p>";
    
    echo "<h3>4.3 is_array() Tests:</h3>";
    echo "<p>is_array(\$all_reservations): " . (is_array($all_reservations) ? 'JA' : 'NEIN') . "</p>";
    echo "<p>is_array(\$pending_reservations): " . (is_array($pending_reservations) ? 'JA' : 'NEIN') . "</p>";
    echo "<p>is_array(\$processed_reservations): " . (is_array($processed_reservations) ? 'JA' : 'NEIN') . "</p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler: " . $e->getMessage() . "</p>";
}

echo "<h2>5. Nächste Schritte</h2>";
echo "<p>1. <a href='admin/dashboard.php'>Teste das Dashboard</a> - Prüfe die Debug-Logs</p>";
echo "<p>2. <a href='debug-google-calendar-fixed.php'>Prüfe die Logs</a></p>";

echo "<hr>";
echo "<p><small>Zeitstempel: " . date('Y-m-d H:i:s') . "</small></p>";
?>
