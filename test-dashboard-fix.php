<?php
/**
 * Test: Dashboard Fix - Teste ob das Dashboard jetzt funktioniert
 */

echo "<h1>Test: Dashboard Fix - Teste ob das Dashboard jetzt funktioniert</h1>";

// Lade Funktionen
require_once 'config/database.php';
require_once 'includes/functions.php';

// Google Calendar Klassen explizit laden
require_once 'includes/google_calendar_service_account.php';
require_once 'includes/google_calendar.php';

echo "<h2>1. Prüfe Dashboard-Umgebung nach Fix</h2>";

// Teste ob create_google_calendar_event verfügbar ist
if (function_exists('create_google_calendar_event')) {
    echo "<p style='color: green;'>✅ create_google_calendar_event Funktion ist verfügbar</p>";
} else {
    echo "<p style='color: red;'>❌ create_google_calendar_event Funktion ist NICHT verfügbar</p>";
}

// Teste ob Google Calendar Klassen verfügbar sind
if (class_exists('GoogleCalendarServiceAccount')) {
    echo "<p style='color: green;'>✅ GoogleCalendarServiceAccount Klasse ist verfügbar</p>";
} else {
    echo "<p style='color: red;'>❌ GoogleCalendarServiceAccount Klasse ist NICHT verfügbar</p>";
}

if (class_exists('GoogleCalendar')) {
    echo "<p style='color: green;'>✅ GoogleCalendar Klasse ist verfügbar</p>";
} else {
    echo "<p style='color: red;'>❌ GoogleCalendar Klasse ist NICHT verfügbar</p>";
}

echo "<h2>2. Teste Google Calendar Event Erstellung</h2>";

try {
    // Lade echte Reservierung
    $stmt = $db->prepare("SELECT r.*, v.name as vehicle_name FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id WHERE r.status = 'pending' LIMIT 1");
    $stmt->execute();
    $reservation = $stmt->fetch();
    
    if ($reservation) {
        echo "<p>✅ Echte Reservierung gefunden: ID " . $reservation['id'] . "</p>";
        
        // Teste create_google_calendar_event
        echo "<p>🔍 Teste create_google_calendar_event...</p>";
        
        $test_result = create_google_calendar_event(
            $reservation['vehicle_name'],
            $reservation['reason'],
            $reservation['start_datetime'],
            $reservation['end_datetime'],
            $reservation['id'],
            $reservation['location'] ?? null
        );
        
        if ($test_result) {
            echo "<p style='color: green;'>✅ Google Calendar Event erfolgreich erstellt - Event ID: $test_result</p>";
        } else {
            echo "<p style='color: red;'>❌ Google Calendar Event konnte nicht erstellt werden - Funktion gab false zurück</p>";
        }
        
    } else {
        echo "<p>❌ Keine ausstehende Reservierung gefunden</p>";
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler: " . $e->getMessage() . "</p>";
}

echo "<h2>3. Nächste Schritte</h2>";
echo "<p>1. <a href='admin/dashboard.php'>Teste das Dashboard</a> - sollte jetzt funktionieren!</p>";
echo "<p>2. <a href='debug-google-calendar-fixed.php'>Prüfe die Logs</a></p>";

echo "<hr>";
echo "<p><small>Zeitstempel: " . date('Y-m-d H:i:s') . "</small></p>";
?>
