<?php
/**
 * Cleanup-Script für abgelaufene Reservierungen
 * 
 * Dieses Script löscht automatisch Reservierungen, deren end_datetime in der Vergangenheit liegt.
 * Es wird sowohl die Datenbank als auch die Google Calendar Events bereinigt.
 * 
 * Verwendung:
 * - Manuell: php cleanup-expired-reservations.php
 * - Cron-Job: 0 2 * * * php /path/to/cleanup-expired-reservations.php
 */

// Fehlerbehandlung aktivieren
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Session starten (falls nötig)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Datenbankverbindung
require_once 'includes/db.php';
require_once 'includes/functions.php';

// Google Calendar Funktionen laden
if (file_exists('includes/google_calendar_functions.php')) {
    require_once 'includes/google_calendar_functions.php';
}

echo "🧹 Cleanup abgelaufener Reservierungen gestartet...\n";
echo "⏰ Zeitpunkt: " . date('Y-m-d H:i:s') . "\n\n";

try {
    // 1. Abgelaufene Reservierungen finden
    echo "1. Suche abgelaufene Reservierungen...\n";
    
    $stmt = $db->prepare("
        SELECT r.id, r.requester_name, r.requester_email, r.reason, r.start_datetime, r.end_datetime,
               v.name as vehicle_name, ce.google_event_id
        FROM reservations r 
        JOIN vehicles v ON r.vehicle_id = v.id 
        LEFT JOIN calendar_events ce ON r.id = ce.reservation_id
        WHERE r.end_datetime < NOW() 
        AND r.status IN ('approved', 'pending')
        ORDER BY r.end_datetime ASC
    ");
    $stmt->execute();
    $expired_reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $count = count($expired_reservations);
    echo "   Gefunden: {$count} abgelaufene Reservierungen\n\n";
    
    if ($count === 0) {
        echo "✅ Keine abgelaufenen Reservierungen gefunden. Cleanup beendet.\n";
        exit(0);
    }
    
    // 2. Reservierungen verarbeiten
    echo "2. Verarbeite abgelaufene Reservierungen...\n";
    
    $deleted_count = 0;
    $google_deleted_count = 0;
    $errors = [];
    
    foreach ($expired_reservations as $reservation) {
        echo "   Verarbeite: {$reservation['vehicle_name']} - {$reservation['reason']} (bis: {$reservation['end_datetime']})\n";
        
        try {
            // 2.1 Google Calendar Event löschen (falls vorhanden)
            if (!empty($reservation['google_event_id'])) {
                echo "     - Lösche Google Calendar Event: {$reservation['google_event_id']}\n";
                
                // Prüfen ob noch andere Reservierungen dieses Event nutzen
                $stmt = $db->prepare("SELECT COUNT(*) FROM calendar_events WHERE google_event_id = ?");
                $stmt->execute([$reservation['google_event_id']]);
                $remaining_links = (int)$stmt->fetchColumn();
                
                if ($remaining_links === 1) {
                    // Nur löschen, wenn keine weitere Reservierung dieses Event nutzt
                    if (function_exists('delete_google_calendar_event')) {
                        $google_deleted = delete_google_calendar_event($reservation['google_event_id']);
                        if ($google_deleted) {
                            echo "     ✅ Google Calendar Event gelöscht\n";
                            $google_deleted_count++;
                        } else {
                            echo "     ⚠️ Google Calendar Event konnte nicht gelöscht werden\n";
                            $errors[] = "Google Calendar Event {$reservation['google_event_id']} konnte nicht gelöscht werden";
                        }
                    } else {
                        echo "     ⚠️ Google Calendar Funktion nicht verfügbar\n";
                        $errors[] = "Google Calendar Funktion nicht verfügbar für Event {$reservation['google_event_id']}";
                    }
                } else {
                    echo "     ℹ️ Google Event wird nicht gelöscht (noch {$remaining_links} weitere Verknüpfungen)\n";
                }
            } else {
                echo "     ℹ️ Kein Google Calendar Event vorhanden\n";
            }
            
            // 2.2 Calendar Events Verknüpfung löschen
            $stmt = $db->prepare("DELETE FROM calendar_events WHERE reservation_id = ?");
            $stmt->execute([$reservation['id']]);
            echo "     ✅ Calendar Events Verknüpfung gelöscht\n";
            
            // 2.3 Reservierung löschen
            $stmt = $db->prepare("DELETE FROM reservations WHERE id = ?");
            $stmt->execute([$reservation['id']]);
            echo "     ✅ Reservierung gelöscht\n";
            
            $deleted_count++;
            
        } catch (Exception $e) {
            $error_msg = "Fehler bei Reservierung ID {$reservation['id']}: " . $e->getMessage();
            echo "     ❌ {$error_msg}\n";
            $errors[] = $error_msg;
        }
        
        echo "\n";
    }
    
    // 3. Zusammenfassung
    echo "3. Cleanup abgeschlossen!\n";
    echo "   ✅ Reservierungen gelöscht: {$deleted_count}\n";
    echo "   ✅ Google Calendar Events gelöscht: {$google_deleted_count}\n";
    
    if (!empty($errors)) {
        echo "   ⚠️ Fehler aufgetreten: " . count($errors) . "\n";
        foreach ($errors as $error) {
            echo "     - {$error}\n";
        }
    }
    
    echo "\n🎉 Cleanup erfolgreich abgeschlossen!\n";
    
} catch (Exception $e) {
    echo "❌ Kritischer Fehler beim Cleanup: " . $e->getMessage() . "\n";
    echo "Stack Trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
?>