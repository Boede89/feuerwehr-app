<?php
/**
 * Test: Dashboard Syntax - Teste ob das Dashboard Syntax-Fehler hat
 */

echo "<h1>Test: Dashboard Syntax - Teste ob das Dashboard Syntax-Fehler hat</h1>";

echo "<h2>1. Teste Dashboard-Syntax</h2>";

// Teste ob das Dashboard geladen werden kann
$dashboard_file = 'admin/dashboard.php';

if (file_exists($dashboard_file)) {
    echo "<p>✅ Dashboard-Datei existiert: $dashboard_file</p>";
    
    // Teste ob die Datei gelesen werden kann
    $content = file_get_contents($dashboard_file);
    if ($content !== false) {
        echo "<p>✅ Dashboard-Datei kann gelesen werden (" . strlen($content) . " Zeichen)</p>";
        
        // Teste ob die Datei PHP-Syntax hat
        if (strpos($content, '<?php') !== false) {
            echo "<p>✅ Dashboard-Datei enthält PHP-Code</p>";
        } else {
            echo "<p>❌ Dashboard-Datei enthält keinen PHP-Code</p>";
        }
        
        // Teste ob die Datei schließende PHP-Tags hat
        if (strpos($content, '?>') !== false) {
            echo "<p>✅ Dashboard-Datei hat schließende PHP-Tags</p>";
        } else {
            echo "<p>⚠️ Dashboard-Datei hat keine schließenden PHP-Tags</p>";
        }
        
    } else {
        echo "<p>❌ Dashboard-Datei kann nicht gelesen werden</p>";
    }
} else {
    echo "<p>❌ Dashboard-Datei existiert nicht: $dashboard_file</p>";
}

echo "<h2>2. Teste Dashboard-Includes</h2>";

// Teste ob die Includes existieren
$includes = [
    'config/database.php',
    'includes/functions.php',
    'includes/google_calendar_service_account.php',
    'includes/google_calendar.php'
];

foreach ($includes as $include) {
    if (file_exists($include)) {
        echo "<p>✅ Include existiert: $include</p>";
    } else {
        echo "<p>❌ Include existiert nicht: $include</p>";
    }
}

echo "<h2>3. Teste Dashboard-Datenbank</h2>";

try {
    require_once 'config/database.php';
    echo "<p>✅ Datenbank-Verbindung erfolgreich</p>";
    
    // Teste einfache Abfrage
    $stmt = $db->query("SELECT COUNT(*) as count FROM reservations");
    $result = $stmt->fetch();
    echo "<p>✅ Datenbank-Abfrage erfolgreich - Reservierungen: " . $result['count'] . "</p>";
    
} catch (Exception $e) {
    echo "<p>❌ Datenbank-Fehler: " . $e->getMessage() . "</p>";
}

echo "<h2>4. Teste Dashboard-Funktionen</h2>";

try {
    require_once 'includes/functions.php';
    echo "<p>✅ Functions-Datei geladen</p>";
    
    if (function_exists('create_google_calendar_event')) {
        echo "<p>✅ create_google_calendar_event Funktion verfügbar</p>";
    } else {
        echo "<p>❌ create_google_calendar_event Funktion NICHT verfügbar</p>";
    }
    
} catch (Exception $e) {
    echo "<p>❌ Functions-Fehler: " . $e->getMessage() . "</p>";
}

echo "<h2>5. Teste Dashboard-Google-Calendar</h2>";

try {
    require_once 'includes/google_calendar_service_account.php';
    echo "<p>✅ GoogleCalendarServiceAccount-Datei geladen</p>";
    
    if (class_exists('GoogleCalendarServiceAccount')) {
        echo "<p>✅ GoogleCalendarServiceAccount Klasse verfügbar</p>";
    } else {
        echo "<p>❌ GoogleCalendarServiceAccount Klasse NICHT verfügbar</p>";
    }
    
} catch (Exception $e) {
    echo "<p>❌ GoogleCalendarServiceAccount-Fehler: " . $e->getMessage() . "</p>";
}

echo "<h2>6. Teste Dashboard-Session</h2>";

try {
    session_start();
    echo "<p>✅ Session gestartet</p>";
    
    if (isset($_SESSION['user_id'])) {
        echo "<p>✅ Session user_id: " . $_SESSION['user_id'] . "</p>";
    } else {
        echo "<p>⚠️ Session user_id nicht gesetzt</p>";
    }
    
    if (isset($_SESSION['role'])) {
        echo "<p>✅ Session role: " . $_SESSION['role'] . "</p>";
    } else {
        echo "<p>⚠️ Session role nicht gesetzt</p>";
    }
    
} catch (Exception $e) {
    echo "<p>❌ Session-Fehler: " . $e->getMessage() . "</p>";
}

echo "<h2>7. Nächste Schritte</h2>";
echo "<p>1. <a href='admin/dashboard.php'>Teste das Dashboard</a> - Prüfe ob es jetzt funktioniert</p>";
echo "<p>2. <a href='debug-google-calendar-fixed.php'>Prüfe die Logs</a></p>";

echo "<hr>";
echo "<p><small>Zeitstempel: " . date('Y-m-d H:i:s') . "</small></p>";
?>
