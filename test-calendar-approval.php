<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

echo "<h1>🧪 Test: Google Calendar Event-Erstellung bei Genehmigung</h1>";

// Erstelle eine Test-Reservierung
echo "<h2>1. Erstelle Test-Reservierung</h2>";

try {
    // Hole das erste verfügbare Fahrzeug
    $stmt = $db->prepare("SELECT id, name FROM vehicles LIMIT 1");
    $stmt->execute();
    $vehicle = $stmt->fetch();
    
    if (!$vehicle) {
        echo "<p style='color: red;'>❌ Kein Fahrzeug verfügbar</p>";
        exit;
    }
    
    echo "<p>Verwende Fahrzeug: " . $vehicle['name'] . " (ID: " . $vehicle['id'] . ")</p>";
    
    // Erstelle Test-Reservierung
    $test_data = [
        'vehicle_id' => $vehicle['id'],
        'requester_name' => 'Test User',
        'requester_email' => 'test@example.com',
        'reason' => 'Test Genehmigung - ' . date('Y-m-d H:i:s'),
        'start_datetime' => date('Y-m-d H:i:s', strtotime('+1 day 10:00')),
        'end_datetime' => date('Y-m-d H:i:s', strtotime('+1 day 12:00')),
        'location' => 'Test-Ort',
        'status' => 'approved'
    ];
    
    $stmt = $db->prepare("
        INSERT INTO reservations (vehicle_id, requester_name, requester_email, reason, start_datetime, end_datetime, location, status, created_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $test_data['vehicle_id'],
        $test_data['requester_name'],
        $test_data['requester_email'],
        $test_data['reason'],
        $test_data['start_datetime'],
        $test_data['end_datetime'],
        $test_data['location'],
        $test_data['status']
    ]);
    
    $reservation_id = $db->lastInsertId();
    echo "<p style='color: green;'>✅ Test-Reservierung erstellt (ID: $reservation_id)</p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim Erstellen der Test-Reservierung: " . $e->getMessage() . "</p>";
    exit;
}

// Teste die neue Funktion
echo "<h2>2. Teste create_or_update_google_calendar_event</h2>";

if (function_exists('create_or_update_google_calendar_event')) {
    echo "<p style='color: green;'>✅ Funktion verfügbar</p>";
    
    try {
        $result = create_or_update_google_calendar_event(
            $vehicle['name'],
            $test_data['reason'],
            $test_data['start_datetime'],
            $test_data['end_datetime'],
            $reservation_id,
            $test_data['location']
        );
        
        if ($result) {
            echo "<p style='color: green;'>✅ Google Calendar Event erfolgreich erstellt/aktualisiert: $result</p>";
            
            // Prüfe ob calendar_events Eintrag erstellt wurde
            $stmt = $db->prepare("SELECT * FROM calendar_events WHERE reservation_id = ?");
            $stmt->execute([$reservation_id]);
            $calendar_event = $stmt->fetch();
            
            if ($calendar_event) {
                echo "<p style='color: green;'>✅ Calendar Event in Datenbank gespeichert:</p>";
                echo "<ul>";
                echo "<li>Google Event ID: " . $calendar_event['google_event_id'] . "</li>";
                echo "<li>Titel: " . $calendar_event['title'] . "</li>";
                echo "<li>Start: " . $calendar_event['start_datetime'] . "</li>";
                echo "<li>Ende: " . $calendar_event['end_datetime'] . "</li>";
                echo "</ul>";
            } else {
                echo "<p style='color: red;'>❌ Calendar Event nicht in Datenbank gespeichert</p>";
            }
            
        } else {
            echo "<p style='color: red;'>❌ Google Calendar Event konnte nicht erstellt werden</p>";
        }
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Fehler beim Testen der Funktion: " . $e->getMessage() . "</p>";
    }
    
} else {
    echo "<p style='color: red;'>❌ Funktion create_or_update_google_calendar_event nicht verfügbar</p>";
}

// Teste auch die alte Funktion zum Vergleich
echo "<h2>3. Teste create_google_calendar_event zum Vergleich</h2>";

if (function_exists('create_google_calendar_event')) {
    echo "<p style='color: green;'>✅ Alte Funktion verfügbar</p>";
    
    try {
        $old_result = create_google_calendar_event(
            $vehicle['name'] . ' - ' . $test_data['reason'],
            $test_data['reason'],
            $test_data['start_datetime'],
            $test_data['end_datetime'],
            $reservation_id,
            $test_data['location']
        );
        
        if ($old_result) {
            echo "<p style='color: green;'>✅ Alte Funktion funktioniert: $old_result</p>";
        } else {
            echo "<p style='color: red;'>❌ Alte Funktion funktioniert nicht</p>";
        }
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Fehler bei alter Funktion: " . $e->getMessage() . "</p>";
    }
    
} else {
    echo "<p style='color: red;'>❌ Alte Funktion nicht verfügbar</p>";
}

echo "<hr>";
echo "<p><a href='debug-calendar-approval.php'>← Detailliertes Debug</a></p>";
echo "<p><a href='admin/dashboard.php'>← Zum Dashboard</a></p>";
?>
