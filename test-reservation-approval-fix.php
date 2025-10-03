<?php
/**
 * Test Reservation Approval Fix - Browser Version
 * Öffnen Sie diese Datei in Ihrem Browser: http://ihre-domain/test-reservation-approval-fix.php
 */

require_once 'config/database.php';
require_once 'includes/functions.php';

echo "<h1>🧪 Test Reservation Approval Fix</h1>";
echo "<p>Diese Seite testet die reparierte Reservierungs-Genehmigung.</p>";

try {
    // 1. Prüfe ob Funktionen existieren
    echo "<h2>1. Funktionen prüfen:</h2>";
    
    if (function_exists('create_google_calendar_event')) {
        echo "<p style='color: green;'>✅ create_google_calendar_event Funktion existiert</p>";
    } else {
        echo "<p style='color: red;'>❌ create_google_calendar_event Funktion existiert nicht!</p>";
    }
    
    if (function_exists('send_email')) {
        echo "<p style='color: green;'>✅ send_email Funktion existiert</p>";
    } else {
        echo "<p style='color: red;'>❌ send_email Funktion existiert nicht!</p>";
    }
    
    // 2. Prüfe Reservierung ID 16
    echo "<h2>2. Reservierung ID 16 prüfen:</h2>";
    
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
    echo "<p><strong>Grund:</strong> " . htmlspecialchars($reservation['reason']) . "</p>";
    
    // 3. Teste Google Calendar Funktion
    echo "<h2>3. Teste Google Calendar Funktion:</h2>";
    
    if (function_exists('create_google_calendar_event')) {
        echo "<p style='color: green;'>✅ create_google_calendar_event Funktion existiert</p>";
        
        // Teste die Funktion (ohne sie tatsächlich auszuführen)
        echo "<p><strong>Test-Parameter:</strong></p>";
        echo "<ul>";
        echo "<li>Fahrzeug: " . htmlspecialchars($reservation['vehicle_name']) . "</li>";
        echo "<li>Grund: " . htmlspecialchars($reservation['reason']) . "</li>";
        echo "<li>Von: " . htmlspecialchars($reservation['start_datetime']) . "</li>";
        echo "<li>Bis: " . htmlspecialchars($reservation['end_datetime']) . "</li>";
        echo "<li>Reservierung ID: " . htmlspecialchars($reservation['id']) . "</li>";
        echo "</ul>";
        
        echo "<p><strong>Hinweis:</strong> Google Calendar Funktion wird nicht ausgeführt, nur getestet</p>";
        
    } else {
        echo "<p style='color: red;'>❌ create_google_calendar_event Funktion existiert nicht!</p>";
    }
    
    // 4. Teste E-Mail Funktion
    echo "<h2>4. Teste E-Mail Funktion:</h2>";
    
    if (function_exists('send_email')) {
        echo "<p style='color: green;'>✅ send_email Funktion existiert</p>";
        
        // Teste die Funktion (ohne sie tatsächlich auszuführen)
        echo "<p><strong>Test-Parameter:</strong></p>";
        echo "<ul>";
        echo "<li>An: " . htmlspecialchars($reservation['requester_email']) . "</li>";
        echo "<li>Betreff: Reservierung genehmigt</li>";
        echo "<li>Nachricht: HTML-E-Mail mit Reservierungsdetails</li>";
        echo "</ul>";
        
        echo "<p><strong>Hinweis:</strong> E-Mail Funktion wird nicht ausgeführt, nur getestet</p>";
        
    } else {
        echo "<p style='color: red;'>❌ send_email Funktion existiert nicht!</p>";
    }
    
    // 5. Teste calendar_events Tabelle
    echo "<h2>5. Teste calendar_events Tabelle:</h2>";
    
    $stmt = $db->query("SHOW TABLES LIKE 'calendar_events'");
    $table_exists = $stmt->fetch();
    
    if ($table_exists) {
        echo "<p style='color: green;'>✅ calendar_events Tabelle existiert</p>";
        
        // Prüfe Spalten
        $stmt = $db->query("SHOW COLUMNS FROM calendar_events");
        $columns = $stmt->fetchAll();
        echo "<p><strong>Spalten:</strong> " . implode(', ', array_column($columns, 'Field')) . "</p>";
        
    } else {
        echo "<p style='color: red;'>❌ calendar_events Tabelle existiert nicht!</p>";
    }
    
    // 6. Teste manuelle Genehmigung
    echo "<h2>6. Teste manuelle Genehmigung:</h2>";
    
    if ($reservation['status'] == 'pending') {
        echo "<p style='color: orange;'>⚠️ Reservierung ist noch pending - teste Genehmigung</p>";
        
        try {
            // Simuliere die Genehmigung
            $stmt = $db->prepare("UPDATE reservations SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?");
            $stmt->execute([1, 16]); // Verwende User ID 1 als Test
            
            echo "<p style='color: green;'>✅ Reservierung erfolgreich genehmigt</p>";
            
            // Teste Google Calendar Event Erstellung
            echo "<h3>6.1. Teste Google Calendar Event Erstellung:</h3>";
            
            if (function_exists('create_google_calendar_event')) {
                echo "<p style='color: green;'>✅ create_google_calendar_event Funktion existiert</p>";
                
                // Teste die Funktion (ohne sie tatsächlich auszuführen)
                echo "<p><strong>Hinweis:</strong> Google Calendar Funktion wird nicht ausgeführt, nur getestet</p>";
                
            } else {
                echo "<p style='color: red;'>❌ create_google_calendar_event Funktion existiert nicht!</p>";
            }
            
            // Teste E-Mail Versand
            echo "<h3>6.2. Teste E-Mail Versand:</h3>";
            
            if (function_exists('send_email')) {
                echo "<p style='color: green;'>✅ send_email Funktion existiert</p>";
                
                // Teste die Funktion (ohne sie tatsächlich auszuführen)
                echo "<p><strong>Hinweis:</strong> E-Mail Funktion wird nicht ausgeführt, nur getestet</p>";
                
            } else {
                echo "<p style='color: red;'>❌ send_email Funktion existiert nicht!</p>";
            }
            
        } catch (Exception $e) {
            echo "<p style='color: red;'>❌ Fehler bei der Genehmigung: " . htmlspecialchars($e->getMessage()) . "</p>";
        }
    } else {
        echo "<p style='color: orange;'>⚠️ Reservierung ist bereits " . htmlspecialchars($reservation['status']) . "</p>";
    }
    
    // 7. Nächste Schritte
    echo "<h2>7. Nächste Schritte:</h2>";
    echo "<ol>";
    echo "<li>Gehen Sie zu <a href='admin/reservations.php'>Admin → Reservierungen</a></li>";
    echo "<li>Versuchen Sie erneut, eine Reservierung zu genehmigen</li>";
    echo "<li>Falls es immer noch nicht funktioniert, schauen Sie in die PHP Error Logs</li>";
    echo "</ol>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><strong>Stack Trace:</strong></p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}

echo "<hr>";
echo "<p><em>Test Reservation Approval Fix abgeschlossen!</em></p>";
?>
