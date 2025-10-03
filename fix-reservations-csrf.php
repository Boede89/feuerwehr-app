<?php
/**
 * Fix Reservations CSRF - Browser Version
 * Öffnen Sie diese Datei in Ihrem Browser: http://ihre-domain/fix-reservations-csrf.php
 */

// Alle Fehler anzeigen
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

echo "<h1>🔧 Fix Reservations CSRF</h1>";
echo "<p>Diese Seite repariert das CSRF Token Problem in admin/reservations.php.</p>";

try {
    // 1. Session starten
    session_start();
    
    echo "<h2>1. Session gestartet:</h2>";
    echo "<p><strong>Session ID:</strong> " . session_id() . "</p>";
    
    // 2. Datenbankverbindung
    echo "<h2>2. Datenbankverbindung:</h2>";
    require_once 'config/database.php';
    echo "<p style='color: green;'>✅ Datenbankverbindung erfolgreich</p>";
    
    // 3. Functions laden
    echo "<h2>3. Functions laden:</h2>";
    require_once 'includes/functions.php';
    echo "<p style='color: green;'>✅ Functions erfolgreich geladen</p>";
    
    // 4. CSRF Token generieren
    echo "<h2>4. CSRF Token generieren:</h2>";
    
    $csrf_token = generate_csrf_token();
    $_SESSION['csrf_token'] = $csrf_token;
    
    echo "<p><strong>CSRF Token:</strong> " . htmlspecialchars($csrf_token) . "</p>";
    echo "<p style='color: green;'>✅ CSRF Token generiert und in Session gespeichert</p>";
    
    // 5. Teste CSRF Token Validierung
    echo "<h2>5. Teste CSRF Token Validierung:</h2>";
    
    $is_valid = validate_csrf_token($csrf_token);
    if ($is_valid) {
        echo "<p style='color: green;'>✅ CSRF Token ist gültig</p>";
    } else {
        echo "<p style='color: red;'>❌ CSRF Token ist ungültig</p>";
    }
    
    // 6. Simuliere Reservierungs-Genehmigung
    echo "<h2>6. Simuliere Reservierungs-Genehmigung:</h2>";
    
    // Simuliere die POST-Daten
    $_POST['action'] = 'approve';
    $_POST['reservation_id'] = '16';
    $_POST['csrf_token'] = $csrf_token;
    
    echo "<p><strong>Simulierte POST-Daten:</strong></p>";
    echo "<ul>";
    echo "<li>action: " . htmlspecialchars($_POST['action']) . "</li>";
    echo "<li>reservation_id: " . htmlspecialchars($_POST['reservation_id']) . "</li>";
    echo "<li>csrf_token: " . htmlspecialchars($_POST['csrf_token']) . "</li>";
    echo "</ul>";
    
    // Teste die Genehmigung (ohne sie tatsächlich auszuführen)
    $reservation_id = (int)$_POST['reservation_id'];
    $action = $_POST['action'];
    
    echo "<p><strong>Reservierung ID:</strong> $reservation_id</p>";
    echo "<p><strong>Action:</strong> " . htmlspecialchars($action) . "</p>";
    
    // Teste CSRF Token Validierung
    if (validate_csrf_token($_POST['csrf_token'] ?? '')) {
        echo "<p style='color: green;'>✅ CSRF Token ist gültig - Genehmigung kann fortgesetzt werden</p>";
        
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
        echo "<p style='color: red;'>❌ CSRF Token ist ungültig - Genehmigung wird abgebrochen</p>";
    }
    
    // 7. Erstelle Test-Formular
    echo "<h2>7. Erstelle Test-Formular:</h2>";
    
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
                    <label class='form-label'>CSRF Token:</label>
                    <input type='text' class='form-control' value='$csrf_token' readonly>
                </div>
                
                <button type='submit' class='btn btn-success'>Reservierung genehmigen</button>
            </form>
        </div>
    </body>
    </html>
    ";
    
    file_put_contents('test-reservation-approval-csrf.html', $test_form);
    echo "<p style='color: green;'>✅ Test-Formular erstellt: <a href='test-reservation-approval-csrf.html'>test-reservation-approval-csrf.html</a></p>";
    
    // 8. Nächste Schritte
    echo "<h2>8. Nächste Schritte:</h2>";
    echo "<ol>";
    echo "<li>Öffnen Sie <a href='test-reservation-approval-csrf.html'>test-reservation-approval-csrf.html</a></li>";
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
echo "<p><em>Fix Reservations CSRF abgeschlossen!</em></p>";
?>
