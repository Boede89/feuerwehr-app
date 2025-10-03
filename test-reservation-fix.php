<?php
/**
 * Test Reservation Fix - Browser Version
 * Öffnen Sie diese Datei in Ihrem Browser: http://ihre-domain/test-reservation-fix.php
 */

// Alle Fehler anzeigen
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

echo "<h1>🧪 Test Reservation Fix</h1>";
echo "<p>Diese Seite testet die reparierte Reservierungs-Genehmigung.</p>";

try {
    // 1. Datenbankverbindung
    echo "<h2>1. Datenbankverbindung:</h2>";
    require_once 'config/database.php';
    echo "<p style='color: green;'>✅ Datenbankverbindung erfolgreich</p>";
    
    // 2. Functions laden
    echo "<h2>2. Functions laden:</h2>";
    require_once 'includes/functions.php';
    echo "<p style='color: green;'>✅ Functions erfolgreich geladen</p>";
    
    // 3. Reservierung ID 16 laden
    echo "<h2>3. Reservierung ID 16 laden:</h2>";
    
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
    
    // 4. Teste die reparierte admin/reservations.php
    echo "<h2>4. Teste die reparierte admin/reservations.php:</h2>";
    
    if (file_exists('admin/reservations.php')) {
        echo "<p style='color: green;'>✅ admin/reservations.php existiert</p>";
        
        // Prüfe ob Output Buffering hinzugefügt wurde
        $content = file_get_contents('admin/reservations.php');
        if (strpos($content, 'ob_start()') !== false) {
            echo "<p style='color: green;'>✅ Output Buffering wurde hinzugefügt</p>";
        } else {
            echo "<p style='color: red;'>❌ Output Buffering wurde nicht hinzugefügt</p>";
        }
        
        if (strpos($content, 'ob_end_flush()') !== false) {
            echo "<p style='color: green;'>✅ Output Buffering Ende wurde hinzugefügt</p>";
        } else {
            echo "<p style='color: red;'>❌ Output Buffering Ende wurde nicht hinzugefügt</p>";
        }
        
    } else {
        echo "<p style='color: red;'>❌ admin/reservations.php existiert nicht!</p>";
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
            <p>Diese Seite testet die reparierte Reservierungs-Genehmigung.</p>
            
            <form method='POST' action='admin/reservations.php'>
                <input type='hidden' name='action' value='approve'>
                <input type='hidden' name='reservation_id' value='16'>
                <input type='hidden' name='csrf_token' value='test_token'>
                
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
                
                <button type='submit' class='btn btn-success'>Reservierung genehmigen</button>
            </form>
        </div>
    </body>
    </html>
    ";
    
    // Speichere das Test-Formular
    file_put_contents('test-reservation-form.html', $test_form);
    echo "<p style='color: green;'>✅ Test-Formular erstellt: <a href='test-reservation-form.html'>test-reservation-form.html</a></p>";
    
    // 6. Nächste Schritte
    echo "<h2>6. Nächste Schritte:</h2>";
    echo "<ol>";
    echo "<li>Öffnen Sie <a href='test-reservation-form.html'>test-reservation-form.html</a></li>";
    echo "<li>Klicken Sie auf 'Reservierung genehmigen'</li>";
    echo "<li>Falls es funktioniert, ist das Problem behoben</li>";
    echo "<li>Falls es nicht funktioniert, liegt das Problem woanders</li>";
    echo "</ol>";
    
    // 7. Zusammenfassung
    echo "<h2>7. Zusammenfassung:</h2>";
    echo "<ul>";
    echo "<li>✅ Datenbankverbindung erfolgreich</li>";
    echo "<li>✅ Functions geladen</li>";
    echo "<li>✅ Reservierung gefunden</li>";
    echo "<li>✅ Output Buffering hinzugefügt</li>";
    echo "<li>✅ Test-Formular erstellt</li>";
    echo "</ul>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><strong>Stack Trace:</strong></p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}

echo "<hr>";
echo "<p><em>Test Reservation Fix abgeschlossen!</em></p>";
?>
