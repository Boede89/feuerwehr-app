<?php
/**
 * Erstelle Test-Reservierung für Debugging
 */

require_once 'config/database.php';

echo "<!DOCTYPE html>";
echo "<html><head><title>Test-Reservierung erstellen</title></head><body>";
echo "<h1>🧪 Test-Reservierung erstellen</h1>";
echo "<p>Zeitstempel: " . date('d.m.Y H:i:s') . "</p>";

try {
    echo "<h2>1. Prüfe verfügbare Fahrzeuge</h2>";
    
    $stmt = $db->query("SELECT id, name FROM vehicles WHERE is_active = 1 ORDER BY name");
    $vehicles = $stmt->fetchAll();
    
    if (empty($vehicles)) {
        echo "❌ Keine aktiven Fahrzeuge gefunden. Erstelle Test-Fahrzeug...<br>";
        
        $stmt = $db->prepare("INSERT INTO vehicles (name, description, is_active) VALUES (?, ?, ?)");
        $stmt->execute(['Test Fahrzeug', 'Test Fahrzeug für Debugging', 1]);
        $vehicle_id = $db->lastInsertId();
        
        echo "✅ Test-Fahrzeug erstellt (ID: $vehicle_id)<br>";
        
        $vehicles = [['id' => $vehicle_id, 'name' => 'Test Fahrzeug']];
    }
    
    echo "Verfügbare Fahrzeuge:<br>";
    foreach ($vehicles as $vehicle) {
        echo "- {$vehicle['name']} (ID: {$vehicle['id']})<br>";
    }
    
    echo "<h2>2. Erstelle Test-Reservierung</h2>";
    
    $vehicle = $vehicles[0]; // Erstes verfügbares Fahrzeug
    $requester_name = 'Test Benutzer';
    $requester_email = 'test@example.com';
    $reason = 'Debug Test - ' . date('H:i:s');
    $location = 'Test Ort';
    $start_datetime = date('Y-m-d H:i:s', strtotime('+1 hour'));
    $end_datetime = date('Y-m-d H:i:s', strtotime('+2 hours'));
    
    echo "Test-Parameter:<br>";
    echo "- Fahrzeug: {$vehicle['name']} (ID: {$vehicle['id']})<br>";
    echo "- Antragsteller: $requester_name<br>";
    echo "- E-Mail: $requester_email<br>";
    echo "- Grund: $reason<br>";
    echo "- Ort: $location<br>";
    echo "- Start: $start_datetime<br>";
    echo "- Ende: $end_datetime<br>";
    
    // Prüfe ob bereits eine Test-Reservierung existiert
    $stmt = $db->prepare("SELECT id FROM reservations WHERE requester_email = ? AND reason LIKE 'Debug Test%' ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$requester_email]);
    $existing_reservation = $stmt->fetch();
    
    if ($existing_reservation) {
        echo "⚠️ Test-Reservierung existiert bereits (ID: {$existing_reservation['id']})<br>";
        echo "Lösche alte Test-Reservierung...<br>";
        
        $stmt = $db->prepare("DELETE FROM reservations WHERE id = ?");
        $stmt->execute([$existing_reservation['id']]);
        
        echo "✅ Alte Test-Reservierung gelöscht<br>";
    }
    
    // Erstelle neue Test-Reservierung
    $stmt = $db->prepare("INSERT INTO reservations (vehicle_id, requester_name, requester_email, reason, location, start_datetime, end_datetime, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $result = $stmt->execute([$vehicle['id'], $requester_name, $requester_email, $reason, $location, $start_datetime, $end_datetime, 'pending']);
    
    if ($result) {
        $reservation_id = $db->lastInsertId();
        echo "✅ Test-Reservierung erstellt (ID: $reservation_id)<br>";
        
        echo "<h2>3. Prüfe erstellte Reservierung</h2>";
        
        $stmt = $db->prepare("SELECT r.*, v.name as vehicle_name FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id WHERE r.id = ?");
        $stmt->execute([$reservation_id]);
        $reservation = $stmt->fetch();
        
        if ($reservation) {
            echo "✅ Reservierung erfolgreich abgerufen:<br>";
            echo "- ID: {$reservation['id']}<br>";
            echo "- Fahrzeug: {$reservation['vehicle_name']}<br>";
            echo "- Antragsteller: {$reservation['requester_name']}<br>";
            echo "- E-Mail: {$reservation['requester_email']}<br>";
            echo "- Grund: {$reservation['reason']}<br>";
            echo "- Ort: {$reservation['location']}<br>";
            echo "- Start: {$reservation['start_datetime']}<br>";
            echo "- Ende: {$reservation['end_datetime']}<br>";
            echo "- Status: {$reservation['status']}<br>";
            echo "- Erstellt: {$reservation['created_at']}<br>";
        } else {
            echo "❌ Reservierung konnte nicht abgerufen werden<br>";
        }
        
        echo "<h2>4. Nächste Schritte</h2>";
        echo "✅ Test-Reservierung ist bereit für Debugging<br>";
        echo "<a href='debug-approval-vs-button.php' class='btn btn-primary'>Debug: Genehmigung vs Button</a><br>";
        echo "<a href='admin/dashboard.php' class='btn btn-secondary'>Zum Dashboard</a><br>";
        
    } else {
        echo "❌ Fehler beim Erstellen der Test-Reservierung<br>";
    }
    
} catch (Exception $e) {
    echo "<div style='color: red;'>";
    echo "<h3>❌ Fehler aufgetreten:</h3>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    echo "</div>";
}

echo "<hr>";
echo "<p><a href='admin/dashboard.php'>Zum Dashboard</a> | <a href='admin/reservations.php'>Zu den Reservierungen</a></p>";
echo "</body></html>";
?>