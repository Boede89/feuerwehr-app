<?php
/**
 * Test Simple - Browser Version
 * Öffnen Sie diese Datei in Ihrem Browser: http://ihre-domain/test-simple.php
 */

// Alle Fehler anzeigen
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

echo "<h1>🧪 Test Simple</h1>";
echo "<p>Diese Seite testet die grundlegende Funktionalität.</p>";

try {
    // 1. PHP Info
    echo "<h2>1. PHP Info:</h2>";
    echo "<p><strong>PHP Version:</strong> " . phpversion() . "</p>";
    echo "<p><strong>Current Directory:</strong> " . getcwd() . "</p>";
    echo "<p><strong>Script Name:</strong> " . $_SERVER['SCRIPT_NAME'] . "</p>";
    
    // 2. Datei-Existenz prüfen
    echo "<h2>2. Datei-Existenz prüfen:</h2>";
    
    $files = [
        'config/database.php',
        'includes/functions.php',
        'admin/reservations.php',
        'fix-google-calendar-config.php'
    ];
    
    foreach ($files as $file) {
        if (file_exists($file)) {
            echo "<p style='color: green;'>✅ $file existiert</p>";
        } else {
            echo "<p style='color: red;'>❌ $file existiert nicht</p>";
        }
    }
    
    // 3. Datenbankverbindung testen
    echo "<h2>3. Datenbankverbindung testen:</h2>";
    
    try {
        require_once 'config/database.php';
        echo "<p style='color: green;'>✅ config/database.php geladen</p>";
        
        // Teste einfache Abfrage
        $stmt = $db->query("SELECT 1 as test");
        $result = $stmt->fetch();
        if ($result && $result['test'] == 1) {
            echo "<p style='color: green;'>✅ Datenbankverbindung funktioniert</p>";
        } else {
            echo "<p style='color: red;'>❌ Datenbankabfrage fehlgeschlagen</p>";
        }
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Datenbankfehler: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
    
    // 4. Functions laden testen
    echo "<h2>4. Functions laden testen:</h2>";
    
    try {
        require_once 'includes/functions.php';
        echo "<p style='color: green;'>✅ includes/functions.php geladen</p>";
        
        // Teste wichtige Funktionen
        $functions = [
            'generate_csrf_token',
            'validate_csrf_token',
            'create_google_calendar_event',
            'send_email'
        ];
        
        foreach ($functions as $func) {
            if (function_exists($func)) {
                echo "<p style='color: green;'>   ✅ $func existiert</p>";
            } else {
                echo "<p style='color: red;'>   ❌ $func existiert nicht</p>";
            }
        }
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Functions-Fehler: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
    
    // 5. Reservierungen testen
    echo "<h2>5. Reservierungen testen:</h2>";
    
    try {
        $stmt = $db->query("SELECT r.*, v.name as vehicle_name FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id ORDER BY r.id DESC LIMIT 5");
        $reservations = $stmt->fetchAll();
        
        if (empty($reservations)) {
            echo "<p style='color: red;'>❌ Keine Reservierungen gefunden</p>";
        } else {
            echo "<p style='color: green;'>✅ " . count($reservations) . " Reservierungen gefunden</p>";
            echo "<table border='1' cellpadding='5' cellspacing='0'>";
            echo "<tr><th>ID</th><th>Fahrzeug</th><th>Antragsteller</th><th>Status</th></tr>";
            foreach ($reservations as $reservation) {
                echo "<tr>";
                echo "<td>" . htmlspecialchars($reservation['id']) . "</td>";
                echo "<td>" . htmlspecialchars($reservation['vehicle_name']) . "</td>";
                echo "<td>" . htmlspecialchars($reservation['requester_name']) . "</td>";
                echo "<td>" . htmlspecialchars($reservation['status']) . "</td>";
                echo "</tr>";
            }
            echo "</table>";
        }
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Reservierungs-Fehler: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
    
    // 6. Teste Reservierungs-Genehmigung direkt
    echo "<h2>6. Teste Reservierungs-Genehmigung direkt:</h2>";
    
    try {
        // Finde eine pending Reservierung
        $stmt = $db->query("SELECT r.*, v.name as vehicle_name FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id WHERE r.status = 'pending' LIMIT 1");
        $pending_reservation = $stmt->fetch();
        
        if ($pending_reservation) {
            echo "<p style='color: green;'>✅ Pending Reservierung gefunden: ID " . $pending_reservation['id'] . "</p>";
            echo "<p><strong>Fahrzeug:</strong> " . htmlspecialchars($pending_reservation['vehicle_name']) . "</p>";
            echo "<p><strong>Antragsteller:</strong> " . htmlspecialchars($pending_reservation['requester_name']) . "</p>";
            
            // Erstelle Test-Formular
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
            
            file_put_contents('test-reservation-direct.html', $test_form);
            echo "<p style='color: green;'>✅ Test-Formular erstellt: <a href='test-reservation-direct.html'>test-reservation-direct.html</a></p>";
            
        } else {
            echo "<p style='color: orange;'>⚠️ Keine pending Reservierungen gefunden</p>";
        }
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Test-Fehler: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
    
    // 7. Nächste Schritte
    echo "<h2>7. Nächste Schritte:</h2>";
    echo "<ol>";
    echo "<li>Öffnen Sie <a href='test-reservation-direct.html'>test-reservation-direct.html</a></li>";
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
echo "<p><em>Test Simple abgeschlossen!</em></p>";
?>
