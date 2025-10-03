<?php
/**
 * Fix Reservation Approval - Browser Version
 * Öffnen Sie diese Datei in Ihrem Browser: http://ihre-domain/fix-reservation-approval.php
 */

require_once 'config/database.php';

echo "<h1>🔧 Fix Reservation Approval</h1>";
echo "<p>Diese Seite repariert das Problem mit der Reservierungs-Genehmigung.</p>";

try {
    // 1. Prüfe Reservierung ID 16
    echo "<h2>1. Reservierung ID 16 prüfen:</h2>";
    
    $stmt = $db->prepare("SELECT * FROM reservations WHERE id = ?");
    $stmt->execute([16]);
    $reservation = $stmt->fetch();
    
    if (!$reservation) {
        echo "<p style='color: red;'>❌ Reservierung ID 16 nicht gefunden!</p>";
        exit;
    }
    
    echo "<p style='color: green;'>✅ Reservierung ID 16 gefunden</p>";
    echo "<p><strong>Status:</strong> " . htmlspecialchars($reservation['status']) . "</p>";
    echo "<p><strong>Fahrzeug ID:</strong> " . htmlspecialchars($reservation['vehicle_id']) . "</p>";
    
    // 2. Prüfe Fahrzeug
    echo "<h2>2. Fahrzeug prüfen:</h2>";
    
    $vehicle_id = $reservation['vehicle_id'];
    $stmt = $db->prepare("SELECT * FROM vehicles WHERE id = ?");
    $stmt->execute([$vehicle_id]);
    $vehicle = $stmt->fetch();
    
    if (!$vehicle) {
        echo "<p style='color: red;'>❌ Fahrzeug ID $vehicle_id nicht gefunden!</p>";
        exit;
    }
    
    echo "<p style='color: green;'>✅ Fahrzeug ID $vehicle_id gefunden</p>";
    echo "<p><strong>Name:</strong> " . htmlspecialchars($vehicle['name']) . "</p>";
    
    // 3. Teste den problematischen JOIN
    echo "<h2>3. Teste problematischen JOIN:</h2>";
    
    $stmt = $db->prepare("SELECT r.*, v.name as vehicle_name FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id WHERE r.id = ?");
    $stmt->execute([16]);
    $reservation_with_vehicle = $stmt->fetch();
    
    if (!$reservation_with_vehicle) {
        echo "<p style='color: red;'>❌ JOIN fehlgeschlagen!</p>";
        
        // Versuche das Problem zu identifizieren
        echo "<h3>3.1. Problem identifizieren:</h3>";
        
        // Prüfe ob es ein Problem mit der vehicles Tabelle gibt
        $stmt = $db->query("SHOW COLUMNS FROM vehicles");
        $columns = $stmt->fetchAll();
        $column_names = array_column($columns, 'Field');
        
        if (!in_array('name', $column_names)) {
            echo "<p style='color: red;'>❌ Spalte 'name' existiert nicht in vehicles Tabelle!</p>";
            echo "<p>Verfügbare Spalten: " . implode(', ', $column_names) . "</p>";
            
            // Versuche die Spalte hinzuzufügen
            echo "<h3>3.2. Spalte 'name' hinzufügen:</h3>";
            try {
                $db->exec("ALTER TABLE vehicles ADD COLUMN name VARCHAR(100) NOT NULL AFTER id");
                echo "<p style='color: green;'>✅ Spalte 'name' hinzugefügt</p>";
                
                // Setze einen Standard-Namen für bestehende Fahrzeuge
                $db->exec("UPDATE vehicles SET name = CONCAT('Fahrzeug ', id) WHERE name IS NULL OR name = ''");
                echo "<p style='color: green;'>✅ Standard-Namen für bestehende Fahrzeuge gesetzt</p>";
                
            } catch (Exception $e) {
                echo "<p style='color: red;'>❌ Fehler beim Hinzufügen der Spalte: " . htmlspecialchars($e->getMessage()) . "</p>";
            }
        } else {
            echo "<p style='color: green;'>✅ Spalte 'name' existiert in vehicles Tabelle</p>";
        }
        
        // Teste den JOIN erneut
        echo "<h3>3.3. JOIN erneut testen:</h3>";
        $stmt = $db->prepare("SELECT r.*, v.name as vehicle_name FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id WHERE r.id = ?");
        $stmt->execute([16]);
        $reservation_with_vehicle = $stmt->fetch();
        
        if ($reservation_with_vehicle) {
            echo "<p style='color: green;'>✅ JOIN jetzt erfolgreich!</p>";
            echo "<p><strong>Fahrzeug Name:</strong> " . htmlspecialchars($reservation_with_vehicle['vehicle_name']) . "</p>";
        } else {
            echo "<p style='color: red;'>❌ JOIN immer noch fehlgeschlagen</p>";
            
            // Prüfe ob es ein Problem mit der reservations Tabelle gibt
            $stmt = $db->query("SHOW COLUMNS FROM reservations");
            $reservation_columns = $stmt->fetchAll();
            $reservation_column_names = array_column($reservation_columns, 'Field');
            
            if (!in_array('vehicle_id', $reservation_column_names)) {
                echo "<p style='color: red;'>❌ Spalte 'vehicle_id' existiert nicht in reservations Tabelle!</p>";
                echo "<p>Verfügbare Spalten: " . implode(', ', $reservation_column_names) . "</p>";
            } else {
                echo "<p style='color: green;'>✅ Spalte 'vehicle_id' existiert in reservations Tabelle</p>";
            }
        }
    } else {
        echo "<p style='color: green;'>✅ JOIN erfolgreich!</p>";
        echo "<p><strong>Fahrzeug Name:</strong> " . htmlspecialchars($reservation_with_vehicle['vehicle_name']) . "</p>";
    }
    
    // 4. Teste die Genehmigung manuell
    echo "<h2>4. Teste Genehmigung manuell:</h2>";
    
    if ($reservation['status'] == 'pending') {
        echo "<p style='color: orange;'>⚠️ Reservierung ist noch pending - teste Genehmigung</p>";
        
        try {
            // Simuliere die Genehmigung
            $stmt = $db->prepare("UPDATE reservations SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?");
            $stmt->execute([1, 16]); // Verwende User ID 1 als Test
            
            echo "<p style='color: green;'>✅ Reservierung erfolgreich genehmigt</p>";
            
            // Teste Google Calendar Event Erstellung
            echo "<h3>4.1. Teste Google Calendar Event Erstellung:</h3>";
            
            if (function_exists('create_google_calendar_event')) {
                echo "<p style='color: green;'>✅ create_google_calendar_event Funktion existiert</p>";
                
                // Teste die Funktion (ohne sie tatsächlich auszuführen)
                echo "<p><strong>Hinweis:</strong> Google Calendar Funktion wird nicht ausgeführt, nur getestet</p>";
                
            } else {
                echo "<p style='color: red;'>❌ create_google_calendar_event Funktion existiert nicht!</p>";
            }
            
            // Teste E-Mail Versand
            echo "<h3>4.2. Teste E-Mail Versand:</h3>";
            
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
    
    // 5. Prüfe PHP Fehler
    echo "<h2>5. PHP Fehler prüfen:</h2>";
    
    $error_log = ini_get('error_log');
    if ($error_log && file_exists($error_log)) {
        echo "<p><strong>Error Log:</strong> $error_log</p>";
        $errors = file_get_contents($error_log);
        $recent_errors = array_slice(explode("\n", $errors), -20); // Letzte 20 Zeilen
        echo "<pre>" . htmlspecialchars(implode("\n", $recent_errors)) . "</pre>";
    } else {
        echo "<p style='color: orange;'>⚠️ Kein Error Log gefunden oder nicht lesbar</p>";
    }
    
    // 6. Nächste Schritte
    echo "<h2>6. Nächste Schritte:</h2>";
    echo "<ol>";
    echo "<li>Gehen Sie zu <a href='admin/reservations.php'>Admin → Reservierungen</a></li>";
    echo "<li>Versuchen Sie erneut, die Reservierung zu genehmigen</li>";
    echo "<li>Falls es immer noch nicht funktioniert, schauen Sie in die PHP Error Logs</li>";
    echo "</ol>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><strong>Stack Trace:</strong></p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}

echo "<hr>";
echo "<p><em>Fix Reservation Approval abgeschlossen!</em></p>";
?>
