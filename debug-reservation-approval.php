<?php
/**
 * Debug Reservation Approval - Browser Version
 * Öffnen Sie diese Datei in Ihrem Browser: http://ihre-domain/debug-reservation-approval.php
 */

require_once 'config/database.php';

echo "<h1>🔍 Debug Reservation Approval</h1>";
echo "<p>Diese Seite debuggt das Problem mit der Reservierungs-Genehmigung.</p>";

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
    echo "<table border='1' cellpadding='5' cellspacing='0'>";
    echo "<tr><th>Feld</th><th>Wert</th></tr>";
    foreach ($reservation as $key => $value) {
        echo "<tr><td><strong>$key</strong></td><td>" . htmlspecialchars($value ?? 'NULL') . "</td></tr>";
    }
    echo "</table>";
    
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
    echo "<table border='1' cellpadding='5' cellspacing='0'>";
    echo "<tr><th>Feld</th><th>Wert</th></tr>";
    foreach ($vehicle as $key => $value) {
        echo "<tr><td><strong>$key</strong></td><td>" . htmlspecialchars($value ?? 'NULL') . "</td></tr>";
    }
    echo "</table>";
    
    // 3. Teste den problematischen JOIN
    echo "<h2>3. Teste problematischen JOIN:</h2>";
    
    $stmt = $db->prepare("SELECT r.*, v.name as vehicle_name FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id WHERE r.id = ?");
    $stmt->execute([16]);
    $reservation_with_vehicle = $stmt->fetch();
    
    if (!$reservation_with_vehicle) {
        echo "<p style='color: red;'>❌ JOIN fehlgeschlagen!</p>";
        
        // Prüfe ob es ein Problem mit der vehicles Tabelle gibt
        echo "<h3>3.1. Vehicles Tabelle Struktur prüfen:</h3>";
        $stmt = $db->query("SHOW COLUMNS FROM vehicles");
        $columns = $stmt->fetchAll();
        echo "<table border='1' cellpadding='5' cellspacing='0'>";
        echo "<tr><th>Spalte</th><th>Typ</th><th>Null</th><th>Key</th><th>Default</th></tr>";
        foreach ($columns as $column) {
            echo "<tr>";
            echo "<td><strong>" . htmlspecialchars($column['Field']) . "</strong></td>";
            echo "<td>" . htmlspecialchars($column['Type']) . "</td>";
            echo "<td>" . htmlspecialchars($column['Null']) . "</td>";
            echo "<td>" . htmlspecialchars($column['Key']) . "</td>";
            echo "<td>" . htmlspecialchars($column['Default'] ?? 'NULL') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        // Prüfe ob es ein Problem mit der reservations Tabelle gibt
        echo "<h3>3.2. Reservations Tabelle Struktur prüfen:</h3>";
        $stmt = $db->query("SHOW COLUMNS FROM reservations");
        $columns = $stmt->fetchAll();
        echo "<table border='1' cellpadding='5' cellspacing='0'>";
        echo "<tr><th>Spalte</th><th>Typ</th><th>Null</th><th>Key</th><th>Default</th></tr>";
        foreach ($columns as $column) {
            echo "<tr>";
            echo "<td><strong>" . htmlspecialchars($column['Field']) . "</strong></td>";
            echo "<td>" . htmlspecialchars($column['Type']) . "</td>";
            echo "<td>" . htmlspecialchars($column['Null']) . "</td>";
            echo "<td>" . htmlspecialchars($column['Key']) . "</td>";
            echo "<td>" . htmlspecialchars($column['Default'] ?? 'NULL') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
        
    } else {
        echo "<p style='color: green;'>✅ JOIN erfolgreich!</p>";
        echo "<p><strong>Fahrzeug Name:</strong> " . htmlspecialchars($reservation_with_vehicle['vehicle_name']) . "</p>";
    }
    
    // 4. Teste Google Calendar Funktion
    echo "<h2>4. Teste Google Calendar Funktion:</h2>";
    
    if (function_exists('create_google_calendar_event')) {
        echo "<p style='color: green;'>✅ create_google_calendar_event Funktion existiert</p>";
        
        // Teste mit den Daten der Reservierung
        $vehicle_name = $vehicle['name'];
        $reason = $reservation['reason'];
        $start_datetime = $reservation['start_datetime'];
        $end_datetime = $reservation['end_datetime'];
        
        echo "<p><strong>Test-Parameter:</strong></p>";
        echo "<ul>";
        echo "<li>Fahrzeug: " . htmlspecialchars($vehicle_name) . "</li>";
        echo "<li>Grund: " . htmlspecialchars($reason) . "</li>";
        echo "<li>Von: " . htmlspecialchars($start_datetime) . "</li>";
        echo "<li>Bis: " . htmlspecialchars($end_datetime) . "</li>";
        echo "</ul>";
        
        // Teste die Funktion (ohne sie tatsächlich auszuführen)
        echo "<p><strong>Hinweis:</strong> Google Calendar Funktion wird nicht ausgeführt, nur getestet</p>";
        
    } else {
        echo "<p style='color: red;'>❌ create_google_calendar_event Funktion existiert nicht!</p>";
    }
    
    // 5. Teste E-Mail Funktion
    echo "<h2>5. Teste E-Mail Funktion:</h2>";
    
    if (function_exists('send_email')) {
        echo "<p style='color: green;'>✅ send_email Funktion existiert</p>";
    } else {
        echo "<p style='color: red;'>❌ send_email Funktion existiert nicht!</p>";
    }
    
    // 6. Prüfe PHP Fehler
    echo "<h2>6. PHP Fehler prüfen:</h2>";
    
    $error_log = ini_get('error_log');
    if ($error_log && file_exists($error_log)) {
        echo "<p><strong>Error Log:</strong> $error_log</p>";
        $errors = file_get_contents($error_log);
        $recent_errors = array_slice(explode("\n", $errors), -20); // Letzte 20 Zeilen
        echo "<pre>" . htmlspecialchars(implode("\n", $recent_errors)) . "</pre>";
    } else {
        echo "<p style='color: orange;'>⚠️ Kein Error Log gefunden oder nicht lesbar</p>";
    }
    
    // 7. Empfohlene Aktionen
    echo "<h2>7. Empfohlene Aktionen:</h2>";
    
    if (!$reservation_with_vehicle) {
        echo "<p style='color: red;'>❌ Das Problem liegt beim JOIN zwischen reservations und vehicles</p>";
        echo "<ol>";
        echo "<li>Überprüfen Sie die Datenbank-Struktur</li>";
        echo "<li>Stellen Sie sicher, dass die vehicles Tabelle korrekt ist</li>";
        echo "<li>Testen Sie den JOIN manuell</li>";
        echo "</ol>";
    } else {
        echo "<p style='color: green;'>✅ JOIN funktioniert, das Problem liegt woanders</p>";
        echo "<ol>";
        echo "<li>Überprüfen Sie die Google Calendar Konfiguration</li>";
        echo "<li>Überprüfen Sie die E-Mail Konfiguration</li>";
        echo "<li>Schauen Sie in die PHP Error Logs</li>";
        echo "</ol>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><strong>Stack Trace:</strong></p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}

echo "<hr>";
echo "<p><em>Debug Reservation Approval abgeschlossen!</em></p>";
?>
