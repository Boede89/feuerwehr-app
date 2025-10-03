<?php
/**
 * Test Reservation Approval - Browser Version
 * Öffnen Sie diese Datei in Ihrem Browser: http://ihre-domain/test-reservation-approval.php
 */

// Alle Fehler anzeigen
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

echo "<h1>🧪 Test Reservation Approval</h1>";
echo "<p>Diese Seite testet die Reservierungs-Genehmigung direkt.</p>";

try {
    // 1. Datenbankverbindung
    echo "<h2>1. Datenbankverbindung:</h2>";
    require_once 'config/database.php';
    echo "<p style='color: green;'>✅ Datenbankverbindung erfolgreich</p>";
    
    // 2. Functions laden
    echo "<h2>2. Functions laden:</h2>";
    require_once 'includes/functions.php';
    echo "<p style='color: green;'>✅ Functions geladen</p>";
    
    // 3. Finde pending Reservierung
    echo "<h2>3. Finde pending Reservierung:</h2>";
    
    $stmt = $db->query("SELECT r.*, v.name as vehicle_name FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id WHERE r.status = 'pending' ORDER BY r.id DESC LIMIT 1");
    $reservation = $stmt->fetch();
    
    if (!$reservation) {
        echo "<p style='color: red;'>❌ Keine pending Reservierungen gefunden</p>";
        
        // Erstelle Test-Reservierung
        echo "<h3>3.1. Erstelle Test-Reservierung:</h3>";
        
        // Finde erstes Fahrzeug
        $stmt = $db->query("SELECT id, name FROM vehicles WHERE is_active = 1 LIMIT 1");
        $vehicle = $stmt->fetch();
        
        if ($vehicle) {
            $stmt = $db->prepare("INSERT INTO reservations (vehicle_id, requester_name, requester_email, reason, start_datetime, end_datetime, status, created_at) VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())");
            $stmt->execute([
                $vehicle['id'],
                'Test User',
                'test@example.com',
                'Test Reservierung',
                '2025-10-05 10:00:00',
                '2025-10-05 12:00:00'
            ]);
            
            $reservation_id = $db->lastInsertId();
            echo "<p style='color: green;'>✅ Test-Reservierung erstellt: ID $reservation_id</p>";
            
            // Lade die neue Reservierung
            $stmt = $db->prepare("SELECT r.*, v.name as vehicle_name FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id WHERE r.id = ?");
            $stmt->execute([$reservation_id]);
            $reservation = $stmt->fetch();
            
        } else {
            echo "<p style='color: red;'>❌ Keine aktiven Fahrzeuge gefunden</p>";
            exit;
        }
    }
    
    if ($reservation) {
        echo "<p style='color: green;'>✅ Reservierung gefunden: ID " . $reservation['id'] . "</p>";
        echo "<p><strong>Fahrzeug:</strong> " . htmlspecialchars($reservation['vehicle_name']) . "</p>";
        echo "<p><strong>Antragsteller:</strong> " . htmlspecialchars($reservation['requester_name']) . "</p>";
        echo "<p><strong>Status:</strong> " . htmlspecialchars($reservation['status']) . "</p>";
        
        // 4. Teste Reservierungs-Genehmigung
        echo "<h2>4. Teste Reservierungs-Genehmigung:</h2>";
        
        try {
            // Simuliere Session
            session_start();
            $_SESSION['user_id'] = 1; // Admin User ID
            $_SESSION['role'] = 'admin';
            
            echo "<p style='color: green;'>✅ Session simuliert</p>";
            
            // Teste Google Calendar Funktion (ohne sie auszuführen)
            if (function_exists('create_google_calendar_event')) {
                echo "<p style='color: green;'>✅ create_google_calendar_event Funktion existiert</p>";
            } else {
                echo "<p style='color: red;'>❌ create_google_calendar_event Funktion existiert nicht</p>";
            }
            
            // Teste E-Mail Funktion (ohne sie auszuführen)
            if (function_exists('send_email')) {
                echo "<p style='color: green;'>✅ send_email Funktion existiert</p>";
            } else {
                echo "<p style='color: red;'>❌ send_email Funktion existiert nicht</p>";
            }
            
            // Teste Reservierungs-Genehmigung (ohne sie auszuführen)
            echo "<p><strong>Simulierte Genehmigung:</strong></p>";
            echo "<ul>";
            echo "<li>Reservierung ID: " . $reservation['id'] . "</li>";
            echo "<li>Fahrzeug: " . htmlspecialchars($reservation['vehicle_name']) . "</li>";
            echo "<li>Antragsteller: " . htmlspecialchars($reservation['requester_name']) . "</li>";
            echo "<li>Von: " . htmlspecialchars($reservation['start_datetime']) . "</li>";
            echo "<li>Bis: " . htmlspecialchars($reservation['end_datetime']) . "</li>";
            echo "</ul>";
            
            echo "<p style='color: green;'>✅ Reservierungs-Genehmigung kann ausgeführt werden</p>";
            
        } catch (Exception $e) {
            echo "<p style='color: red;'>❌ Test-Fehler: " . htmlspecialchars($e->getMessage()) . "</p>";
        }
        
        // 5. Erstelle Test-Formular
        echo "<h2>5. Erstelle Test-Formular:</h2>";
        
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
                <p>Diese Seite testet die Reservierungs-Genehmigung direkt.</p>
                
                <form method='POST' action='admin/reservations.php'>
                    <input type='hidden' name='action' value='approve'>
                    <input type='hidden' name='reservation_id' value='" . $reservation['id'] . "'>
                    <input type='hidden' name='csrf_token' value='test_token'>
                    
                    <div class='mb-3'>
                        <label class='form-label'>Reservierung ID:</label>
                        <input type='text' class='form-control' value='" . $reservation['id'] . "' readonly>
                    </div>
                    
                    <div class='mb-3'>
                        <label class='form-label'>Fahrzeug:</label>
                        <input type='text' class='form-control' value='" . htmlspecialchars($reservation['vehicle_name']) . "' readonly>
                    </div>
                    
                    <div class='mb-3'>
                        <label class='form-label'>Antragsteller:</label>
                        <input type='text' class='form-control' value='" . htmlspecialchars($reservation['requester_name']) . "' readonly>
                    </div>
                    
                    <div class='mb-3'>
                        <label class='form-label'>Status:</label>
                        <input type='text' class='form-control' value='" . htmlspecialchars($reservation['status']) . "' readonly>
                    </div>
                    
                    <div class='mb-3'>
                        <label class='form-label'>Von:</label>
                        <input type='text' class='form-control' value='" . htmlspecialchars($reservation['start_datetime']) . "' readonly>
                    </div>
                    
                    <div class='mb-3'>
                        <label class='form-label'>Bis:</label>
                        <input type='text' class='form-control' value='" . htmlspecialchars($reservation['end_datetime']) . "' readonly>
                    </div>
                    
                    <button type='submit' class='btn btn-success'>Reservierung genehmigen</button>
                </form>
            </div>
        </body>
        </html>
        ";
        
        file_put_contents('test-reservation-approval-form.html', $test_form);
        echo "<p style='color: green;'>✅ Test-Formular erstellt: <a href='test-reservation-approval-form.html'>test-reservation-approval-form.html</a></p>";
        
    } else {
        echo "<p style='color: red;'>❌ Keine Reservierung gefunden</p>";
    }
    
    // 6. Nächste Schritte
    echo "<h2>6. Nächste Schritte:</h2>";
    echo "<ol>";
    echo "<li>Öffnen Sie <a href='test-reservation-approval-form.html'>test-reservation-approval-form.html</a></li>";
    echo "<li>Klicken Sie auf 'Reservierung genehmigen'</li>";
    echo "<li>Falls es funktioniert, ist das Problem behoben</li>";
    echo "<li>Falls es nicht funktioniert, schauen Sie in die PHP Error Logs</li>";
    echo "</ol>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Kritischer Fehler: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><strong>Stack Trace:</strong></p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}

echo "<hr>";
echo "<p><em>Test Reservation Approval abgeschlossen!</em></p>";
?>
