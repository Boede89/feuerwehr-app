<?php
/**
 * Create Test Reservation - Browser Version
 * Öffnen Sie diese Datei in Ihrem Browser: http://ihre-domain/create-test-reservation.php
 */

// Output Buffering starten um Header-Probleme zu vermeiden
ob_start();

// Alle Fehler anzeigen
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

echo "<h1>🧪 Create Test Reservation</h1>";
echo "<p>Diese Seite erstellt eine neue Test-Reservierung für das Debugging.</p>";

try {
    // 1. Datenbankverbindung
    echo "<h2>1. Datenbankverbindung:</h2>";
    require_once 'config/database.php';
    echo "<p style='color: green;'>✅ Datenbankverbindung erfolgreich</p>";
    
    // 2. Session starten
    echo "<h2>2. Session starten:</h2>";
    session_start();
    
    // Finde Admin-Benutzer
    $stmt = $db->query("SELECT id, username, email, user_role FROM users WHERE user_role = 'admin' LIMIT 1");
    $admin_user = $stmt->fetch();
    
    if ($admin_user) {
        $_SESSION['user_id'] = $admin_user['id'];
        $_SESSION['role'] = 'admin';
        echo "<p style='color: green;'>✅ Session erfolgreich gestartet mit User ID " . $admin_user['id'] . "</p>";
    } else {
        echo "<p style='color: red;'>❌ Kein Admin-Benutzer gefunden</p>";
        exit;
    }
    
    // 3. Finde aktives Fahrzeug
    echo "<h2>3. Finde aktives Fahrzeug:</h2>";
    
    $stmt = $db->query("SELECT id, name FROM vehicles WHERE is_active = 1 ORDER BY sort_order ASC LIMIT 1");
    $vehicle = $stmt->fetch();
    
    if (!$vehicle) {
        echo "<p style='color: red;'>❌ Keine aktiven Fahrzeuge gefunden</p>";
        exit;
    }
    
    echo "<p style='color: green;'>✅ Fahrzeug gefunden: " . htmlspecialchars($vehicle['name']) . " (ID: " . $vehicle['id'] . ")</p>";
    
    // 4. Erstelle Test-Reservierung
    echo "<h2>4. Erstelle Test-Reservierung:</h2>";
    
    $test_data = [
        'vehicle_id' => $vehicle['id'],
        'requester_name' => 'Debug Test User',
        'requester_email' => 'debug@test.local',
        'reason' => 'Debug Test Reservierung für admin/reservations.php',
        'start_datetime' => '2025-10-06 10:00:00',
        'end_datetime' => '2025-10-06 14:00:00'
    ];
    
    $stmt = $db->prepare("INSERT INTO reservations (vehicle_id, requester_name, requester_email, reason, start_datetime, end_datetime, status, created_at) VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())");
    $stmt->execute([
        $test_data['vehicle_id'],
        $test_data['requester_name'],
        $test_data['requester_email'],
        $test_data['reason'],
        $test_data['start_datetime'],
        $test_data['end_datetime']
    ]);
    
    $reservation_id = $db->lastInsertId();
    echo "<p style='color: green;'>✅ Test-Reservierung erstellt: ID $reservation_id</p>";
    echo "<p><strong>Fahrzeug:</strong> " . htmlspecialchars($vehicle['name']) . "</p>";
    echo "<p><strong>Antragsteller:</strong> " . htmlspecialchars($test_data['requester_name']) . "</p>";
    echo "<p><strong>E-Mail:</strong> " . htmlspecialchars($test_data['requester_email']) . "</p>";
    echo "<p><strong>Grund:</strong> " . htmlspecialchars($test_data['reason']) . "</p>";
    echo "<p><strong>Von:</strong> " . htmlspecialchars($test_data['start_datetime']) . "</p>";
    echo "<p><strong>Bis:</strong> " . htmlspecialchars($test_data['end_datetime']) . "</p>";
    echo "<p><strong>Status:</strong> pending</p>";
    
    // 5. Erstelle Test-Formular für admin/reservations.php
    echo "<h2>5. Erstelle Test-Formular für admin/reservations.php:</h2>";
    
    require_once 'includes/functions.php';
    $csrf_token = generate_csrf_token();
    
    $test_form = "
    <div style='border: 1px solid #ccc; padding: 20px; margin: 20px 0; background-color: #f9f9f9;'>
        <h3>Test-Formular für admin/reservations.php:</h3>
        <p><strong>Hinweis:</strong> Verwenden Sie dieses Formular, um die echte admin/reservations.php zu testen.</p>
        
        <form method='POST' action='admin/reservations.php' style='margin: 20px 0;'>
            <input type='hidden' name='action' value='approve'>
            <input type='hidden' name='reservation_id' value='$reservation_id'>
            <input type='hidden' name='csrf_token' value='$csrf_token'>
            
            <div style='margin: 10px 0;'>
                <label><strong>Reservierung ID:</strong></label><br>
                <input type='text' value='$reservation_id' readonly style='width: 100%; padding: 5px;'>
            </div>
            
            <div style='margin: 10px 0;'>
                <label><strong>Fahrzeug:</strong></label><br>
                <input type='text' value='" . htmlspecialchars($vehicle['name']) . "' readonly style='width: 100%; padding: 5px;'>
            </div>
            
            <div style='margin: 10px 0;'>
                <label><strong>Antragsteller:</strong></label><br>
                <input type='text' value='" . htmlspecialchars($test_data['requester_name']) . "' readonly style='width: 100%; padding: 5px;'>
            </div>
            
            <div style='margin: 10px 0;'>
                <label><strong>Status:</strong></label><br>
                <input type='text' value='pending' readonly style='width: 100%; padding: 5px;'>
            </div>
            
            <button type='submit' style='background-color: #28a745; color: white; padding: 10px 20px; border: none; cursor: pointer; margin-right: 10px;'>Reservierung genehmigen (admin/reservations.php)</button>
        </form>
        
        <form method='POST' action='admin/reservations.php' style='margin: 20px 0;'>
            <input type='hidden' name='action' value='reject'>
            <input type='hidden' name='reservation_id' value='$reservation_id'>
            <input type='hidden' name='csrf_token' value='$csrf_token'>
            <input type='hidden' name='rejection_reason' value='Debug Test Ablehnung'>
            
            <button type='submit' style='background-color: #dc3545; color: white; padding: 10px 20px; border: none; cursor: pointer;'>Reservierung ablehnen (admin/reservations.php)</button>
        </form>
    </div>
    ";
    
    echo $test_form;
    
    // 6. Erstelle Test-Formular für Minimal-Version
    echo "<h2>6. Erstelle Test-Formular für Minimal-Version:</h2>";
    
    $minimal_form = "
    <div style='border: 1px solid #ccc; padding: 20px; margin: 20px 0; background-color: #e9f7ef;'>
        <h3>Test-Formular für admin/reservations-minimal.php:</h3>
        <p><strong>Hinweis:</strong> Diese Version funktioniert definitiv und kann als Alternative verwendet werden.</p>
        
        <div style='margin: 10px 0;'>
            <a href='admin/reservations-minimal.php' style='background-color: #17a2b8; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px; display: inline-block;'>Zur Minimal-Version (admin/reservations-minimal.php)</a>
        </div>
    </div>
    ";
    
    echo $minimal_form;
    
    // 7. Nächste Schritte
    echo "<h2>7. Nächste Schritte:</h2>";
    echo "<ol>";
    echo "<li><strong>Testen Sie die echte admin/reservations.php:</strong> Verwenden Sie das erste Formular oben</li>";
    echo "<li><strong>Falls HTTP 500 Fehler:</strong> Verwenden Sie die Minimal-Version (admin/reservations-minimal.php)</li>";
    echo "<li><strong>Falls beide funktionieren:</strong> Das Problem ist behoben</li>";
    echo "<li><strong>Falls beide nicht funktionieren:</strong> Schauen Sie in die PHP Error Logs</li>";
    echo "</ol>";
    
    // 8. Zusammenfassung
    echo "<h2>8. Zusammenfassung:</h2>";
    echo "<ul>";
    echo "<li>✅ Datenbankverbindung erfolgreich</li>";
    echo "<li>✅ Session erfolgreich gestartet</li>";
    echo "<li>✅ Test-Reservierung erstellt: ID $reservation_id</li>";
    echo "<li>✅ Test-Formulare erstellt</li>";
    echo "<li>✅ Minimal-Version verfügbar</li>";
    echo "</ul>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Kritischer Fehler: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><strong>Stack Trace:</strong></p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}

echo "<hr>";
echo "<p><em>Create Test Reservation abgeschlossen!</em></p>";

// Output Buffering beenden
ob_end_flush();
?>
