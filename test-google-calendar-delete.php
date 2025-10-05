<?php
/**
 * Test: Google Calendar Lösch-Funktion
 */

require_once 'config/database.php';
require_once 'includes/functions.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Nur POST-Requests erlaubt']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$event_id = $input['event_id'] ?? '';

if (empty($event_id)) {
    echo json_encode(['success' => false, 'error' => 'Event ID fehlt']);
    exit;
}

echo "Teste Google Calendar Lösch-Funktion für Event ID: $event_id\n\n";

// 1. Prüfe Google Calendar Service
echo "1. Prüfe Google Calendar Service:\n";
if (class_exists('GoogleCalendarServiceAccount')) {
    echo "✅ GoogleCalendarServiceAccount Klasse verfügbar\n";
} else {
    echo "❌ GoogleCalendarServiceAccount Klasse NICHT verfügbar\n";
    echo json_encode(['success' => false, 'error' => 'GoogleCalendarServiceAccount Klasse nicht verfügbar']);
    exit;
}

// 2. Teste delete_google_calendar_event Funktion
echo "\n2. Teste delete_google_calendar_event Funktion:\n";
if (function_exists('delete_google_calendar_event')) {
    echo "✅ delete_google_calendar_event Funktion verfügbar\n";
    
    try {
        $result = delete_google_calendar_event($event_id);
        echo "Ergebnis: " . ($result ? 'Erfolgreich' : 'Fehlgeschlagen') . "\n";
        
        if ($result) {
            echo json_encode(['success' => true, 'message' => 'Event erfolgreich gelöscht']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Event konnte nicht gelöscht werden']);
        }
    } catch (Exception $e) {
        echo "❌ Exception: " . $e->getMessage() . "\n";
        echo json_encode(['success' => false, 'error' => 'Exception: ' . $e->getMessage()]);
    }
} else {
    echo "❌ delete_google_calendar_event Funktion NICHT verfügbar\n";
    echo json_encode(['success' => false, 'error' => 'delete_google_calendar_event Funktion nicht verfügbar']);
}
?>
