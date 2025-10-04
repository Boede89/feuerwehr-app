<?php
/**
 * Debug: Genehmigungsformular testen
 */

session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';

// Simuliere Admin-Session
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'admin';

echo "<!DOCTYPE html>";
echo "<html><head><title>Debug Approval Form</title></head><body>";
echo "<h1>🔍 Debug: Genehmigungsformular</h1>";
echo "<p>Zeitstempel: " . date('d.m.Y H:i:s') . "</p>";

try {
    echo "<h2>1. Lade ausstehende Reservierung</h2>";
    
    $stmt = $db->prepare("
        SELECT r.*, v.name as vehicle_name 
        FROM reservations r 
        JOIN vehicles v ON r.vehicle_id = v.id 
        WHERE r.status = 'pending'
        ORDER BY r.created_at DESC 
        LIMIT 1
    ");
    $stmt->execute();
    $reservation = $stmt->fetch();
    
    if (!$reservation) {
        echo "❌ Keine ausstehende Reservierung gefunden<br>";
        exit;
    }
    
    echo "✅ Reservierung gefunden: ID {$reservation['id']}, Fahrzeug: {$reservation['vehicle_name']}<br>";
    
    echo "<h2>2. Teste Genehmigung direkt</h2>";
    
    // Simuliere POST-Daten
    $_POST['reservation_id'] = $reservation['id'];
    $_POST['action'] = 'approve';
    $_POST['csrf_token'] = generate_csrf_token();
    
    echo "POST-Daten:<br>";
    echo "- reservation_id: {$_POST['reservation_id']}<br>";
    echo "- action: {$_POST['action']}<br>";
    echo "- csrf_token: {$_POST['csrf_token']}<br>";
    
    echo "<h2>3. Führe Genehmigung aus</h2>";
    
    // Simuliere die Genehmigung
    $reservation_id = (int)$_POST['reservation_id'];
    $action = $_POST['action'];
    
    if ($action == 'approve') {
        echo "Genehmige Reservierung #$reservation_id...<br>";
        
        // Prüfe CSRF Token
        if (validate_csrf_token($_POST['csrf_token'])) {
            echo "✅ CSRF Token gültig<br>";
        } else {
            echo "❌ CSRF Token ungültig<br>";
        }
        
        // Führe Genehmigung aus
        $stmt = $db->prepare("UPDATE reservations SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?");
        $result = $stmt->execute([$_SESSION['user_id'], $reservation_id]);
        
        if ($result) {
            echo "✅ Reservierung erfolgreich genehmigt!<br>";
            
            // Prüfe Status nach Genehmigung
            $stmt = $db->prepare("SELECT status, approved_by, approved_at FROM reservations WHERE id = ?");
            $stmt->execute([$reservation_id]);
            $updated_reservation = $stmt->fetch();
            
            echo "Status nach Genehmigung: {$updated_reservation['status']}<br>";
            echo "Genehmigt von: {$updated_reservation['approved_by']}<br>";
            echo "Genehmigt am: {$updated_reservation['approved_at']}<br>";
            
            // Teste Google Calendar Event
            echo "<h2>4. Teste Google Calendar Event</h2>";
            
            if (function_exists('create_google_calendar_event')) {
                echo "Erstelle Google Calendar Event...<br>";
                
                $event_id = create_google_calendar_event(
                    $reservation['vehicle_name'],
                    $reservation['reason'],
                    $reservation['start_datetime'],
                    $reservation['end_datetime'],
                    $reservation_id,
                    $reservation['location']
                );
                
                if ($event_id) {
                    echo "✅ Google Calendar Event erfolgreich erstellt! Event ID: $event_id<br>";
                } else {
                    echo "❌ Google Calendar Event konnte nicht erstellt werden<br>";
                }
            } else {
                echo "❌ create_google_calendar_event Funktion nicht verfügbar<br>";
            }
            
        } else {
            echo "❌ Fehler bei der Genehmigung!<br>";
        }
    }
    
    echo "<h2>5. Prüfe ausstehende Reservierungen nach Genehmigung</h2>";
    
    $stmt = $db->prepare("
        SELECT r.*, v.name as vehicle_name 
        FROM reservations r 
        JOIN vehicles v ON r.vehicle_id = v.id 
        WHERE r.status = 'pending'
        ORDER BY r.created_at DESC
    ");
    $stmt->execute();
    $pending_reservations = $stmt->fetchAll();
    
    echo "Anzahl ausstehende Reservierungen nach Genehmigung: " . count($pending_reservations) . "<br>";
    
    if (empty($pending_reservations)) {
        echo "✅ Keine ausstehenden Reservierungen mehr - Genehmigung erfolgreich!<br>";
    } else {
        echo "⚠️ Es gibt noch ausstehende Reservierungen:<br>";
        foreach ($pending_reservations as $res) {
            echo "- ID: {$res['id']}, Fahrzeug: {$res['vehicle_name']}, Status: {$res['status']}<br>";
        }
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
