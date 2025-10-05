<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

echo "<h1>🔍 Debug: Dashboard - Live Reservierungen analysieren</h1>";

// Hole alle ausstehenden Reservierungen (wie Dashboard)
echo "<h2>1. Hole alle ausstehenden Reservierungen (wie Dashboard)</h2>";

try {
    $stmt = $db->prepare("
        SELECT r.*, v.name as vehicle_name 
        FROM reservations r 
        JOIN vehicles v ON r.vehicle_id = v.id 
        WHERE r.status = 'pending' 
        ORDER BY r.created_at DESC
    ");
    $stmt->execute();
    $pending_reservations = $stmt->fetchAll();
    
    echo "<p style='color: green;'>✅ " . count($pending_reservations) . " ausstehende Reservierungen gefunden</p>";
    
    if (empty($pending_reservations)) {
        echo "<p style='color: orange;'>⚠️ Keine ausstehenden Reservierungen - erstelle Test-Reservierung</p>";
        
        // Erstelle Test-Reservierung
        $stmt = $db->prepare("SELECT id, name FROM vehicles LIMIT 1");
        $stmt->execute();
        $vehicle = $stmt->fetch();
        
        $stmt = $db->prepare("
            INSERT INTO reservations (vehicle_id, requester_name, requester_email, reason, start_datetime, end_datetime, location, status, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $vehicle['id'],
            'Live Dashboard Debug',
            'live-dashboard@example.com',
            'Live Dashboard Debug - ' . date('Y-m-d H:i:s'),
            date('Y-m-d H:i:s', strtotime('+9 days 16:00')),
            date('Y-m-d H:i:s', strtotime('+9 days 18:00')),
            'Live-Dashboard-Ort',
            'pending'
        ]);
        
        $reservation_id = $db->lastInsertId();
        
        // Lade die neue Reservierung
        $stmt = $db->prepare("SELECT r.*, v.name as vehicle_name FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id WHERE r.id = ?");
        $stmt->execute([$reservation_id]);
        $pending_reservations = [$stmt->fetch()];
    }
    
    echo "<h3>Ausstehende Reservierungen:</h3>";
    echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr><th>ID</th><th>Fahrzeug</th><th>Antragsteller</th><th>Grund</th><th>Zeitraum</th><th>Status</th><th>Aktionen</th></tr>";
    foreach ($pending_reservations as $res) {
        echo "<tr>";
        echo "<td>" . $res['id'] . "</td>";
        echo "<td>" . $res['vehicle_name'] . "</td>";
        echo "<td>" . $res['requester_name'] . "</td>";
        echo "<td>" . substr($res['reason'], 0, 30) . "...</td>";
        echo "<td>" . date('d.m.Y H:i', strtotime($res['start_datetime'])) . " - " . date('H:i', strtotime($res['end_datetime'])) . "</td>";
        echo "<td>" . $res['status'] . "</td>";
        echo "<td><a href='?test_approve=" . $res['id'] . "'>Test Genehmigung</a></td>";
        echo "</tr>";
    }
    echo "</table>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim Laden der Reservierungen: " . $e->getMessage() . "</p>";
    exit;
}

// Teste Genehmigung wenn angefordert
if (isset($_GET['test_approve'])) {
    $reservation_id = (int)$_GET['test_approve'];
    
    echo "<h2>2. Teste Genehmigung für Reservierung $reservation_id</h2>";
    
    try {
        // Lade Reservierung
        $stmt = $db->prepare("SELECT r.*, v.name as vehicle_name FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id WHERE r.id = ?");
        $stmt->execute([$reservation_id]);
        $reservation = $stmt->fetch();
        
        if (!$reservation) {
            echo "<p style='color: red;'>❌ Reservierung $reservation_id nicht gefunden</p>";
        } else {
            echo "<p style='color: green;'>✅ Reservierung $reservation_id gefunden</p>";
            echo "<ul>";
            echo "<li>Fahrzeug: " . $reservation['vehicle_name'] . "</li>";
            echo "<li>Grund: " . $reservation['reason'] . "</li>";
            echo "<li>Zeitraum: " . $reservation['start_datetime'] . " - " . $reservation['end_datetime'] . "</li>";
            echo "<li>Ort: " . ($reservation['location'] ?? 'Nicht angegeben') . "</li>";
            echo "</ul>";
            
            // Setze Status auf approved
            $stmt = $db->prepare("UPDATE reservations SET status = 'approved' WHERE id = ?");
            $stmt->execute([$reservation_id]);
            echo "<p style='color: green;'>✅ Status auf 'approved' gesetzt</p>";
            
            // Teste create_or_update_google_calendar_event
            if (function_exists('create_or_update_google_calendar_event')) {
                echo "<p style='color: green;'>✅ Funktion create_or_update_google_calendar_event existiert</p>";
                
                error_log('LIVE DASHBOARD DEBUG: Teste create_or_update_google_calendar_event für Reservierung ' . $reservation_id);
                
                $event_id = create_or_update_google_calendar_event(
                    $reservation['vehicle_name'],
                    $reservation['reason'],
                    $reservation['start_datetime'],
                    $reservation['end_datetime'],
                    $reservation['id'],
                    $reservation['location'] ?? null
                );
                
                if ($event_id) {
                    echo "<p style='color: green;'>✅ create_or_update_google_calendar_event erfolgreich: $event_id</p>";
                    echo "<p style='color: green;'>✅ Meldung würde lauten: 'Reservierung erfolgreich genehmigt und in Google Calendar eingetragen.'</p>";
                } else {
                    echo "<p style='color: red;'>❌ create_or_update_google_calendar_event fehlgeschlagen</p>";
                    echo "<p style='color: red;'>❌ Meldung würde lauten: 'Reservierung genehmigt, aber Google Calendar Event konnte nicht erstellt werden.'</p>";
                }
            } else {
                echo "<p style='color: red;'>❌ Funktion create_or_update_google_calendar_event existiert nicht</p>";
            }
        }
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Exception bei Genehmigung: " . $e->getMessage() . "</p>";
    }
}

// Prüfe Error Logs
echo "<h2>3. Error Logs</h2>";

$error_log_file = ini_get('error_log');
if ($error_log_file && file_exists($error_log_file)) {
    $logs = file_get_contents($error_log_file);
    $recent_logs = array_slice(explode("\n", $logs), -30);
    echo "<h3>Letzte 30 Error Log Einträge:</h3>";
    echo "<pre style='background: #f5f5f5; padding: 10px; max-height: 400px; overflow-y: auto;'>";
    foreach ($recent_logs as $log) {
        if (strpos($log, 'Google Calendar') !== false || 
            strpos($log, 'create_or_update') !== false || 
            strpos($log, 'LIVE DASHBOARD DEBUG') !== false ||
            strpos($log, 'Intelligente') !== false ||
            strpos($log, 'create_google_calendar_event') !== false) {
            echo htmlspecialchars($log) . "\n";
        }
    }
    echo "</pre>";
} else {
    echo "<p style='color: orange;'>⚠️ Error Log nicht verfügbar</p>";
}

echo "<hr>";
echo "<p><a href='admin/dashboard.php'>← Zum Dashboard</a></p>";
echo "<p><a href='debug-dashboard-reservation-223.php'>← Zurück zur Reservierung 223 Analyse</a></p>";
?>
