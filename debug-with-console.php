<?php
require_once __DIR__ . '/includes/debug-auth.php';
/**
 * Debug mit Browser-Console-Logging
 */

echo "<!DOCTYPE html>";
echo "<html><head><title>Debug mit Console</title></head><body>";
echo "<h1>🔍 Debug mit Browser-Console</h1>";
echo "<p>Zeitstempel: " . date('d.m.Y H:i:s') . "</p>";

echo "<script>";
echo "console.log('🔍 Debug-Script gestartet');";
echo "console.log('Zeitstempel: " . date('d.m.Y H:i:s') . "');";
echo "</script>";

try {
    echo "<h2>1. Datenbank-Verbindung testen</h2>";
    require_once 'config/database.php';
    echo "✅ Datenbank-Verbindung erfolgreich<br>";
    
    echo "<script>console.log('✅ Datenbank-Verbindung erfolgreich');</script>";
    
    echo "<h2>2. Lade functions.php</h2>";
    require_once 'includes/functions.php';
    echo "✅ includes/functions.php geladen<br>";
    
    echo "<script>console.log('✅ includes/functions.php geladen');</script>";
    
    echo "<h2>3. Prüfe Funktionen</h2>";
    
    $functions = [
        'check_calendar_conflicts',
        'create_google_calendar_event',
        'validate_csrf_token',
        'has_admin_access'
    ];
    
    foreach ($functions as $function) {
        if (function_exists($function)) {
            echo "✅ $function Funktion ist verfügbar<br>";
            echo "<script>console.log('✅ $function Funktion ist verfügbar');</script>";
        } else {
            echo "❌ $function Funktion ist NICHT verfügbar<br>";
            echo "<script>console.error('❌ $function Funktion ist NICHT verfügbar');</script>";
        }
    }
    
    echo "<h2>4. Teste check_calendar_conflicts</h2>";
    
    if (function_exists('check_calendar_conflicts')) {
        echo "Teste Funktion...<br>";
        echo "<script>console.log('Teste check_calendar_conflicts Funktion...');</script>";
        
        $test_vehicle = 'MTF';
        $test_start = date('Y-m-d H:i:s', strtotime('+1 day'));
        $test_end = date('Y-m-d H:i:s', strtotime('+1 day +2 hours'));
        
        echo "Test-Parameter:<br>";
        echo "- Fahrzeug: $test_vehicle<br>";
        echo "- Start: $test_start<br>";
        echo "- Ende: $test_end<br>";
        
        echo "<script>";
        echo "console.log('Test-Parameter:');";
        echo "console.log('- Fahrzeug: $test_vehicle');";
        echo "console.log('- Start: $test_start');";
        echo "console.log('- Ende: $test_end');";
        echo "</script>";
        
        $start_time = microtime(true);
        $conflicts = check_calendar_conflicts($test_vehicle, $test_start, $test_end);
        $end_time = microtime(true);
        $execution_time = ($end_time - $start_time) * 1000;
        
        echo "Ausführungszeit: " . round($execution_time, 2) . " ms<br>";
        echo "<script>console.log('Ausführungszeit: " . round($execution_time, 2) . " ms');</script>";
        
        if (is_array($conflicts)) {
            echo "✅ Funktion erfolgreich ausgeführt<br>";
            echo "Gefundene Konflikte: " . count($conflicts) . "<br>";
            echo "<script>console.log('✅ Funktion erfolgreich ausgeführt');</script>";
            echo "<script>console.log('Gefundene Konflikte: " . count($conflicts) . "');</script>";
        } else {
            echo "❌ Funktion hat einen Fehler zurückgegeben<br>";
            echo "<script>console.error('❌ Funktion hat einen Fehler zurückgegeben');</script>";
        }
    }
    
    echo "<h2>5. Teste Reservierungsgenehmigung</h2>";
    
    // Simuliere eine Reservierungsgenehmigung
    echo "Simuliere Reservierungsgenehmigung...<br>";
    echo "<script>console.log('Simuliere Reservierungsgenehmigung...');</script>";
    
    // Lade ausstehende Reservierung
    $stmt = $db->prepare("
        SELECT r.*, v.name as vehicle_name 
        FROM reservations r 
        JOIN vehicles v ON r.vehicle_id = v.id 
        WHERE r.status = 'pending'
        ORDER BY r.created_at DESC 
        LIMIT 1
    ");
    $stmt->execute();
    $reservation = $stmt->fetch();
    
    if ($reservation) {
        echo "Reservierung gefunden: ID {$reservation['id']}, Fahrzeug: {$reservation['vehicle_name']}<br>";
        echo "<script>console.log('Reservierung gefunden: ID {$reservation['id']}, Fahrzeug: {$reservation['vehicle_name']}');</script>";
        
        // Teste Google Calendar Event erstellen
        if (function_exists('create_google_calendar_event')) {
            echo "Teste Google Calendar Event erstellen...<br>";
            echo "<script>console.log('Teste Google Calendar Event erstellen...');</script>";
            
            $event_id = create_google_calendar_event(
                $reservation['vehicle_name'],
                $reservation['reason'],
                $reservation['start_datetime'],
                $reservation['end_datetime'],
                $reservation['id'],
                $reservation['location']
            );
            
            if ($event_id) {
                echo "✅ Google Calendar Event erfolgreich erstellt! Event ID: $event_id<br>";
                echo "<script>console.log('✅ Google Calendar Event erfolgreich erstellt! Event ID: $event_id');</script>";
            } else {
                echo "❌ Google Calendar Event konnte nicht erstellt werden<br>";
                echo "<script>console.error('❌ Google Calendar Event konnte nicht erstellt werden');</script>";
            }
        } else {
            echo "❌ create_google_calendar_event Funktion nicht verfügbar<br>";
            echo "<script>console.error('❌ create_google_calendar_event Funktion nicht verfügbar');</script>";
        }
    } else {
        echo "ℹ️ Keine ausstehende Reservierung gefunden<br>";
        echo "<script>console.log('ℹ️ Keine ausstehende Reservierung gefunden');</script>";
    }
    
    echo "<h2>6. Teste Admin-Zugriff</h2>";
    
    if (function_exists('has_admin_access')) {
        echo "Teste Admin-Zugriff...<br>";
        echo "<script>console.log('Teste Admin-Zugriff...');</script>";
        
        // Simuliere Session
        session_start();
        $_SESSION['user_id'] = 1;
        $_SESSION['role'] = 'admin';
        
        if (has_admin_access()) {
            echo "✅ Admin-Zugriff verfügbar<br>";
            echo "<script>console.log('✅ Admin-Zugriff verfügbar');</script>";
        } else {
            echo "❌ Admin-Zugriff nicht verfügbar<br>";
            echo "<script>console.error('❌ Admin-Zugriff nicht verfügbar');</script>";
        }
    }
    
} catch (Exception $e) {
    echo "<div style='color: red;'>";
    echo "<h3>❌ Fehler aufgetreten:</h3>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    echo "</div>";
    
    echo "<script>";
    echo "console.error('❌ Fehler aufgetreten: " . addslashes($e->getMessage()) . "');";
    echo "console.error('Stack Trace: " . addslashes($e->getTraceAsString()) . "');";
    echo "</script>";
}

echo "<hr>";
echo "<h3>📋 Browser-Console öffnen:</h3>";
echo "<p>Drücke F12 oder Rechtsklick → 'Element untersuchen' → 'Console' Tab</p>";
echo "<p>Alle Debug-Informationen werden in der Console angezeigt.</p>";

echo "<script>";
echo "console.log('🎉 Debug-Script abgeschlossen');";
echo "console.log('Zeitstempel: " . date('d.m.Y H:i:s') . "');";
echo "</script>";

echo "<p><a href='admin/dashboard.php'>Zum Dashboard</a> | <a href='admin/reservations.php'>Zu den Reservierungen</a></p>";
echo "</body></html>";
?>
