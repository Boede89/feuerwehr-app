<?php
/**
 * Test: Google Calendar Event erstellen
 */

require_once 'config/database.php';
require_once 'includes/functions.php';

header('Content-Type: text/plain');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo "Nur POST-Requests erlaubt\n";
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$reservation_id = $input['reservation_id'] ?? '';

if (empty($reservation_id)) {
    echo "Reservation ID fehlt\n";
    exit;
}

echo "Erstelle Google Calendar Event für Reservation ID: $reservation_id\n\n";

try {
    // Lade Reservierungsdaten
    $stmt = $db->prepare("
        SELECT r.*, v.name as vehicle_name 
        FROM reservations r 
        JOIN vehicles v ON r.vehicle_id = v.id 
        WHERE r.id = ?
    ");
    $stmt->execute([$reservation_id]);
    $reservation = $stmt->fetch();
    
    if (!$reservation) {
        echo "❌ Reservierung nicht gefunden\n";
        exit;
    }
    
    echo "Reservierungsdaten:\n";
    echo "- Fahrzeug: " . $reservation['vehicle_name'] . "\n";
    echo "- Grund: " . $reservation['reason'] . "\n";
    echo "- Start: " . $reservation['start_datetime'] . "\n";
    echo "- Ende: " . $reservation['end_datetime'] . "\n";
    echo "- Ort: " . $reservation['location'] . "\n\n";
    
    // Erstelle Google Calendar Event
    $event_id = create_google_calendar_event(
        $reservation['vehicle_name'],
        $reservation['reason'],
        $reservation['start_datetime'],
        $reservation['end_datetime'],
        $reservation_id,
        $reservation['location']
    );
    
    if ($event_id) {
        echo "✅ Google Calendar Event erfolgreich erstellt\n";
        echo "Event ID: $event_id\n";
    } else {
        echo "❌ Fehler beim Erstellen des Google Calendar Events\n";
    }
    
} catch (Exception $e) {
    echo "❌ Exception: " . $e->getMessage() . "\n";
}
?>
