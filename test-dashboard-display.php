<?php
/**
 * Test: Dashboard Display - Teste ob das Dashboard die Reservierungen anzeigt
 */

echo "<h1>Test: Dashboard Display - Teste ob das Dashboard die Reservierungen anzeigt</h1>";

// Lade Funktionen
require_once 'config/database.php';

echo "<h2>1. Simuliere Dashboard-Logik</h2>";

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
    
    echo "<h2>2. Teste Dashboard-Display-Logik</h2>";
    
    echo "<h3>2.1 Teste empty() Funktion:</h3>";
    $is_empty = empty($pending_reservations);
    echo "<p>empty(\$pending_reservations): " . ($is_empty ? 'TRUE (leer)' : 'FALSE (nicht leer)') . "</p>";
    
    echo "<h3>2.2 Teste count() Funktion:</h3>";
    $count = count($pending_reservations);
    echo "<p>count(\$pending_reservations): $count</p>";
    
    echo "<h3>2.3 Teste if-Bedingung:</h3>";
    if (empty($pending_reservations)) {
        echo "<p style='color: red;'>❌ if (empty(\$pending_reservations)) ist TRUE - zeigt 'Keine ausstehenden Anträge'</p>";
    } else {
        echo "<p style='color: green;'>✅ if (empty(\$pending_reservations)) ist FALSE - zeigt Reservierungen an</p>";
    }
    
    echo "<h2>3. Teste Dashboard-HTML-Simulation</h2>";
    
    echo "<h3>3.1 Simuliere Dashboard-HTML:</h3>";
    
    // Simuliere den Dashboard-HTML-Bereich
    echo "<div style='border: 1px solid #ddd; padding: 10px; margin: 10px 0;'>";
    echo "<h4>Offene Anträge (" . count($pending_reservations) . ")</h4>";
    
    if (empty($pending_reservations)) {
        echo "<p style='color: red;'>❌ Keine ausstehenden Anträge</p>";
    } else {
        echo "<p style='color: green;'>✅ Zeige " . count($pending_reservations) . " ausstehende Anträge:</p>";
        
        echo "<ul>";
        foreach ($pending_reservations as $reservation) {
            echo "<li>ID " . $reservation['id'] . " - " . htmlspecialchars($reservation['vehicle_name']) . " - " . htmlspecialchars($reservation['reason']) . "</li>";
        }
        echo "</ul>";
    }
    echo "</div>";
    
    echo "<h2>4. Debug-Informationen</h2>";
    
    echo "<h3>4.1 Variable-Typen:</h3>";
    echo "<p>gettype(\$pending_reservations): " . gettype($pending_reservations) . "</p>";
    echo "<p>is_array(\$pending_reservations): " . (is_array($pending_reservations) ? 'JA' : 'NEIN') . "</p>";
    echo "<p>is_null(\$pending_reservations): " . (is_null($pending_reservations) ? 'JA' : 'NEIN') . "</p>";
    
    echo "<h3>4.2 Array-Details:</h3>";
    echo "<p>array_keys(\$pending_reservations): " . implode(', ', array_keys($pending_reservations)) . "</p>";
    echo "<p>array_values(\$pending_reservations): " . implode(', ', array_map(function($r) { return $r['id']; }, $pending_reservations)) . "</p>";
    
    echo "<h2>5. Teste verschiedene empty() Alternativen</h2>";
    
    echo "<h3>5.1 Verschiedene empty() Tests:</h3>";
    echo "<p>empty(\$pending_reservations): " . (empty($pending_reservations) ? 'TRUE' : 'FALSE') . "</p>";
    echo "<p>count(\$pending_reservations) === 0: " . (count($pending_reservations) === 0 ? 'TRUE' : 'FALSE') . "</p>";
    echo "<p>count(\$pending_reservations) == 0: " . (count($pending_reservations) == 0 ? 'TRUE' : 'FALSE') . "</p>";
    echo "<p>!count(\$pending_reservations): " . (!count($pending_reservations) ? 'TRUE' : 'FALSE') . "</p>";
    echo "<p>!empty(\$pending_reservations): " . (!empty($pending_reservations) ? 'TRUE' : 'FALSE') . "</p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler: " . $e->getMessage() . "</p>";
}

echo "<h2>6. Nächste Schritte</h2>";
echo "<p>1. <a href='admin/dashboard.php'>Teste das Dashboard</a> - Prüfe die Debug-Logs</p>";
echo "<p>2. <a href='debug-google-calendar-fixed.php'>Prüfe die Logs</a></p>";

echo "<hr>";
echo "<p><small>Zeitstempel: " . date('Y-m-d H:i:s') . "</small></p>";
?>
