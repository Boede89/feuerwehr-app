<?php
require_once __DIR__ . '/includes/debug-auth.php';
/**
 * Debug: Kalender-Konfliktprüfung testen
 */

require_once 'config/database.php';
require_once 'includes/functions.php';

echo "🔍 Debug: Kalender-Konfliktprüfung testen\n";
echo "Zeitstempel: " . date('d.m.Y H:i:s') . "\n\n";

try {
    // 1. Prüfe Funktion Verfügbarkeit
    echo "1. Funktion Verfügbarkeit prüfen:\n";
    if (function_exists('check_calendar_conflicts')) {
        echo "✅ check_calendar_conflicts Funktion ist verfügbar\n";
    } else {
        echo "❌ check_calendar_conflicts Funktion ist NICHT verfügbar\n";
    }
    
    if (function_exists('create_google_calendar_event')) {
        echo "✅ create_google_calendar_event Funktion ist verfügbar\n";
    } else {
        echo "❌ create_google_calendar_event Funktion ist NICHT verfügbar\n";
    }
    
    // 2. Prüfe Google Calendar Klassen
    echo "\n2. Google Calendar Klassen prüfen:\n";
    if (class_exists('GoogleCalendarServiceAccount')) {
        echo "✅ GoogleCalendarServiceAccount Klasse ist verfügbar\n";
    } else {
        echo "❌ GoogleCalendarServiceAccount Klasse ist NICHT verfügbar\n";
    }
    
    if (class_exists('GoogleCalendar')) {
        echo "✅ GoogleCalendar Klasse ist verfügbar\n";
    } else {
        echo "❌ GoogleCalendar Klasse ist NICHT verfügbar\n";
    }
    
    // 3. Prüfe Google Calendar Einstellungen
    echo "\n3. Google Calendar Einstellungen prüfen:\n";
    $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'google_calendar_%'");
    $stmt->execute();
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    foreach ($settings as $key => $value) {
        if ($key === 'google_calendar_service_account_json') {
            echo "- $key: " . (empty($value) ? 'Nicht konfiguriert' : 'Konfiguriert (' . strlen($value) . ' Zeichen)') . "\n";
        } else {
            echo "- $key: " . (empty($value) ? 'Nicht konfiguriert' : $value) . "\n";
        }
    }
    
    // 4. Teste Kalender-Konfliktprüfung
    echo "\n4. Teste Kalender-Konfliktprüfung:\n";
    
    $test_vehicle = 'MTF'; // Test-Fahrzeug
    $test_start = date('Y-m-d H:i:s', strtotime('+1 day'));
    $test_end = date('Y-m-d H:i:s', strtotime('+1 day +2 hours'));
    
    echo "Test-Parameter:\n";
    echo "- Fahrzeug: $test_vehicle\n";
    echo "- Start: $test_start\n";
    echo "- Ende: $test_end\n";
    
    $conflicts = check_calendar_conflicts($test_vehicle, $test_start, $test_end);
    
    if (is_array($conflicts)) {
        echo "✅ Konfliktprüfung erfolgreich ausgeführt\n";
        echo "Gefundene Konflikte: " . count($conflicts) . "\n";
        
        if (!empty($conflicts)) {
            foreach ($conflicts as $i => $conflict) {
                echo "Konflikt " . ($i + 1) . ":\n";
                echo "  - Titel: " . $conflict['title'] . "\n";
                echo "  - Start: " . $conflict['start'] . "\n";
                echo "  - Ende: " . $conflict['end'] . "\n";
            }
        } else {
            echo "ℹ️ Keine Konflikte gefunden\n";
        }
    } else {
        echo "❌ Konfliktprüfung fehlgeschlagen\n";
    }
    
    // 5. Prüfe Datenbank-Schema
    echo "\n5. Datenbank-Schema prüfen:\n";
    $stmt = $db->query("DESCRIBE reservations");
    $columns = $stmt->fetchAll();
    
    $has_calendar_conflicts = false;
    foreach ($columns as $column) {
        if ($column['Field'] === 'calendar_conflicts') {
            $has_calendar_conflicts = true;
            echo "✅ calendar_conflicts Feld existiert: {$column['Type']}\n";
        }
    }
    
    if (!$has_calendar_conflicts) {
        echo "❌ calendar_conflicts Feld fehlt in der Datenbank!\n";
        echo "Führe add-calendar-conflict-field.php aus.\n";
    }
    
    // 6. Teste mit echter Reservierung
    echo "\n6. Teste mit echter Reservierung:\n";
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
        echo "Teste mit Reservierung ID: {$reservation['id']}\n";
        echo "Fahrzeug: {$reservation['vehicle_name']}\n";
        echo "Zeitraum: {$reservation['start_datetime']} - {$reservation['end_datetime']}\n";
        
        $conflicts = check_calendar_conflicts(
            $reservation['vehicle_name'],
            $reservation['start_datetime'],
            $reservation['end_datetime']
        );
        
        echo "Gefundene Konflikte: " . count($conflicts) . "\n";
        
        if (!empty($conflicts)) {
            echo "Konflikte:\n";
            foreach ($conflicts as $i => $conflict) {
                echo "  " . ($i + 1) . ". " . $conflict['title'] . "\n";
            }
        }
    } else {
        echo "ℹ️ Keine ausstehenden Reservierungen gefunden\n";
    }
    
} catch (Exception $e) {
    echo "❌ Fehler: " . $e->getMessage() . "\n";
    echo "Stack Trace: " . $e->getTraceAsString() . "\n";
}

echo "\nDebug abgeschlossen um: " . date('d.m.Y H:i:s') . "\n";
?>
