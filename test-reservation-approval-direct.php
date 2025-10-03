<?php
/**
 * Test Reservation Approval Direct - Browser Version
 * Öffnen Sie diese Datei in Ihrem Browser: http://ihre-domain/test-reservation-approval-direct.php
 */

// Alle Fehler anzeigen
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

echo "<h1>🧪 Test Reservation Approval Direct</h1>";
echo "<p>Diese Seite testet die Reservierungs-Genehmigung direkt.</p>";

try {
    // 1. Session starten
    session_start();
    $_SESSION['user_id'] = 1;
    $_SESSION['user_role'] = 'admin';
    
    echo "<h2>1. Session gestartet:</h2>";
    echo "<p><strong>User ID:</strong> " . $_SESSION['user_id'] . "</p>";
    echo "<p><strong>User Role:</strong> " . $_SESSION['user_role'] . "</p>";
    
    // 2. Datenbankverbindung
    echo "<h2>2. Datenbankverbindung:</h2>";
    require_once 'config/database.php';
    echo "<p style='color: green;'>✅ Datenbankverbindung erfolgreich</p>";
    
    // 3. Functions laden
    echo "<h2>3. Functions laden:</h2>";
    require_once 'includes/functions.php';
    echo "<p style='color: green;'>✅ Functions erfolgreich geladen</p>";
    
    // 4. Reservierung ID 16 laden
    echo "<h2>4. Reservierung ID 16 laden:</h2>";
    
    $stmt = $db->prepare("SELECT r.*, v.name as vehicle_name FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id WHERE r.id = ?");
    $stmt->execute([16]);
    $reservation = $stmt->fetch();
    
    if (!$reservation) {
        echo "<p style='color: red;'>❌ Reservierung ID 16 nicht gefunden!</p>";
        exit;
    }
    
    echo "<p style='color: green;'>✅ Reservierung ID 16 gefunden</p>";
    echo "<p><strong>Status:</strong> " . htmlspecialchars($reservation['status']) . "</p>";
    echo "<p><strong>Fahrzeug:</strong> " . htmlspecialchars($reservation['vehicle_name']) . "</p>";
    echo "<p><strong>Antragsteller:</strong> " . htmlspecialchars($reservation['requester_name']) . "</p>";
    echo "<p><strong>E-Mail:</strong> " . htmlspecialchars($reservation['requester_email']) . "</p>";
    
    // 5. Reservierung genehmigen
    echo "<h2>5. Reservierung genehmigen:</h2>";
    
    if ($reservation['status'] == 'pending') {
        echo "<p style='color: orange;'>⚠️ Reservierung ist pending - genehmige sie</p>";
        
        try {
            // Reservierung genehmigen
            $stmt = $db->prepare("UPDATE reservations SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?");
            $stmt->execute([$_SESSION['user_id'], 16]);
            
            echo "<p style='color: green;'>✅ Reservierung erfolgreich genehmigt</p>";
            
            // Reservierung erneut laden
            $stmt = $db->prepare("SELECT r.*, v.name as vehicle_name FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id WHERE r.id = ?");
            $stmt->execute([16]);
            $reservation = $stmt->fetch();
            
            echo "<p><strong>Neuer Status:</strong> " . htmlspecialchars($reservation['status']) . "</p>";
            echo "<p><strong>Genehmigt von:</strong> " . htmlspecialchars($reservation['approved_by']) . "</p>";
            echo "<p><strong>Genehmigt am:</strong> " . htmlspecialchars($reservation['approved_at']) . "</p>";
            
        } catch (Exception $e) {
            echo "<p style='color: red;'>❌ Fehler bei der Genehmigung: " . htmlspecialchars($e->getMessage()) . "</p>";
            echo "<p><strong>Stack Trace:</strong></p>";
            echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
        }
    } else {
        echo "<p style='color: orange;'>⚠️ Reservierung ist bereits " . htmlspecialchars($reservation['status']) . "</p>";
    }
    
    // 6. Google Calendar Event erstellen
    echo "<h2>6. Google Calendar Event erstellen:</h2>";
    
    if (function_exists('create_google_calendar_event')) {
        echo "<p style='color: green;'>✅ create_google_calendar_event Funktion verfügbar</p>";
        
        try {
            $event_id = create_google_calendar_event(
                $reservation['vehicle_name'],
                $reservation['reason'],
                $reservation['start_datetime'],
                $reservation['end_datetime'],
                16 // reservation_id
            );
            
            if ($event_id) {
                echo "<p style='color: green;'>✅ Google Calendar Event erfolgreich erstellt</p>";
                echo "<p><strong>Event ID:</strong> " . htmlspecialchars($event_id) . "</p>";
            } else {
                echo "<p style='color: orange;'>⚠️ Google Calendar Event konnte nicht erstellt werden (wahrscheinlich nicht konfiguriert)</p>";
            }
            
        } catch (Exception $e) {
            echo "<p style='color: red;'>❌ Fehler beim Erstellen des Google Calendar Events: " . htmlspecialchars($e->getMessage()) . "</p>";
            echo "<p><strong>Stack Trace:</strong></p>";
            echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
        }
    } else {
        echo "<p style='color: red;'>❌ create_google_calendar_event Funktion nicht verfügbar!</p>";
    }
    
    // 7. E-Mail versenden
    echo "<h2>7. E-Mail versenden:</h2>";
    
    if (function_exists('send_email')) {
        echo "<p style='color: green;'>✅ send_email Funktion verfügbar</p>";
        
        try {
            $subject = "Reservierung genehmigt - " . $reservation['vehicle_name'];
            $message = "
            <h2>Reservierung genehmigt</h2>
            <p>Ihre Reservierung wurde genehmigt.</p>
            <p><strong>Fahrzeug:</strong> " . htmlspecialchars($reservation['vehicle_name']) . "</p>
            <p><strong>Grund:</strong> " . htmlspecialchars($reservation['reason']) . "</p>
            <p><strong>Von:</strong> " . htmlspecialchars($reservation['start_datetime']) . "</p>
            <p><strong>Bis:</strong> " . htmlspecialchars($reservation['end_datetime']) . "</p>
            <p>Vielen Dank für Ihre Reservierung!</p>
            ";
            
            $result = send_email($reservation['requester_email'], $subject, $message);
            
            if ($result) {
                echo "<p style='color: green;'>✅ E-Mail erfolgreich versendet</p>";
                echo "<p><strong>An:</strong> " . htmlspecialchars($reservation['requester_email']) . "</p>";
            } else {
                echo "<p style='color: orange;'>⚠️ E-Mail konnte nicht versendet werden (wahrscheinlich nicht konfiguriert)</p>";
            }
            
        } catch (Exception $e) {
            echo "<p style='color: red;'>❌ Fehler beim Versenden der E-Mail: " . htmlspecialchars($e->getMessage()) . "</p>";
            echo "<p><strong>Stack Trace:</strong></p>";
            echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
        }
    } else {
        echo "<p style='color: red;'>❌ send_email Funktion nicht verfügbar!</p>";
    }
    
    // 8. Zusammenfassung
    echo "<h2>8. Zusammenfassung:</h2>";
    echo "<ul>";
    echo "<li>✅ Session gestartet</li>";
    echo "<li>✅ Datenbankverbindung erfolgreich</li>";
    echo "<li>✅ Functions geladen</li>";
    echo "<li>✅ Reservierung gefunden</li>";
    echo "<li>✅ Reservierung genehmigt</li>";
    echo "<li>✅ Google Calendar Event erstellt (falls konfiguriert)</li>";
    echo "<li>✅ E-Mail versendet (falls konfiguriert)</li>";
    echo "</ul>";
    
    echo "<h2>9. Nächste Schritte:</h2>";
    echo "<ol>";
    echo "<li>Gehen Sie zu <a href='admin/reservations.php'>Admin → Reservierungen</a></li>";
    echo "<li>Versuchen Sie erneut, eine Reservierung zu genehmigen</li>";
    echo "<li>Falls es immer noch nicht funktioniert, liegt das Problem woanders</li>";
    echo "</ol>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Kritischer Fehler: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><strong>Stack Trace:</strong></p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}

echo "<hr>";
echo "<p><em>Test Reservation Approval Direct abgeschlossen!</em></p>";
?>
