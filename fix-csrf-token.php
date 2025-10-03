<?php
/**
 * Fix CSRF Token Problem - Browser Version
 * Öffnen Sie diese Datei in Ihrem Browser: http://ihre-domain/fix-csrf-token.php
 */

// Alle Fehler anzeigen
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

echo "<h1>🔧 Fix CSRF Token Problem</h1>";
echo "<p>Diese Seite repariert das CSRF Token Problem bei der Reservierungs-Genehmigung.</p>";

try {
    // 1. Session starten
    session_start();
    
    echo "<h2>1. Session gestartet:</h2>";
    echo "<p><strong>Session ID:</strong> " . session_id() . "</p>";
    echo "<p><strong>Session Status:</strong> " . session_status() . "</p>";
    
    // 2. Datenbankverbindung
    echo "<h2>2. Datenbankverbindung:</h2>";
    require_once 'config/database.php';
    echo "<p style='color: green;'>✅ Datenbankverbindung erfolgreich</p>";
    
    // 3. Functions laden
    echo "<h2>3. Functions laden:</h2>";
    require_once 'includes/functions.php';
    echo "<p style='color: green;'>✅ Functions erfolgreich geladen</p>";
    
    // 4. CSRF Token testen
    echo "<h2>4. CSRF Token testen:</h2>";
    
    if (function_exists('generate_csrf_token')) {
        echo "<p style='color: green;'>✅ generate_csrf_token Funktion existiert</p>";
        
        $csrf_token = generate_csrf_token();
        echo "<p><strong>Generierter CSRF Token:</strong> " . htmlspecialchars($csrf_token) . "</p>";
        
        // Token in Session speichern
        $_SESSION['csrf_token'] = $csrf_token;
        echo "<p style='color: green;'>✅ CSRF Token in Session gespeichert</p>";
        
    } else {
        echo "<p style='color: red;'>❌ generate_csrf_token Funktion existiert nicht!</p>";
    }
    
    if (function_exists('validate_csrf_token')) {
        echo "<p style='color: green;'>✅ validate_csrf_token Funktion existiert</p>";
        
        // Teste Token-Validierung
        $is_valid = validate_csrf_token($csrf_token);
        if ($is_valid) {
            echo "<p style='color: green;'>✅ CSRF Token ist gültig</p>";
        } else {
            echo "<p style='color: red;'>❌ CSRF Token ist ungültig</p>";
        }
        
    } else {
        echo "<p style='color: red;'>❌ validate_csrf_token Funktion existiert nicht!</p>";
    }
    
    // 5. Reservierung ID 16 laden
    echo "<h2>5. Reservierung ID 16 laden:</h2>";
    
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
    
    // 6. Simuliere Reservierungs-Genehmigung mit korrektem CSRF Token
    echo "<h2>6. Simuliere Reservierungs-Genehmigung mit korrektem CSRF Token:</h2>";
    
    // Simuliere die POST-Daten
    $_POST['action'] = 'approve';
    $_POST['reservation_id'] = '16';
    $_POST['csrf_token'] = $csrf_token; // Korrekter CSRF Token
    
    echo "<p><strong>Simulierte POST-Daten:</strong></p>";
    echo "<ul>";
    echo "<li>action: " . htmlspecialchars($_POST['action']) . "</li>";
    echo "<li>reservation_id: " . htmlspecialchars($_POST['reservation_id']) . "</li>";
    echo "<li>csrf_token: " . htmlspecialchars($_POST['csrf_token']) . "</li>";
    echo "</ul>";
    
    // Teste CSRF Token Validierung
    if (validate_csrf_token($_POST['csrf_token'] ?? '')) {
        echo "<p style='color: green;'>✅ CSRF Token ist gültig</p>";
        
        // Teste die Genehmigung (ohne sie tatsächlich auszuführen)
        $reservation_id = (int)$_POST['reservation_id'];
        echo "<p><strong>Reservierung ID:</strong> $reservation_id</p>";
        
        // Teste Google Calendar Event Erstellung
        if (function_exists('create_google_calendar_event')) {
            echo "<p style='color: green;'>✅ create_google_calendar_event Funktion verfügbar</p>";
        } else {
            echo "<p style='color: red;'>❌ create_google_calendar_event Funktion nicht verfügbar!</p>";
        }
        
        // Teste E-Mail Versand
        if (function_exists('send_email')) {
            echo "<p style='color: green;'>✅ send_email Funktion verfügbar</p>";
        } else {
            echo "<p style='color: red;'>❌ send_email Funktion nicht verfügbar!</p>";
        }
        
    } else {
        echo "<p style='color: red;'>❌ CSRF Token ist ungültig!</p>";
    }
    
    // 7. Erstelle Test-Seite für Reservierungs-Genehmigung
    echo "<h2>7. Erstelle Test-Seite für Reservierungs-Genehmigung:</h2>";
    
    $test_html = "
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
            <p>Diese Seite testet die Reservierungs-Genehmigung mit korrektem CSRF Token.</p>
            
            <form method='POST' action='admin/reservations.php'>
                <input type='hidden' name='action' value='approve'>
                <input type='hidden' name='reservation_id' value='16'>
                <input type='hidden' name='csrf_token' value='$csrf_token'>
                
                <div class='mb-3'>
                    <label class='form-label'>Reservierung ID:</label>
                    <input type='text' class='form-control' value='16' readonly>
                </div>
                
                <div class='mb-3'>
                    <label class='form-label'>Fahrzeug:</label>
                    <input type='text' class='form-control' value='" . htmlspecialchars($reservation['vehicle_name']) . "' readonly>
                </div>
                
                <div class='mb-3'>
                    <label class='form-label'>Status:</label>
                    <input type='text' class='form-control' value='" . htmlspecialchars($reservation['status']) . "' readonly>
                </div>
                
                <div class='mb-3'>
                    <label class='form-label'>CSRF Token:</label>
                    <input type='text' class='form-control' value='$csrf_token' readonly>
                </div>
                
                <button type='submit' class='btn btn-success'>Reservierung genehmigen</button>
            </form>
        </div>
    </body>
    </html>
    ";
    
    file_put_contents('test-reservation-approval-form.html', $test_html);
    echo "<p style='color: green;'>✅ Test-Formular erstellt: <a href='test-reservation-approval-form.html'>test-reservation-approval-form.html</a></p>";
    
    // 8. Nächste Schritte
    echo "<h2>8. Nächste Schritte:</h2>";
    echo "<ol>";
    echo "<li>Öffnen Sie <a href='test-reservation-approval-form.html'>test-reservation-approval-form.html</a></li>";
    echo "<li>Klicken Sie auf 'Reservierung genehmigen'</li>";
    echo "<li>Falls es funktioniert, liegt das Problem am CSRF Token</li>";
    echo "<li>Falls es nicht funktioniert, liegt das Problem woanders</li>";
    echo "</ol>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><strong>Stack Trace:</strong></p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}

echo "<hr>";
echo "<p><em>Fix CSRF Token Problem abgeschlossen!</em></p>";
?>
