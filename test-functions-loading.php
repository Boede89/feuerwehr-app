<?php
/**
 * Test: Functions Loading - Prüfe ob alle Funktionen korrekt geladen werden
 */

echo "<h1>Test: Functions Loading - Prüfe ob alle Funktionen korrekt geladen werden</h1>";

echo "<h2>1. Lade includes/functions.php</h2>";

// Lade functions.php
require_once 'includes/functions.php';

echo "<p>✅ includes/functions.php geladen</p>";

echo "<h2>2. Prüfe Funktionen</h2>";

$functions_to_check = [
    'create_google_calendar_event',
    'check_calendar_conflicts',
    'sanitize_input',
    'format_datetime',
    'generate_csrf_token',
    'validate_csrf_token'
];

foreach ($functions_to_check as $function) {
    if (function_exists($function)) {
        echo "<p style='color: green;'>✅ $function Funktion verfügbar</p>";
    } else {
        echo "<p style='color: red;'>❌ $function Funktion NICHT verfügbar</p>";
    }
}

echo "<h2>3. Prüfe Google Calendar Klassen</h2>";

// Lade Google Calendar Klassen
if (file_exists('includes/google_calendar_service_account.php')) {
    require_once 'includes/google_calendar_service_account.php';
    echo "<p>✅ includes/google_calendar_service_account.php geladen</p>";
} else {
    echo "<p style='color: red;'>❌ includes/google_calendar_service_account.php nicht gefunden</p>";
}

if (file_exists('includes/google_calendar.php')) {
    require_once 'includes/google_calendar.php';
    echo "<p>✅ includes/google_calendar.php geladen</p>";
} else {
    echo "<p style='color: red;'>❌ includes/google_calendar.php nicht gefunden</p>";
}

// Prüfe Klassen
if (class_exists('GoogleCalendarServiceAccount')) {
    echo "<p style='color: green;'>✅ GoogleCalendarServiceAccount Klasse verfügbar</p>";
} else {
    echo "<p style='color: red;'>❌ GoogleCalendarServiceAccount Klasse NICHT verfügbar</p>";
}

if (class_exists('GoogleCalendar')) {
    echo "<p style='color: green;'>✅ GoogleCalendar Klasse verfügbar</p>";
} else {
    echo "<p style='color: red;'>❌ GoogleCalendar Klasse NICHT verfügbar</p>";
}

echo "<h2>4. Teste create_google_calendar_event direkt</h2>";

if (function_exists('create_google_calendar_event')) {
    echo "<p>🔍 Teste create_google_calendar_event direkt...</p>";
    
    // Schreibe Test-Log
    error_log('TEST FUNCTIONS LOADING: Starte create_google_calendar_event Test');
    
    $test_result = create_google_calendar_event(
        'Test Fahrzeug',
        'Test Grund',
        '2025-10-05 10:00:00',
        '2025-10-05 12:00:00',
        999999,
        'Test Ort'
    );
    
    error_log('TEST FUNCTIONS LOADING: create_google_calendar_event Rückgabe: ' . ($test_result ? $test_result : 'false'));
    
    if ($test_result) {
        echo "<p style='color: green;'>✅ create_google_calendar_event erfolgreich - Event ID: $test_result</p>";
    } else {
        echo "<p style='color: red;'>❌ create_google_calendar_event fehlgeschlagen - Funktion gab false zurück</p>";
    }
} else {
    echo "<p style='color: red;'>❌ create_google_calendar_event Funktion nicht verfügbar</p>";
}

echo "<h2>5. Prüfe error_log Einstellungen</h2>";

echo "<p>error_log: " . ini_get('error_log') . "</p>";
echo "<p>log_errors: " . (ini_get('log_errors') ? 'ON' : 'OFF') . "</p>";
echo "<p>display_errors: " . (ini_get('display_errors') ? 'ON' : 'OFF') . "</p>";
echo "<p>error_reporting: " . ini_get('error_reporting') . "</p>";

echo "<h2>6. Nächste Schritte</h2>";
echo "<p>1. <a href='test-google-calendar-direct.php'>Teste Google Calendar direkt</a></p>";
echo "<p>2. <a href='debug-google-calendar-live.php'>Prüfe die Logs</a></p>";
echo "<p>3. <a href='admin/dashboard.php'>Teste das Dashboard</a></p>";

echo "<hr>";
echo "<p><small>Zeitstempel: " . date('Y-m-d H:i:s') . "</small></p>";
?>
