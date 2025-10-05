<?php
/**
 * Test: Google Calendar Integration im Dashboard
 */

require_once 'config/database.php';
require_once 'includes/functions.php';
require_once 'includes/google_calendar_service_account.php';
require_once 'includes/google_calendar.php';

echo "<h1>🧪 Google Calendar Integration Test</h1>";

// 1. Prüfe Google Calendar Einstellungen
echo "<h2>1. Google Calendar Einstellungen prüfen</h2>";

try {
    $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'google_calendar_%'");
    $stmt->execute();
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Einstellung</th><th>Wert</th></tr>";
    foreach ($settings as $key => $value) {
        if ($key === 'google_calendar_service_account_json') {
            echo "<tr><td>$key</td><td>" . (empty($value) ? 'Nicht gesetzt' : 'Gesetzt (' . strlen($value) . ' Zeichen)') . "</td></tr>";
        } else {
            echo "<tr><td>$key</td><td>" . htmlspecialchars($value) . "</td></tr>";
        }
    }
    echo "</table>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim Laden der Einstellungen: " . $e->getMessage() . "</p>";
}

// 2. Prüfe Funktionen
echo "<h2>2. Funktionen prüfen</h2>";

if (function_exists('create_google_calendar_event')) {
    echo "<p style='color: green;'>✅ create_google_calendar_event Funktion verfügbar</p>";
} else {
    echo "<p style='color: red;'>❌ create_google_calendar_event Funktion NICHT verfügbar</p>";
}

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

// 3. Teste mit einer echten Reservierung
echo "<h2>3. Teste mit echter Reservierung</h2>";

try {
    $stmt = $db->prepare("SELECT r.*, v.name as vehicle_name FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id WHERE r.status = 'pending' ORDER BY r.created_at DESC LIMIT 1");
    $stmt->execute();
    $reservation = $stmt->fetch();
    
    if ($reservation) {
        echo "<p style='color: green;'>✅ Test-Reservierung gefunden:</p>";
        echo "<ul>";
        echo "<li><strong>ID:</strong> " . $reservation['id'] . "</li>";
        echo "<li><strong>Fahrzeug:</strong> " . htmlspecialchars($reservation['vehicle_name']) . "</li>";
        echo "<li><strong>Grund:</strong> " . htmlspecialchars($reservation['reason']) . "</li>";
        echo "<li><strong>Start:</strong> " . $reservation['start_datetime'] . "</li>";
        echo "<li><strong>Ende:</strong> " . $reservation['end_datetime'] . "</li>";
        echo "<li><strong>Ort:</strong> " . htmlspecialchars($reservation['location'] ?? 'Nicht angegeben') . "</li>";
        echo "</ul>";
        
        // Teste Google Calendar Event Erstellung
        echo "<h3>3.1 Google Calendar Event Test</h3>";
        
        if (function_exists('create_google_calendar_event')) {
            echo "<p>Teste Google Calendar Event Erstellung...</p>";
            
            $event_id = create_google_calendar_event(
                $reservation['vehicle_name'],
                $reservation['reason'],
                $reservation['start_datetime'],
                $reservation['end_datetime'],
                $reservation['id'],
                $reservation['location'] ?? null
            );
            
            if ($event_id) {
                echo "<p style='color: green;'>✅ Google Calendar Event erfolgreich erstellt!</p>";
                echo "<p><strong>Event ID:</strong> " . htmlspecialchars($event_id) . "</p>";
                echo "<p><strong>Event Titel:</strong> " . htmlspecialchars($reservation['vehicle_name'] . ' - ' . $reservation['reason']) . "</p>";
            } else {
                echo "<p style='color: red;'>❌ Google Calendar Event konnte nicht erstellt werden</p>";
            }
        } else {
            echo "<p style='color: red;'>❌ create_google_calendar_event Funktion nicht verfügbar</p>";
        }
        
    } else {
        echo "<p style='color: orange;'>⚠️ Keine ausstehenden Reservierungen gefunden</p>";
        echo "<p><a href='create-test-reservation.php'>Test-Reservierung erstellen</a></p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler: " . $e->getMessage() . "</p>";
}

echo "<hr>";
echo "<p><a href='admin/dashboard.php'>← Zurück zum Dashboard</a></p>";
echo "<p><small>Zeitstempel: " . date('Y-m-d H:i:s') . "</small></p>";
?>
