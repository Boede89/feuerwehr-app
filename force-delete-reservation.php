<?php
/**
 * Force Delete: Reservierung manuell löschen
 */

require_once 'config/database.php';

echo "<h1>🗑️ Force Delete: Reservierung manuell löschen</h1>";

$reservation_id = 159;
$google_event_id = '1qcoeid24vdq4lgm0uf9dp231k';

echo "<h2>1. Reservierung ID $reservation_id manuell löschen</h2>";

try {
    // 1. Lösche Calendar Event aus Datenbank
    echo "<p>Lösche Calendar Event aus Datenbank...</p>";
    $stmt = $db->prepare("DELETE FROM calendar_events WHERE reservation_id = ? OR google_event_id = ?");
    $stmt->execute([$reservation_id, $google_event_id]);
    $deleted_calendar_events = $stmt->rowCount();
    echo "<p style='color: green;'>✅ $deleted_calendar_events Calendar Event(s) aus Datenbank gelöscht</p>";
    
    // 2. Lösche Reservierung aus Datenbank
    echo "<p>Lösche Reservierung aus Datenbank...</p>";
    $stmt = $db->prepare("DELETE FROM reservations WHERE id = ?");
    $stmt->execute([$reservation_id]);
    $deleted_reservations = $stmt->rowCount();
    echo "<p style='color: green;'>✅ $deleted_reservations Reservierung(en) aus Datenbank gelöscht</p>";
    
    if ($deleted_reservations > 0) {
        echo "<p style='color: green; font-weight: bold;'>🎉 Reservierung ID $reservation_id erfolgreich aus der Datenbank gelöscht!</p>";
    } else {
        echo "<p style='color: orange;'>⚠️ Reservierung ID $reservation_id war bereits gelöscht</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim Löschen aus Datenbank: " . $e->getMessage() . "</p>";
}

echo "<h2>2. Google Calendar Event manuell löschen</h2>";

echo "<p style='color: orange;'>⚠️ Google Calendar Event muss manuell gelöscht werden:</p>";
echo "<ul>";
echo "<li><strong>Event ID:</strong> $google_event_id</li>";
echo "<li><strong>Kalender:</strong> <a href='https://calendar.google.com/calendar/u/0/r' target='_blank'>Google Calendar öffnen</a></li>";
echo "<li><strong>Anleitung:</strong> Suchen Sie nach dem Event und löschen Sie es manuell</li>";
echo "</ul>";

echo "<h2>3. Verifikation</h2>";

try {
    // Prüfe ob Reservierung noch existiert
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM reservations WHERE id = ?");
    $stmt->execute([$reservation_id]);
    $reservation_exists = $stmt->fetch()['count'] > 0;
    
    if ($reservation_exists) {
        echo "<p style='color: red;'>❌ Reservierung ID $reservation_id existiert noch in der Datenbank</p>";
    } else {
        echo "<p style='color: green;'>✅ Reservierung ID $reservation_id wurde aus der Datenbank gelöscht</p>";
    }
    
    // Prüfe Calendar Events
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM calendar_events WHERE google_event_id = ?");
    $stmt->execute([$google_event_id]);
    $calendar_event_exists = $stmt->fetch()['count'] > 0;
    
    if ($calendar_event_exists) {
        echo "<p style='color: red;'>❌ Calendar Event $google_event_id existiert noch in der Datenbank</p>";
    } else {
        echo "<p style='color: green;'>✅ Calendar Event $google_event_id wurde aus der Datenbank gelöscht</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler bei der Verifikation: " . $e->getMessage() . "</p>";
}

echo "<h2>4. Nächste Schritte</h2>";

echo "<div style='background: #fff3cd; padding: 15px; border: 1px solid #ffeaa7; border-radius: 5px;'>";
echo "<h3 style='color: #856404; margin-top: 0;'>⚠️ Google Calendar Event manuell löschen</h3>";
echo "<ol style='color: #856404;'>";
echo "<li>Öffnen Sie <a href='https://calendar.google.com/calendar/u/0/r' target='_blank'>Google Calendar</a></li>";
echo "<li>Suchen Sie nach dem Event mit der ID: <code>$google_event_id</code></li>";
echo "<li>Klicken Sie auf das Event</li>";
echo "<li>Klicken Sie auf 'Löschen' oder 'Delete'</li>";
echo "<li>Bestätigen Sie das Löschen</li>";
echo "</ol>";
echo "</div>";

echo "<h2>5. Google Calendar API Problem beheben</h2>";

echo "<p>Das Google Calendar API Problem kann verschiedene Ursachen haben:</p>";
echo "<ul>";
echo "<li><strong>Netzwerk-Timeout:</strong> Die API-Antwort dauert zu lange</li>";
echo "<li><strong>API-Limits:</strong> Zu viele Anfragen in kurzer Zeit</li>";
echo "<li><strong>Authentifizierung:</strong> Service Account Berechtigung abgelaufen</li>";
echo "<li><strong>Kalender-Berechtigung:</strong> Service Account hat keinen Zugriff</li>";
echo "</ul>";

echo "<p><strong>Lösungsvorschläge:</strong></p>";
echo "<ol>";
echo "<li>Warten Sie 5-10 Minuten und versuchen Sie es erneut</li>";
echo "<li>Prüfen Sie die Google Calendar Berechtigungen</li>";
echo "<li>Testen Sie die API mit einem neuen Service Account</li>";
echo "<li>Verwenden Sie manuelles Löschen als Workaround</li>";
echo "</ol>";

echo "<hr>";
echo "<p><a href='admin/reservations.php'>→ Zur Reservierungen-Übersicht</a></p>";
echo "<p><a href='fix-google-calendar-simple.php'>→ Zurück zum Fix-Skript</a></p>";
echo "<p><small>Force Delete abgeschlossen: " . date('Y-m-d H:i:s') . "</small></p>";
?>
