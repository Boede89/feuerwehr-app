<?php
/**
 * Test: Dashboard Includes - Teste ob Includes das Problem verursachen
 */

echo "<h1>Test: Dashboard Includes - Teste ob Includes das Problem verursachen</h1>";

echo "<h2>1. Teste Dashboard ohne Includes</h2>";

// Lade Funktionen
require_once 'config/database.php';

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
    
    echo "<p>✅ Ohne Includes - all_reservations: " . count($all_reservations) . ", pending: " . count($pending_reservations) . "</p>";
    
    echo "<h2>2. Teste Dashboard mit Includes</h2>";
    
    // Lade includes
    require_once 'includes/functions.php';
    require_once 'includes/google_calendar_service_account.php';
    require_once 'includes/google_calendar.php';
    
    echo "<p>✅ Mit Includes - all_reservations: " . count($all_reservations) . ", pending: " . count($pending_reservations) . "</p>";
    
    echo "<h2>3. Teste Dashboard mit Session-Fix</h2>";
    
    // Simuliere Session-Fix
    session_start();
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
        // Lade Admin-Benutzer aus der Datenbank
        $stmt = $db->query("SELECT id, username, email, user_role, is_admin, role, first_name, last_name FROM users WHERE user_role = 'admin' OR role = 'admin' OR is_admin = 1 LIMIT 1");
        $admin_user = $stmt->fetch();
        
        if ($admin_user) {
            $_SESSION['user_id'] = $admin_user['id'];
            $_SESSION['role'] = 'admin';
            $_SESSION['first_name'] = $admin_user['first_name'];
            $_SESSION['last_name'] = $admin_user['last_name'];
            $_SESSION['username'] = $admin_user['username'];
            $_SESSION['email'] = $admin_user['email'];
        }
    }
    
    echo "<p>✅ Mit Session-Fix - all_reservations: " . count($all_reservations) . ", pending: " . count($pending_reservations) . "</p>";
    
    echo "<h2>4. Teste Dashboard mit POST-Verarbeitung</h2>";
    
    // Simuliere POST-Verarbeitung
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        echo "<p>POST-Verarbeitung aktiviert</p>";
        
        // Simuliere POST-Verarbeitung ohne echte Aktionen
        $message = "Test POST-Verarbeitung";
        $error = "";
    }
    
    echo "<p>✅ Mit POST-Verarbeitung - all_reservations: " . count($all_reservations) . ", pending: " . count($pending_reservations) . "</p>";
    
    echo "<h2>5. Teste Dashboard mit HTML-Ausgabe</h2>";
    
    // Simuliere HTML-Ausgabe
    echo "<div style='border: 2px solid #007bff; padding: 20px; margin: 20px 0; background: #f8f9fa;'>";
    echo "<h4>Offene Anträge (" . count($pending_reservations) . ")</h4>";
    
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
    
    echo "<h2>6. Debug-Informationen</h2>";
    
    echo "<h3>6.1 Variable-Status:</h3>";
    echo "<p>all_reservations: " . count($all_reservations) . " (isset: " . (isset($all_reservations) ? 'JA' : 'NEIN') . ")</p>";
    echo "<p>pending_reservations: " . count($pending_reservations) . " (isset: " . (isset($pending_reservations) ? 'JA' : 'NEIN') . ")</p>";
    echo "<p>processed_reservations: " . count($processed_reservations) . " (isset: " . (isset($processed_reservations) ? 'JA' : 'NEIN') . ")</p>";
    
    echo "<h3>6.2 Session-Status:</h3>";
    echo "<p>user_id: " . ($_SESSION['user_id'] ?? 'NICHT GESETZT') . "</p>";
    echo "<p>role: " . ($_SESSION['role'] ?? 'NICHT GESETZT') . "</p>";
    
    echo "<h3>6.3 POST-Status:</h3>";
    echo "<p>REQUEST_METHOD: " . $_SERVER['REQUEST_METHOD'] . "</p>";
    echo "<p>POST-Daten: " . (empty($_POST) ? 'LEER' : count($_POST) . ' Felder') . "</p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler: " . $e->getMessage() . "</p>";
}

echo "<h2>7. Nächste Schritte</h2>";
echo "<p>1. <a href='admin/dashboard.php'>Teste das Dashboard</a> - Prüfe die Debug-Logs</p>";
echo "<p>2. <a href='debug-google-calendar-fixed.php'>Prüfe die Logs</a></p>";

echo "<hr>";
echo "<p><small>Zeitstempel: " . date('Y-m-d H:i:s') . "</small></p>";
?>
