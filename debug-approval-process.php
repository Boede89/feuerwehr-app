<?php
/**
 * Debug: Reservierungsgenehmigung testen
 */

session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';

// Simuliere Admin-Session
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'admin';

echo "🔍 Debug: Reservierungsgenehmigung testen\n";
echo "Zeitstempel: " . date('d.m.Y H:i:s') . "\n\n";

try {
    // 1. Prüfe ausstehende Reservierungen
    echo "1. Ausstehende Reservierungen prüfen:\n";
    $stmt = $db->prepare("
        SELECT r.*, v.name as vehicle_name 
        FROM reservations r 
        JOIN vehicles v ON r.vehicle_id = v.id 
        WHERE r.status = 'pending'
        ORDER BY r.created_at DESC 
        LIMIT 1
    ");
    $stmt->execute();
    $pending_reservations = $stmt->fetchAll();
    
    if (empty($pending_reservations)) {
        echo "⚠️ Keine ausstehenden Reservierungen gefunden. Erstelle Test-Reservierung...\n";
        
        // Test-Reservierung erstellen
        $stmt = $db->prepare("
            INSERT INTO reservations (vehicle_id, requester_name, requester_email, reason, location, start_datetime, end_datetime, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')
        ");
        $stmt->execute([
            1, // Erste verfügbare Fahrzeug-ID
            'Test Antragsteller',
            'test@example.com',
            'Debug Test für Genehmigung',
            'Test Ort',
            date('Y-m-d H:i:s', strtotime('+1 hour')),
            date('Y-m-d H:i:s', strtotime('+2 hours'))
        ]);
        
        $test_reservation_id = $db->lastInsertId();
        echo "✅ Test-Reservierung erstellt (ID: $test_reservation_id)\n";
        
        // Reservierung erneut laden
        $stmt = $db->prepare("
            SELECT r.*, v.name as vehicle_name 
            FROM reservations r 
            JOIN vehicles v ON r.vehicle_id = v.id 
            WHERE r.id = ?
        ");
        $stmt->execute([$test_reservation_id]);
        $pending_reservations = $stmt->fetchAll();
    }
    
    $reservation = $pending_reservations[0];
    echo "✅ Reservierung gefunden: ID {$reservation['id']}, Fahrzeug: {$reservation['vehicle_name']}\n\n";
    
    // 2. Simuliere Genehmigung
    echo "2. Simuliere Genehmigung:\n";
    
    // Prüfe Admin-Benutzer
    $stmt = $db->prepare("SELECT id FROM users WHERE role = 'admin' LIMIT 1");
    $stmt->execute();
    $admin_user = $stmt->fetch();
    
    if (!$admin_user) {
        echo "⚠️ Kein Admin-Benutzer gefunden. Erstelle Test-Admin...\n";
        $stmt = $db->prepare("
            INSERT INTO users (username, email, password, first_name, last_name, role, is_active) 
            VALUES (?, ?, ?, ?, ?, 'admin', 1)
        ");
        $stmt->execute([
            'test_admin',
            'admin@test.com',
            password_hash('test123', PASSWORD_DEFAULT),
            'Test',
            'Admin'
        ]);
        $admin_user_id = $db->lastInsertId();
        echo "✅ Test-Admin erstellt (ID: $admin_user_id)\n";
    } else {
        $admin_user_id = $admin_user['id'];
        echo "✅ Admin-Benutzer gefunden (ID: $admin_user_id)\n";
    }
    
    // Reservierung genehmigen
    echo "Genehmige Reservierung #{$reservation['id']}...\n";
    $stmt = $db->prepare("
        UPDATE reservations 
        SET status = 'approved', 
            approved_by = ?, 
            approved_at = NOW() 
        WHERE id = ?
    ");
    $result = $stmt->execute([$admin_user_id, $reservation['id']]);
    
    if ($result) {
        echo "✅ Reservierung erfolgreich genehmigt!\n";
        
        // Prüfe Status nach Genehmigung
        $stmt = $db->prepare("SELECT status, approved_by, approved_at FROM reservations WHERE id = ?");
        $stmt->execute([$reservation['id']]);
        $updated_reservation = $stmt->fetch();
        
        echo "Status nach Genehmigung: {$updated_reservation['status']}\n";
        echo "Genehmigt von: {$updated_reservation['approved_by']}\n";
        echo "Genehmigt am: {$updated_reservation['approved_at']}\n\n";
        
        // 3. Teste Google Calendar Integration
        echo "3. Teste Google Calendar Integration:\n";
        
        if (function_exists('create_google_calendar_event')) {
            echo "✅ create_google_calendar_event Funktion ist verfügbar\n";
            
            // Lade Reservierung mit Fahrzeug-Name
            $stmt = $db->prepare("
                SELECT r.*, v.name as vehicle_name 
                FROM reservations r 
                JOIN vehicles v ON r.vehicle_id = v.id 
                WHERE r.id = ?
            ");
            $stmt->execute([$reservation['id']]);
            $reservation_with_vehicle = $stmt->fetch();
            
            echo "Versuche Google Calendar Event zu erstellen...\n";
            $event_id = create_google_calendar_event(
                $reservation_with_vehicle['vehicle_name'],
                $reservation_with_vehicle['reason'],
                $reservation_with_vehicle['start_datetime'],
                $reservation_with_vehicle['end_datetime'],
                $reservation['id'],
                $reservation_with_vehicle['location']
            );
            
            if ($event_id) {
                echo "✅ Google Calendar Event erfolgreich erstellt! Event ID: $event_id\n";
                
                // Prüfe ob Event in der Datenbank gespeichert wurde
                $stmt = $db->prepare("SELECT * FROM calendar_events WHERE reservation_id = ?");
                $stmt->execute([$reservation['id']]);
                $calendar_event = $stmt->fetch();
                
                if ($calendar_event) {
                    echo "✅ Event in der Datenbank gespeichert (ID: {$calendar_event['id']})\n";
                } else {
                    echo "⚠️ Event nicht in der Datenbank gefunden\n";
                }
            } else {
                echo "❌ Google Calendar Event konnte nicht erstellt werden\n";
            }
        } else {
            echo "❌ create_google_calendar_event Funktion ist nicht verfügbar\n";
        }
        
    } else {
        echo "❌ Fehler bei der Genehmigung!\n";
    }
    
    // 4. Cleanup
    echo "\n4. Cleanup:\n";
    if (isset($test_reservation_id)) {
        $stmt = $db->prepare("DELETE FROM reservations WHERE id = ?");
        $stmt->execute([$test_reservation_id]);
        echo "✅ Test-Reservierung gelöscht\n";
    }
    
    if (isset($admin_user_id) && $admin_user_id > 1) {
        $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$admin_user_id]);
        echo "✅ Test-Admin gelöscht\n";
    }
    
} catch (Exception $e) {
    echo "❌ Fehler: " . $e->getMessage() . "\n";
    echo "Stack Trace: " . $e->getTraceAsString() . "\n";
}

echo "\nDebug abgeschlossen um: " . date('d.m.Y H:i:s') . "\n";
?>
