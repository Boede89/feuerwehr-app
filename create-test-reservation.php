<?php
/**
 * Erstellt eine Test-Reservierung für das Dashboard
 * Öffnen Sie diese Datei in Ihrem Browser: http://ihre-domain/create-test-reservation.php
 */

// Alle Fehler anzeigen
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<!DOCTYPE html>";
echo "<html><head><title>Test-Reservierung erstellen</title>";
echo "<style>body{font-family:Arial,sans-serif;margin:20px;} .success{color:green;} .error{color:red;} .warning{color:orange;}</style>";
echo "</head><body>";

echo "<h1>🧪 Test-Reservierung erstellen</h1>";

try {
    // 1. Datenbankverbindung
    echo "<h2>1. Datenbankverbindung</h2>";
    require_once 'config/database.php';
    echo "<p class='success'>✅ Datenbankverbindung erfolgreich</p>";
    
    // 2. Prüfe ob Fahrzeuge vorhanden sind
    echo "<h2>2. Verfügbare Fahrzeuge prüfen</h2>";
    $stmt = $db->query("SELECT id, name FROM vehicles WHERE is_active = 1 ORDER BY id LIMIT 5");
    $vehicles = $stmt->fetchAll();
    
    if (empty($vehicles)) {
        echo "<p class='error'>❌ Keine aktiven Fahrzeuge gefunden!</p>";
        echo "<p>Erstelle ein Test-Fahrzeug...</p>";
        
        $stmt = $db->prepare("INSERT INTO vehicles (name, type, description, capacity, is_active) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute(['Test-Fahrzeug', 'LF', 'Test-Fahrzeug für Reservierungen', 6, 1]);
        $vehicle_id = $db->lastInsertId();
        echo "<p class='success'>✅ Test-Fahrzeug erstellt (ID: $vehicle_id)</p>";
        
        $vehicles = [['id' => $vehicle_id, 'name' => 'Test-Fahrzeug']];
    } else {
        echo "<p class='success'>✅ " . count($vehicles) . " Fahrzeuge gefunden</p>";
        foreach ($vehicles as $vehicle) {
            echo "<p>- ID {$vehicle['id']}: {$vehicle['name']}</p>";
        }
    }
    
    // 3. Prüfe bestehende Reservierungen
    echo "<h2>3. Bestehende Reservierungen prüfen</h2>";
    $stmt = $db->query("SELECT COUNT(*) as count FROM reservations WHERE status = 'pending'");
    $pending_count = $stmt->fetch()['count'];
    
    echo "<p>Ausstehende Reservierungen: <strong>$pending_count</strong></p>";
    
    if ($pending_count > 0) {
        echo "<p class='warning'>⚠️ Es gibt bereits ausstehende Reservierungen</p>";
        echo "<p><a href='admin/dashboard.php'>Zum Dashboard gehen</a></p>";
    } else {
        echo "<p class='warning'>⚠️ Keine ausstehenden Reservierungen - erstelle Test-Reservierung...</p>";
        
        // 4. Erstelle Test-Reservierung
        echo "<h2>4. Test-Reservierung erstellen</h2>";
        
        $vehicle_id = $vehicles[0]['id'];
        $start_time = date('Y-m-d H:i:s', strtotime('+1 hour'));
        $end_time = date('Y-m-d H:i:s', strtotime('+3 hours'));
        
        $stmt = $db->prepare("
            INSERT INTO reservations (
                vehicle_id, 
                requester_name, 
                requester_email, 
                reason, 
                location, 
                start_datetime, 
                end_datetime, 
                status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')
        ");
        
        $stmt->execute([
            $vehicle_id,
            'Test Antragsteller',
            'test@feuerwehr.de',
            'Test-Reservierung für Dashboard-Debug',
            'Test-Ort',
            $start_time,
            $end_time
        ]);
        
        $reservation_id = $db->lastInsertId();
        echo "<p class='success'>✅ Test-Reservierung erstellt (ID: $reservation_id)</p>";
        echo "<p>Fahrzeug: {$vehicles[0]['name']}</p>";
        echo "<p>Zeitraum: $start_time bis $end_time</p>";
        echo "<p>Status: pending</p>";
    }
    
    // 5. Prüfe das Ergebnis
    echo "<h2>5. Ergebnis prüfen</h2>";
    $stmt = $db->prepare("
        SELECT r.*, v.name as vehicle_name
        FROM reservations r
        JOIN vehicles v ON r.vehicle_id = v.id
        WHERE r.status = 'pending'
        ORDER BY r.created_at DESC
    ");
    $stmt->execute();
    $pending_reservations = $stmt->fetchAll();
    
    echo "<p>Jetzt ausstehende Reservierungen: <strong>" . count($pending_reservations) . "</strong></p>";
    
    if (count($pending_reservations) > 0) {
        echo "<p class='success'>✅ Test erfolgreich! Das Dashboard sollte jetzt Reservierungen anzeigen.</p>";
        echo "<p><a href='admin/dashboard.php' style='background: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>Zum Dashboard gehen</a></p>";
    } else {
        echo "<p class='error'>❌ Immer noch keine ausstehenden Reservierungen - Problem liegt woanders</p>";
    }
    
} catch (Exception $e) {
    echo "<p class='error'>❌ Fehler: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p>Stack Trace:</p><pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}

echo "<hr>";
echo "<p><a href='debug-dashboard-simple.php'>Debug-Dashboard anzeigen</a> | <a href='admin/dashboard.php'>Dashboard anzeigen</a></p>";
echo "<p><small>Zeitstempel: " . date('Y-m-d H:i:s') . "</small></p>";

echo "</body></html>";
?>