<?php
/**
 * Test: Reservierung löschen (mit Google Calendar)
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
$google_event_id = $input['google_event_id'] ?? '';

if (empty($reservation_id)) {
    echo "Reservation ID fehlt\n";
    exit;
}

echo "Teste Löschen von Reservation ID: $reservation_id\n";
echo "Google Event ID: $google_event_id\n\n";

try {
    // 1. Prüfe Reservierung
    $stmt = $db->prepare("SELECT * FROM reservations WHERE id = ?");
    $stmt->execute([$reservation_id]);
    $reservation = $stmt->fetch();
    
    if (!$reservation) {
        echo "❌ Reservierung nicht gefunden\n";
        exit;
    }
    
    echo "Reservierung gefunden:\n";
    echo "- Status: " . $reservation['status'] . "\n";
    echo "- Antragsteller: " . $reservation['requester_name'] . "\n\n";
    
    // 2. Prüfe Google Calendar Event
    if (!empty($google_event_id)) {
        echo "Teste Google Calendar Event löschen...\n";
        $google_deleted = delete_google_calendar_event($google_event_id);
        
        if ($google_deleted) {
            echo "✅ Google Calendar Event erfolgreich gelöscht\n";
        } else {
            echo "❌ Fehler beim Löschen des Google Calendar Events\n";
        }
    } else {
        echo "⚠️ Keine Google Event ID vorhanden\n";
    }
    
    // 3. Lösche aus lokaler Datenbank
    echo "\nLösche aus lokaler Datenbank...\n";
    
    // Lösche calendar_events Eintrag
    $stmt = $db->prepare("DELETE FROM calendar_events WHERE reservation_id = ?");
    $stmt->execute([$reservation_id]);
    echo "✅ calendar_events Eintrag gelöscht\n";
    
    // Lösche Reservierung
    $stmt = $db->prepare("DELETE FROM reservations WHERE id = ?");
    $stmt->execute([$reservation_id]);
    echo "✅ Reservierung gelöscht\n";
    
    echo "\n✅ Lösch-Vorgang erfolgreich abgeschlossen\n";
    
} catch (Exception $e) {
    echo "❌ Exception: " . $e->getMessage() . "\n";
}
?>
