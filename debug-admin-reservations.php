<?php
/**
 * Debug Admin Reservations - Browser Version
 * Öffnen Sie diese Datei in Ihrem Browser: http://ihre-domain/debug-admin-reservations.php
 */

// Output Buffering starten um Header-Probleme zu vermeiden
ob_start();

// Alle Fehler anzeigen
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

echo "<h1>🔍 Debug Admin Reservations</h1>";
echo "<p>Diese Seite debuggt die admin/reservations.php Datei.</p>";

try {
    // 1. Datenbankverbindung
    echo "<h2>1. Datenbankverbindung:</h2>";
    require_once 'config/database.php';
    echo "<p style='color: green;'>✅ Datenbankverbindung erfolgreich</p>";
    
    // 2. Functions laden
    echo "<h2>2. Functions laden:</h2>";
    require_once 'includes/functions.php';
    echo "<p style='color: green;'>✅ Functions geladen</p>";
    
    // 3. Session starten
    echo "<h2>3. Session starten:</h2>";
    session_start();
    
    // Finde Admin-Benutzer
    $stmt = $db->query("SELECT id, username, email, user_role FROM users WHERE user_role = 'admin' LIMIT 1");
    $admin_user = $stmt->fetch();
    
    if ($admin_user) {
        $_SESSION['user_id'] = $admin_user['id'];
        $_SESSION['role'] = 'admin';
        echo "<p style='color: green;'>✅ Session erfolgreich gestartet mit User ID " . $admin_user['id'] . "</p>";
        echo "<p><strong>Benutzername:</strong> " . htmlspecialchars($admin_user['username']) . "</p>";
        echo "<p><strong>Rolle:</strong> " . htmlspecialchars($admin_user['user_role']) . "</p>";
    } else {
        echo "<p style='color: red;'>❌ Kein Admin-Benutzer gefunden</p>";
        exit;
    }
    
    // 4. Teste can_approve_reservations Funktion
    echo "<h2>4. Teste can_approve_reservations Funktion:</h2>";
    
    if (function_exists('can_approve_reservations')) {
        $can_approve = can_approve_reservations();
        if ($can_approve) {
            echo "<p style='color: green;'>✅ can_approve_reservations() gibt true zurück</p>";
        } else {
            echo "<p style='color: red;'>❌ can_approve_reservations() gibt false zurück</p>";
        }
    } else {
        echo "<p style='color: red;'>❌ can_approve_reservations() Funktion existiert nicht</p>";
    }
    
    // 5. Teste is_logged_in Funktion
    echo "<h2>5. Teste is_logged_in Funktion:</h2>";
    
    if (function_exists('is_logged_in')) {
        $is_logged_in = is_logged_in();
        if ($is_logged_in) {
            echo "<p style='color: green;'>✅ is_logged_in() gibt true zurück</p>";
        } else {
            echo "<p style='color: red;'>❌ is_logged_in() gibt false zurück</p>";
        }
    } else {
        echo "<p style='color: red;'>❌ is_logged_in() Funktion existiert nicht</p>";
    }
    
    // 6. Teste Session-Variablen
    echo "<h2>6. Teste Session-Variablen:</h2>";
    echo "<p><strong>user_id:</strong> " . ($_SESSION['user_id'] ?? 'NICHT GESETZT') . "</p>";
    echo "<p><strong>role:</strong> " . ($_SESSION['role'] ?? 'NICHT GESETZT') . "</p>";
    echo "<p><strong>username:</strong> " . ($_SESSION['username'] ?? 'NICHT GESETZT') . "</p>";
    
    // 7. Teste Reservierungen laden
    echo "<h2>7. Teste Reservierungen laden:</h2>";
    
    try {
        $stmt = $db->query("SELECT r.*, v.name as vehicle_name FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id ORDER BY r.created_at DESC");
        $reservations = $stmt->fetchAll();
        
        if (empty($reservations)) {
            echo "<p style='color: red;'>❌ Keine Reservierungen gefunden</p>";
        } else {
            echo "<p style='color: green;'>✅ " . count($reservations) . " Reservierungen gefunden</p>";
            echo "<table border='1' cellpadding='5' cellspacing='0'>";
            echo "<tr><th>ID</th><th>Fahrzeug</th><th>Antragsteller</th><th>Status</th><th>Genehmigt von</th></tr>";
            foreach ($reservations as $reservation) {
                echo "<tr>";
                echo "<td>" . htmlspecialchars($reservation['id']) . "</td>";
                echo "<td>" . htmlspecialchars($reservation['vehicle_name']) . "</td>";
                echo "<td>" . htmlspecialchars($reservation['requester_name']) . "</td>";
                echo "<td>" . htmlspecialchars($reservation['status']) . "</td>";
                echo "<td>" . htmlspecialchars($reservation['approved_by'] ?? 'N/A') . "</td>";
                echo "</tr>";
            }
            echo "</table>";
        }
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Reservierungen laden fehlgeschlagen: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
    
    // 8. Teste admin/reservations.php direkt
    echo "<h2>8. Teste admin/reservations.php direkt:</h2>";
    
    try {
        // Simuliere die admin/reservations.php Logik
        echo "<h3>8.1. Simuliere admin/reservations.php Logik:</h3>";
        
        // Prüfe can_approve_reservations
        if (!can_approve_reservations()) {
            echo "<p style='color: red;'>❌ can_approve_reservations() gibt false zurück - würde zu login.php weiterleiten</p>";
        } else {
            echo "<p style='color: green;'>✅ can_approve_reservations() gibt true zurück - Zugriff erlaubt</p>";
        }
        
        // Teste CSRF Token
        echo "<h3>8.2. Teste CSRF Token:</h3>";
        
        if (function_exists('generate_csrf_token')) {
            $csrf_token = generate_csrf_token();
            echo "<p style='color: green;'>✅ CSRF Token generiert: " . substr($csrf_token, 0, 20) . "...</p>";
            
            if (function_exists('validate_csrf_token')) {
                $is_valid = validate_csrf_token($csrf_token);
                if ($is_valid) {
                    echo "<p style='color: green;'>✅ CSRF Token ist gültig</p>";
                } else {
                    echo "<p style='color: red;'>❌ CSRF Token ist ungültig</p>";
                }
            } else {
                echo "<p style='color: red;'>❌ validate_csrf_token() Funktion existiert nicht</p>";
            }
        } else {
            echo "<p style='color: red;'>❌ generate_csrf_token() Funktion existiert nicht</p>";
        }
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ admin/reservations.php Simulation fehlgeschlagen: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
    
    // 9. Erstelle Test-Formular für admin/reservations.php
    echo "<h2>9. Erstelle Test-Formular für admin/reservations.php:</h2>";
    
    try {
        // Finde pending Reservierung
        $stmt = $db->query("SELECT r.*, v.name as vehicle_name FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id WHERE r.status = 'pending' ORDER BY r.id DESC LIMIT 1");
        $pending_reservation = $stmt->fetch();
        
        if ($pending_reservation) {
            echo "<p style='color: green;'>✅ Pending Reservierung gefunden: ID " . $pending_reservation['id'] . "</p>";
            
            // Generiere CSRF Token
            $csrf_token = generate_csrf_token();
            
            // Erstelle Test-Formular
            $test_form = "
            <div style='border: 1px solid #ccc; padding: 20px; margin: 20px 0; background-color: #f9f9f9;'>
                <h3>Test-Formular für admin/reservations.php:</h3>
                <form method='POST' action='admin/reservations.php' style='margin: 20px 0;'>
                    <input type='hidden' name='action' value='approve'>
                    <input type='hidden' name='reservation_id' value='" . $pending_reservation['id'] . "'>
                    <input type='hidden' name='csrf_token' value='" . $csrf_token . "'>
                    
                    <div style='margin: 10px 0;'>
                        <label><strong>Reservierung ID:</strong></label><br>
                        <input type='text' value='" . $pending_reservation['id'] . "' readonly style='width: 100%; padding: 5px;'>
                    </div>
                    
                    <div style='margin: 10px 0;'>
                        <label><strong>Fahrzeug:</strong></label><br>
                        <input type='text' value='" . htmlspecialchars($pending_reservation['vehicle_name']) . "' readonly style='width: 100%; padding: 5px;'>
                    </div>
                    
                    <div style='margin: 10px 0;'>
                        <label><strong>Antragsteller:</strong></label><br>
                        <input type='text' value='" . htmlspecialchars($pending_reservation['requester_name']) . "' readonly style='width: 100%; padding: 5px;'>
                    </div>
                    
                    <div style='margin: 10px 0;'>
                        <label><strong>Status:</strong></label><br>
                        <input type='text' value='" . htmlspecialchars($pending_reservation['status']) . "' readonly style='width: 100%; padding: 5px;'>
                    </div>
                    
                    <button type='submit' style='background-color: #28a745; color: white; padding: 10px 20px; border: none; cursor: pointer;'>Reservierung genehmigen (admin/reservations.php)</button>
                </form>
            </div>
            ";
            
            echo $test_form;
            
        } else {
            echo "<p style='color: orange;'>⚠️ Keine pending Reservierungen gefunden</p>";
        }
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Test-Formular Fehler: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
    
    // 10. Nächste Schritte
    echo "<h2>10. Nächste Schritte:</h2>";
    echo "<ol>";
    echo "<li>Verwenden Sie das Test-Formular oben</li>";
    echo "<li>Klicken Sie auf 'Reservierung genehmigen (admin/reservations.php)'</li>";
    echo "<li>Falls es funktioniert, ist das Problem behoben</li>";
    echo "<li>Falls es nicht funktioniert, schauen Sie in die PHP Error Logs</li>";
    echo "</ol>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Kritischer Fehler: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><strong>Stack Trace:</strong></p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}

echo "<hr>";
echo "<p><em>Debug Admin Reservations abgeschlossen!</em></p>";

// Output Buffering beenden
ob_end_flush();
?>
