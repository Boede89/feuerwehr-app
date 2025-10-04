<?php
/**
 * Debug für echte Reservierungsgenehmigung
 */

require_once 'config/database.php';
require_once 'includes/functions.php';

echo "<h1>Debug: Echte Reservierungsgenehmigung</h1>";
echo "<p>Zeitstempel: " . date('d.m.Y H:i:s') . "</p>";

// 1. Prüfe aktuelle Session
echo "<h2>1. Session prüfen</h2>";
session_start();

if (isset($_SESSION['user_id'])) {
    echo "<p style='color: green;'>✅ Session user_id: " . htmlspecialchars($_SESSION['user_id']) . "</p>";
} else {
    echo "<p style='color: red;'>❌ Keine Session user_id</p>";
}

if (isset($_SESSION['role'])) {
    echo "<p style='color: green;'>✅ Session role: " . htmlspecialchars($_SESSION['role']) . "</p>";
} else {
    echo "<p style='color: red;'>❌ Keine Session role</p>";
}

// 2. Prüfe ob es eine neue Reservierung gibt, die genehmigt werden kann
echo "<h2>2. Pending Reservierungen prüfen</h2>";

try {
    $stmt = $db->prepare("SELECT r.*, v.name as vehicle_name FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id WHERE r.status = 'pending' ORDER BY r.created_at DESC LIMIT 5");
    $stmt->execute();
    $pending_reservations = $stmt->fetchAll();
    
    if (empty($pending_reservations)) {
        echo "<p style='color: orange;'>⚠️ Keine pending Reservierungen gefunden. Erstelle eine Test-Reservierung...</p>";
        
        // Test-Reservierung erstellen
        $stmt = $db->prepare("SELECT id, name FROM vehicles WHERE is_active = 1 LIMIT 1");
        $stmt->execute();
        $vehicle = $stmt->fetch();
        
        if ($vehicle) {
            $test_start = date('Y-m-d H:i:s', strtotime('+1 hour'));
            $test_end = date('Y-m-d H:i:s', strtotime('+2 hours'));
            
            $stmt = $db->prepare("INSERT INTO reservations (vehicle_id, requester_name, requester_email, reason, start_datetime, end_datetime, status, created_at) VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())");
            $stmt->execute([$vehicle['id'], 'Debug Test', 'debug@test.com', 'Debug Test für echte Genehmigung', $test_start, $test_end]);
            $test_reservation_id = $db->lastInsertId();
            
            echo "<p>✅ Test-Reservierung erstellt (ID: $test_reservation_id)</p>";
            
            // Reservierung zu pending list hinzufügen
            $pending_reservations = [[
                'id' => $test_reservation_id,
                'vehicle_name' => $vehicle['name'],
                'reason' => 'Debug Test für echte Genehmigung',
                'start_datetime' => $test_start,
                'end_datetime' => $test_end,
                'status' => 'pending'
            ]];
        }
    } else {
        echo "<p style='color: green;'>✅ " . count($pending_reservations) . " pending Reservierungen gefunden</p>";
    }
    
    foreach ($pending_reservations as $reservation) {
        echo "<div style='border: 1px solid #ddd; padding: 10px; margin: 10px 0;'>";
        echo "<p><strong>ID:</strong> " . htmlspecialchars($reservation['id']) . "</p>";
        echo "<p><strong>Fahrzeug:</strong> " . htmlspecialchars($reservation['vehicle_name']) . "</p>";
        echo "<p><strong>Grund:</strong> " . htmlspecialchars($reservation['reason']) . "</p>";
        echo "<p><strong>Start:</strong> " . htmlspecialchars($reservation['start_datetime']) . "</p>";
        echo "<p><strong>Ende:</strong> " . htmlspecialchars($reservation['end_datetime']) . "</p>";
        echo "<p><strong>Status:</strong> " . htmlspecialchars($reservation['status']) . "</p>";
        echo "</div>";
    }
    
} catch(PDOException $e) {
    echo "<p style='color: red;'>❌ Fehler beim Laden der Reservierungen: " . $e->getMessage() . "</p>";
    exit;
}

// 3. Simuliere die echte Reservierungsgenehmigung
if (!empty($pending_reservations)) {
    $test_reservation = $pending_reservations[0];
    $reservation_id = $test_reservation['id'];
    
    echo "<h2>3. Simuliere echte Reservierungsgenehmigung</h2>";
    echo "<p><strong>Genehmige Reservierung #$reservation_id</strong></p>";
    
    try {
        // Simuliere die echte admin/reservations.php Logik
        $admin_user_id = $_SESSION['user_id'] ?? 1; // Fallback falls keine Session
        
        // 1. Reservierung genehmigen
        $stmt = $db->prepare("UPDATE reservations SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?");
        $stmt->execute([$admin_user_id, $reservation_id]);
        
        echo "<p>✅ Reservierung genehmigt</p>";
        
        // 2. Google Calendar Event erstellen (exakt wie in admin/reservations.php)
        echo "<h3>Google Calendar Event erstellen</h3>";
        
        $stmt = $db->prepare("SELECT r.*, v.name as vehicle_name FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id WHERE r.id = ?");
        $stmt->execute([$reservation_id]);
        $reservation = $stmt->fetch();
        
        if ($reservation) {
            echo "<p><strong>Reservierung gefunden:</strong></p>";
            echo "<ul>";
            echo "<li><strong>ID:</strong> " . htmlspecialchars($reservation['id']) . "</li>";
            echo "<li><strong>Fahrzeug:</strong> " . htmlspecialchars($reservation['vehicle_name']) . "</li>";
            echo "<li><strong>Grund:</strong> " . htmlspecialchars($reservation['reason']) . "</li>";
            echo "<li><strong>Start:</strong> " . htmlspecialchars($reservation['start_datetime']) . "</li>";
            echo "<li><strong>Ende:</strong> " . htmlspecialchars($reservation['end_datetime']) . "</li>";
            echo "<li><strong>Status:</strong> " . htmlspecialchars($reservation['status']) . "</li>";
            echo "</ul>";
            
            // Prüfe ob Google Calendar Funktion verfügbar ist
            if (function_exists('create_google_calendar_event')) {
                echo "<p style='color: green;'>✅ create_google_calendar_event Funktion ist verfügbar</p>";
                
                echo "<p><strong>Versuche Google Calendar Event zu erstellen...</strong></p>";
                
                $event_id = create_google_calendar_event(
                    $reservation['vehicle_name'],
                    $reservation['reason'],
                    $reservation['start_datetime'],
                    $reservation['end_datetime'],
                    $reservation['id']
                );
                
                if ($event_id) {
                    echo "<p style='color: green;'>✅ Google Calendar Event erfolgreich erstellt! Event ID: " . htmlspecialchars($event_id) . "</p>";
                    
                    // Prüfe ob Event in der Datenbank gespeichert wurde
                    $stmt = $db->prepare("SELECT * FROM calendar_events WHERE reservation_id = ?");
                    $stmt->execute([$reservation_id]);
                    $event_record = $stmt->fetch();
                    
                    if ($event_record) {
                        echo "<p style='color: green;'>✅ Event in der Datenbank gespeichert</p>";
                        echo "<ul>";
                        echo "<li><strong>Event ID:</strong> " . htmlspecialchars($event_record['google_event_id']) . "</li>";
                        echo "<li><strong>Titel:</strong> " . htmlspecialchars($event_record['title']) . "</li>";
                        echo "<li><strong>Start:</strong> " . htmlspecialchars($event_record['start_datetime']) . "</li>";
                        echo "<li><strong>Ende:</strong> " . htmlspecialchars($event_record['end_datetime']) . "</li>";
                        echo "</ul>";
                    } else {
                        echo "<p style='color: red;'>❌ Event NICHT in der Datenbank gespeichert</p>";
                    }
                } else {
                    echo "<p style='color: red;'>❌ Google Calendar Event konnte NICHT erstellt werden</p>";
                }
            } else {
                echo "<p style='color: red;'>❌ create_google_calendar_event Funktion ist NICHT verfügbar</p>";
            }
        } else {
            echo "<p style='color: red;'>❌ Reservierung nicht gefunden</p>";
        }
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Fehler bei der Genehmigung: " . htmlspecialchars($e->getMessage()) . "</p>";
        echo "<p><strong>Stack Trace:</strong></p>";
        echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    }
}

// 4. Prüfe alle Google Calendar Events
echo "<h2>4. Alle Google Calendar Events prüfen</h2>";

try {
    $stmt = $db->prepare("SELECT ce.*, r.reason, v.name as vehicle_name FROM calendar_events ce JOIN reservations r ON ce.reservation_id = r.id JOIN vehicles v ON r.vehicle_id = v.id ORDER BY ce.created_at DESC LIMIT 10");
    $stmt->execute();
    $events = $stmt->fetchAll();
    
    if (empty($events)) {
        echo "<p style='color: red;'>❌ Keine Google Calendar Events in der Datenbank</p>";
    } else {
        echo "<p style='color: green;'>✅ " . count($events) . " Google Calendar Events in der Datenbank</p>";
        
        foreach ($events as $event) {
            echo "<div style='border: 1px solid #ddd; padding: 10px; margin: 10px 0;'>";
            echo "<p><strong>Event ID:</strong> " . htmlspecialchars($event['google_event_id']) . "</p>";
            echo "<p><strong>Reservierung ID:</strong> " . htmlspecialchars($event['reservation_id']) . "</p>";
            echo "<p><strong>Fahrzeug:</strong> " . htmlspecialchars($event['vehicle_name']) . "</p>";
            echo "<p><strong>Titel:</strong> " . htmlspecialchars($event['title']) . "</p>";
            echo "<p><strong>Start:</strong> " . htmlspecialchars($event['start_datetime']) . "</p>";
            echo "<p><strong>Ende:</strong> " . htmlspecialchars($event['end_datetime']) . "</p>";
            echo "<p><strong>Erstellt am:</strong> " . htmlspecialchars($event['created_at']) . "</p>";
            echo "</div>";
        }
    }
} catch(PDOException $e) {
    echo "<p style='color: red;'>❌ Fehler beim Laden der Events: " . $e->getMessage() . "</p>";
}

echo "<hr>";
echo "<p><strong>Debug abgeschlossen um:</strong> " . date('d.m.Y H:i:s') . "</p>";
?>
