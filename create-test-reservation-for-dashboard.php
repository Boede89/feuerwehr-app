<?php
/**
 * Erstelle Test-Reservierung für Dashboard-Test
 */

require_once 'config/database.php';
require_once 'includes/functions.php';

echo "<h1>🧪 Test-Reservierung für Dashboard erstellen</h1>";

try {
    // 1. Prüfe ob bereits ausstehende Reservierungen existieren
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM reservations WHERE status = 'pending'");
    $stmt->execute();
    $count = $stmt->fetch()['count'];
    
    echo "<h2>1. Bestehende Reservierungen</h2>";
    echo "<p>Ausstehende Reservierungen: $count</p>";
    
    if ($count > 0) {
        echo "<p style='color: green;'>✅ Es gibt bereits ausstehende Reservierungen</p>";
        echo "<p><a href='admin/dashboard.php'>→ Zum Dashboard gehen</a></p>";
    } else {
        echo "<p style='color: orange;'>⚠️ Keine ausstehenden Reservierungen - erstelle Test-Daten</p>";
        
        // 2. Erstelle Test-Reservierung
        echo "<h2>2. Test-Reservierung erstellen</h2>";
        
        // Prüfe verfügbare Fahrzeuge
        $stmt = $db->prepare("SELECT id, name FROM vehicles LIMIT 1");
        $stmt->execute();
        $vehicle = $stmt->fetch();
        
        if (!$vehicle) {
            echo "<p style='color: red;'>❌ Keine Fahrzeuge gefunden</p>";
            exit;
        }
        
        // Prüfe verfügbare Benutzer
        $stmt = $db->prepare("SELECT id, first_name, last_name FROM users LIMIT 1");
        $stmt->execute();
        $user = $stmt->fetch();
        
        if (!$user) {
            echo "<p style='color: red;'>❌ Keine Benutzer gefunden</p>";
            exit;
        }
        
        echo "<p>Verwende Fahrzeug: " . htmlspecialchars($vehicle['name']) . "</p>";
        echo "<p>Verwende Benutzer: " . htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) . "</p>";
        
        // Erstelle Test-Reservierung
        $start_datetime = date('Y-m-d H:i:s', strtotime('+1 day 10:00'));
        $end_datetime = date('Y-m-d H:i:s', strtotime('+1 day 12:00'));
        
        $stmt = $db->prepare("
            INSERT INTO reservations (user_id, vehicle_id, start_datetime, end_datetime, reason, location, status, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())
        ");
        
        $result = $stmt->execute([
            $user['id'],
            $vehicle['id'],
            $start_datetime,
            $end_datetime,
            'Test-Reservierung für Dashboard',
            'Test-Ort',
        ]);
        
        if ($result) {
            $reservation_id = $db->lastInsertId();
            echo "<p style='color: green;'>✅ Test-Reservierung erstellt (ID: $reservation_id)</p>";
            echo "<p><strong>Details:</strong></p>";
            echo "<ul>";
            echo "<li>Fahrzeug: " . htmlspecialchars($vehicle['name']) . "</li>";
            echo "<li>Zeitraum: $start_datetime - $end_datetime</li>";
            echo "<li>Grund: Test-Reservierung für Dashboard</li>";
            echo "<li>Status: pending</li>";
            echo "</ul>";
            echo "<p><a href='admin/dashboard.php'>→ Zum Dashboard gehen</a></p>";
        } else {
            echo "<p style='color: red;'>❌ Fehler beim Erstellen der Test-Reservierung</p>";
        }
    }
    
    // 3. Zeige alle ausstehenden Reservierungen
    echo "<h2>3. Alle ausstehenden Reservierungen</h2>";
    
    $stmt = $db->prepare("
        SELECT r.*, v.name as vehicle_name, r.requester_name, r.requester_email
        FROM reservations r 
        JOIN vehicles v ON r.vehicle_id = v.id 
        WHERE r.status = 'pending' 
        ORDER BY r.created_at DESC
    ");
    $stmt->execute();
    $reservations = $stmt->fetchAll();
    
    if (!empty($reservations)) {
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>ID</th><th>Fahrzeug</th><th>Antragsteller</th><th>Start</th><th>Ende</th><th>Grund</th></tr>";
        foreach ($reservations as $reservation) {
            echo "<tr>";
            echo "<td>" . $reservation['id'] . "</td>";
            echo "<td>" . htmlspecialchars($reservation['vehicle_name']) . "</td>";
            echo "<td>" . htmlspecialchars($reservation['requester_name']) . "</td>";
            echo "<td>" . $reservation['start_datetime'] . "</td>";
            echo "<td>" . $reservation['end_datetime'] . "</td>";
            echo "<td>" . htmlspecialchars($reservation['reason']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p style='color: orange;'>⚠️ Keine ausstehenden Reservierungen gefunden</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler: " . $e->getMessage() . "</p>";
}

echo "<hr>";
echo "<p><small>Zeitstempel: " . date('Y-m-d H:i:s') . "</small></p>";
?>
