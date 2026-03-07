<?php
require_once __DIR__ . '/includes/debug-auth.php';
require_once 'config/database.php';
require_once 'includes/functions.php';

echo "<h1>🔍 Debug: Zweites Fahrzeug Logik-Problem</h1>";

// Erstelle zwei Test-Reservierungen
echo "<h2>1. Erstelle Test-Reservierungen</h2>";

try {
    // Hole zwei verschiedene Fahrzeuge
    $stmt = $db->prepare("SELECT id, name FROM vehicles LIMIT 2");
    $stmt->execute();
    $vehicles = $stmt->fetchAll();
    
    $vehicle1 = $vehicles[0];
    $vehicle2 = $vehicles[1];
    
    echo "<p>Fahrzeug 1: " . $vehicle1['name'] . " (ID: " . $vehicle1['id'] . ")</p>";
    echo "<p>Fahrzeug 2: " . $vehicle2['name'] . " (ID: " . $vehicle2['id'] . ")</p>";
    
    // Gleicher Zeitraum und Grund für beide
    $shared_reason = 'Logik Test - ' . date('Y-m-d H:i:s');
    $shared_start = date('Y-m-d H:i:s', strtotime('+6 days 14:00'));
    $shared_end = date('Y-m-d H:i:s', strtotime('+6 days 16:00'));
    $shared_location = 'Logik-Ort';
    
    // Erstelle erste Reservierung
    $stmt = $db->prepare("
        INSERT INTO reservations (vehicle_id, requester_name, requester_email, reason, start_datetime, end_datetime, location, status, created_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $vehicle1['id'],
        'Logik Test 1',
        'logik1@example.com',
        $shared_reason,
        $shared_start,
        $shared_end,
        $shared_location,
        'approved'
    ]);
    
    $reservation1_id = $db->lastInsertId();
    echo "<p style='color: green;'>✅ Erste Reservierung erstellt (ID: $reservation1_id)</p>";
    
    // Erstelle zweite Reservierung
    $stmt = $db->prepare("
        INSERT INTO reservations (vehicle_id, requester_name, requester_email, reason, start_datetime, end_datetime, location, status, created_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $vehicle2['id'],
        'Logik Test 2',
        'logik2@example.com',
        $shared_reason,
        $shared_start,
        $shared_end,
        $shared_location,
        'approved'
    ]);
    
    $reservation2_id = $db->lastInsertId();
    echo "<p style='color: green;'>✅ Zweite Reservierung erstellt (ID: $reservation2_id)</p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim Erstellen der Test-Reservierungen: " . $e->getMessage() . "</p>";
    exit;
}

// Teste erste Reservierung
echo "<h2>2. Teste erste Reservierung</h2>";

try {
    error_log('LOGIK DEBUG: Teste erste Reservierung');
    
    $result1 = create_or_update_google_calendar_event(
        $vehicle1['name'],
        $shared_reason,
        $shared_start,
        $shared_end,
        $reservation1_id,
        $shared_location
    );
    
    if ($result1) {
        echo "<p style='color: green;'>✅ Erste Reservierung erfolgreich: $result1</p>";
    } else {
        echo "<p style='color: red;'>❌ Erste Reservierung fehlgeschlagen</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Exception bei erster Reservierung: " . $e->getMessage() . "</p>";
}

// Simuliere die Logik der zweiten Reservierung Schritt für Schritt
echo "<h2>3. Simuliere zweite Reservierung Schritt für Schritt</h2>";

echo "<h3>3.1 SQL-Abfrage für bestehende Events</h3>";

try {
    $stmt = $db->prepare("
        SELECT ce.google_event_id, ce.title 
        FROM calendar_events ce 
        JOIN reservations r ON ce.reservation_id = r.id 
        WHERE r.start_datetime = ? AND r.end_datetime = ? AND r.reason = ?
        LIMIT 1
    ");
    $stmt->execute([$shared_start, $shared_end, $shared_reason]);
    $existing_event = $stmt->fetch();
    
    if ($existing_event) {
        echo "<p style='color: orange;'>⚠️ Bestehendes Event gefunden:</p>";
        echo "<ul>";
        echo "<li>Google Event ID: " . $existing_event['google_event_id'] . "</li>";
        echo "<li>Titel: " . $existing_event['title'] . "</li>";
        echo "</ul>";
        
        // Prüfe ob das Fahrzeug bereits im Titel steht
        $current_title = $existing_event['title'];
        $vehicle_name = $vehicle2['name'];
        
        echo "<h3>3.2 Prüfe ob Fahrzeug im Titel steht</h3>";
        echo "<p><strong>Aktueller Titel:</strong> $current_title</p>";
        echo "<p><strong>Fahrzeug Name:</strong> $vehicle_name</p>";
        
        if (stripos($current_title, $vehicle_name) === false) {
            echo "<p style='color: green;'>✅ Fahrzeug nicht im Titel - sollte hinzugefügt werden</p>";
            
            $new_title = $current_title . ', ' . $vehicle_name;
            echo "<p><strong>Neuer Titel:</strong> $new_title</p>";
            
            // Teste update_google_calendar_event_title
            echo "<h3>3.3 Teste update_google_calendar_event_title</h3>";
            
            $update_success = update_google_calendar_event_title($existing_event['google_event_id'], $new_title);
            
            if ($update_success) {
                echo "<p style='color: green;'>✅ Titel-Update erfolgreich</p>";
                
                // Teste Datenbank-Updates
                echo "<h3>3.4 Teste Datenbank-Updates</h3>";
                
                // Aktualisiere alle betroffenen calendar_events Einträge
                $stmt = $db->prepare("UPDATE calendar_events SET title = ? WHERE google_event_id = ?");
                $update_result = $stmt->execute([$new_title, $existing_event['google_event_id']]);
                
                if ($update_result) {
                    echo "<p style='color: green;'>✅ Calendar Events Titel aktualisiert</p>";
                } else {
                    echo "<p style='color: red;'>❌ Calendar Events Titel Update fehlgeschlagen</p>";
                }
                
                // Speichere die neue Reservierung mit der bestehenden Google Event ID
                $stmt = $db->prepare("INSERT INTO calendar_events (reservation_id, google_event_id, title, start_datetime, end_datetime, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
                $insert_result = $stmt->execute([$reservation2_id, $existing_event['google_event_id'], $new_title, $shared_start, $shared_end]);
                
                if ($insert_result) {
                    echo "<p style='color: green;'>✅ Neue Reservierung in calendar_events gespeichert</p>";
                } else {
                    echo "<p style='color: red;'>❌ Neue Reservierung Speicherung fehlgeschlagen</p>";
                }
                
            } else {
                echo "<p style='color: red;'>❌ Titel-Update fehlgeschlagen</p>";
            }
            
        } else {
            echo "<p style='color: orange;'>⚠️ Fahrzeug bereits im Titel - sollte nur calendar_events Eintrag hinzufügen</p>";
        }
        
    } else {
        echo "<p style='color: red;'>❌ Kein bestehendes Event gefunden - das sollte nicht passieren</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler bei SQL-Abfrage: " . $e->getMessage() . "</p>";
}

// Teste die komplette Funktion für die zweite Reservierung
echo "<h2>4. Teste komplette Funktion für zweite Reservierung</h2>";

try {
    error_log('LOGIK DEBUG: Teste zweite Reservierung komplett');
    
    $result2 = create_or_update_google_calendar_event(
        $vehicle2['name'],
        $shared_reason,
        $shared_start,
        $shared_end,
        $reservation2_id,
        $shared_location
    );
    
    if ($result2) {
        echo "<p style='color: green;'>✅ Zweite Reservierung erfolgreich: $result2</p>";
    } else {
        echo "<p style='color: red;'>❌ Zweite Reservierung fehlgeschlagen</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Exception bei zweiter Reservierung: " . $e->getMessage() . "</p>";
}

// Prüfe Error Logs
echo "<h2>5. Error Logs analysieren</h2>";

$error_log_file = ini_get('error_log');
if ($error_log_file && file_exists($error_log_file)) {
    $logs = file_get_contents($error_log_file);
    $recent_logs = array_slice(explode("\n", $logs), -50);
    echo "<h3>Letzte 50 Error Log Einträge:</h3>";
    echo "<pre style='background: #f5f5f5; padding: 10px; max-height: 500px; overflow-y: auto;'>";
    foreach ($recent_logs as $log) {
        if (strpos($log, 'Google Calendar') !== false || 
            strpos($log, 'create_or_update') !== false || 
            strpos($log, 'LOGIK DEBUG') !== false ||
            strpos($log, 'Intelligente') !== false ||
            strpos($log, 'update_google_calendar_event_title') !== false) {
            echo htmlspecialchars($log) . "\n";
        }
    }
    echo "</pre>";
} else {
    echo "<p style='color: orange;'>⚠️ Error Log nicht verfügbar</p>";
}

echo "<hr>";
echo "<p><a href='admin/dashboard.php'>← Zum Dashboard</a></p>";
echo "<p><a href='debug-title-update.php'>← Zurück zum Titel-Update Debug</a></p>";
?>
