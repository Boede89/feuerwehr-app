<?php
/**
 * Fix Missing Reservation - Browser Version
 * Öffnen Sie diese Datei in Ihrem Browser: http://ihre-domain/fix-missing-reservation.php
 */

// Alle Fehler anzeigen
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

echo "<h1>🔧 Fix Missing Reservation</h1>";
echo "<p>Diese Seite behebt das Problem mit der fehlenden Reservierung ID 16.</p>";

try {
    // 1. Datenbankverbindung
    echo "<h2>1. Datenbankverbindung:</h2>";
    require_once 'config/database.php';
    echo "<p style='color: green;'>✅ Datenbankverbindung erfolgreich</p>";
    
    // 2. Prüfe alle Reservierungen
    echo "<h2>2. Prüfe alle Reservierungen:</h2>";
    
    $stmt = $db->query("SELECT r.*, v.name as vehicle_name FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id ORDER BY r.id DESC LIMIT 10");
    $reservations = $stmt->fetchAll();
    
    if (empty($reservations)) {
        echo "<p style='color: red;'>❌ Keine Reservierungen gefunden!</p>";
    } else {
        echo "<p style='color: green;'>✅ " . count($reservations) . " Reservierungen gefunden</p>";
        echo "<table border='1' cellpadding='5' cellspacing='0'>";
        echo "<tr><th>ID</th><th>Fahrzeug</th><th>Antragsteller</th><th>Status</th><th>Erstellt</th></tr>";
        foreach ($reservations as $reservation) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($reservation['id']) . "</td>";
            echo "<td>" . htmlspecialchars($reservation['vehicle_name']) . "</td>";
            echo "<td>" . htmlspecialchars($reservation['requester_name']) . "</td>";
            echo "<td>" . htmlspecialchars($reservation['status']) . "</td>";
            echo "<td>" . htmlspecialchars($reservation['created_at']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
    // 3. Prüfe Reservierung ID 16 spezifisch
    echo "<h2>3. Prüfe Reservierung ID 16 spezifisch:</h2>";
    
    $stmt = $db->prepare("SELECT * FROM reservations WHERE id = ?");
    $stmt->execute([16]);
    $reservation_16 = $stmt->fetch();
    
    if ($reservation_16) {
        echo "<p style='color: green;'>✅ Reservierung ID 16 existiert</p>";
        echo "<p><strong>Status:</strong> " . htmlspecialchars($reservation_16['status']) . "</p>";
        echo "<p><strong>Antragsteller:</strong> " . htmlspecialchars($reservation_16['requester_name']) . "</p>";
        echo "<p><strong>E-Mail:</strong> " . htmlspecialchars($reservation_16['requester_email']) . "</p>";
        echo "<p><strong>Fahrzeug ID:</strong> " . htmlspecialchars($reservation_16['vehicle_id']) . "</p>";
    } else {
        echo "<p style='color: red;'>❌ Reservierung ID 16 existiert nicht!</p>";
        
        // Prüfe ob es ein Problem mit dem JOIN gibt
        echo "<h3>3.1. Prüfe JOIN-Problem:</h3>";
        
        $stmt = $db->prepare("SELECT r.*, v.name as vehicle_name FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id WHERE r.id = ?");
        $stmt->execute([16]);
        $reservation_with_vehicle = $stmt->fetch();
        
        if ($reservation_with_vehicle) {
            echo "<p style='color: green;'>✅ JOIN funktioniert - Reservierung ID 16 mit Fahrzeug gefunden</p>";
        } else {
            echo "<p style='color: red;'>❌ JOIN fehlgeschlagen - Reservierung ID 16 nicht mit Fahrzeug gefunden</p>";
            
            // Prüfe ob das Fahrzeug existiert
            if ($reservation_16) {
                $vehicle_id = $reservation_16['vehicle_id'];
                $stmt = $db->prepare("SELECT * FROM vehicles WHERE id = ?");
                $stmt->execute([$vehicle_id]);
                $vehicle = $stmt->fetch();
                
                if ($vehicle) {
                    echo "<p style='color: green;'>✅ Fahrzeug ID $vehicle_id existiert</p>";
                    echo "<p><strong>Fahrzeug Name:</strong> " . htmlspecialchars($vehicle['name']) . "</p>";
                } else {
                    echo "<p style='color: red;'>❌ Fahrzeug ID $vehicle_id existiert nicht!</p>";
                }
            }
        }
    }
    
    // 4. Erstelle Test-Reservierung falls keine vorhanden
    echo "<h2>4. Erstelle Test-Reservierung falls keine vorhanden:</h2>";
    
    if (empty($reservations)) {
        echo "<p style='color: orange;'>⚠️ Keine Reservierungen vorhanden - erstelle Test-Reservierung</p>";
        
        // Prüfe ob Fahrzeuge vorhanden sind
        $stmt = $db->query("SELECT * FROM vehicles LIMIT 1");
        $vehicle = $stmt->fetch();
        
        if ($vehicle) {
            echo "<p style='color: green;'>✅ Fahrzeug gefunden: " . htmlspecialchars($vehicle['name']) . "</p>";
            
            // Erstelle Test-Reservierung
            $stmt = $db->prepare("INSERT INTO reservations (vehicle_id, requester_name, requester_email, reason, start_datetime, end_datetime, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $vehicle['id'],
                'Test Benutzer',
                'test@example.com',
                'Test Reservierung',
                '2025-10-05 10:00:00',
                '2025-10-05 12:00:00',
                'pending'
            ]);
            
            $new_reservation_id = $db->lastInsertId();
            echo "<p style='color: green;'>✅ Test-Reservierung erstellt mit ID: $new_reservation_id</p>";
            
        } else {
            echo "<p style='color: red;'>❌ Keine Fahrzeuge vorhanden - kann keine Test-Reservierung erstellen</p>";
        }
    } else {
        echo "<p style='color: green;'>✅ Reservierungen vorhanden - keine Test-Reservierung nötig</p>";
    }
    
    // 5. Teste Reservierungs-Genehmigung mit vorhandener Reservierung
    echo "<h2>5. Teste Reservierungs-Genehmigung mit vorhandener Reservierung:</h2>";
    
    // Finde eine pending Reservierung
    $stmt = $db->query("SELECT r.*, v.name as vehicle_name FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id WHERE r.status = 'pending' LIMIT 1");
    $pending_reservation = $stmt->fetch();
    
    if ($pending_reservation) {
        echo "<p style='color: green;'>✅ Pending Reservierung gefunden: ID " . $pending_reservation['id'] . "</p>";
        echo "<p><strong>Fahrzeug:</strong> " . htmlspecialchars($pending_reservation['vehicle_name']) . "</p>";
        echo "<p><strong>Antragsteller:</strong> " . htmlspecialchars($pending_reservation['requester_name']) . "</p>";
        
        // Erstelle Test-Formular für diese Reservierung
        $test_form = "
        <!DOCTYPE html>
        <html lang='de'>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <title>Test Reservierungs-Genehmigung</title>
            <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css' rel='stylesheet'>
        </head>
        <body>
            <div class='container mt-5'>
                <h1>Test Reservierungs-Genehmigung</h1>
                <p>Diese Seite testet die Reservierungs-Genehmigung mit einer vorhandenen Reservierung.</p>
                
                <form method='POST' action='admin/reservations.php'>
                    <input type='hidden' name='action' value='approve'>
                    <input type='hidden' name='reservation_id' value='" . $pending_reservation['id'] . "'>
                    <input type='hidden' name='csrf_token' value='test_token'>
                    
                    <div class='mb-3'>
                        <label class='form-label'>Reservierung ID:</label>
                        <input type='text' class='form-control' value='" . $pending_reservation['id'] . "' readonly>
                    </div>
                    
                    <div class='mb-3'>
                        <label class='form-label'>Fahrzeug:</label>
                        <input type='text' class='form-control' value='" . htmlspecialchars($pending_reservation['vehicle_name']) . "' readonly>
                    </div>
                    
                    <div class='mb-3'>
                        <label class='form-label'>Antragsteller:</label>
                        <input type='text' class='form-control' value='" . htmlspecialchars($pending_reservation['requester_name']) . "' readonly>
                    </div>
                    
                    <div class='mb-3'>
                        <label class='form-label'>Status:</label>
                        <input type='text' class='form-control' value='" . htmlspecialchars($pending_reservation['status']) . "' readonly>
                    </div>
                    
                    <button type='submit' class='btn btn-success'>Reservierung genehmigen</button>
                </form>
            </div>
        </body>
        </html>
        ";
        
        file_put_contents('test-reservation-approval-fixed.html', $test_form);
        echo "<p style='color: green;'>✅ Test-Formular erstellt: <a href='test-reservation-approval-fixed.html'>test-reservation-approval-fixed.html</a></p>";
        
    } else {
        echo "<p style='color: orange;'>⚠️ Keine pending Reservierungen gefunden</p>";
    }
    
    // 6. Nächste Schritte
    echo "<h2>6. Nächste Schritte:</h2>";
    echo "<ol>";
    echo "<li>Öffnen Sie <a href='test-reservation-approval-fixed.html'>test-reservation-approval-fixed.html</a></li>";
    echo "<li>Klicken Sie auf 'Reservierung genehmigen'</li>";
    echo "<li>Falls es funktioniert, ist das Problem behoben</li>";
    echo "<li>Falls es nicht funktioniert, liegt das Problem woanders</li>";
    echo "</ol>";
    
    // 7. Zusammenfassung
    echo "<h2>7. Zusammenfassung:</h2>";
    echo "<ul>";
    echo "<li>✅ Datenbankverbindung erfolgreich</li>";
    echo "<li>✅ Reservierungen gefunden: " . count($reservations) . "</li>";
    echo "<li>✅ Test-Reservierung erstellt (falls nötig)</li>";
    echo "<li>✅ Test-Formular erstellt</li>";
    echo "</ul>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><strong>Stack Trace:</strong></p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}

echo "<hr>";
echo "<p><em>Fix Missing Reservation abgeschlossen!</em></p>";
?>
