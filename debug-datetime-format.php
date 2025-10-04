<?php
/**
 * Debug für Datum/Zeit Format Problem
 */

session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';

echo "<h1>Debug: Datum/Zeit Format Problem</h1>";
echo "<p>Zeitstempel: " . date('d.m.Y H:i:s') . "</p>";

// 1. Prüfe Session
echo "<h2>1. Session prüfen</h2>";
if (isset($_SESSION['user_id'])) {
    echo "<p style='color: green;'>✅ Eingeloggt als User ID: " . $_SESSION['user_id'] . "</p>";
} else {
    echo "<p style='color: red;'>❌ Nicht eingeloggt</p>";
    exit;
}

// 2. Prüfe ausstehende Reservierungen
echo "<h2>2. Ausstehende Reservierungen prüfen</h2>";

try {
    $stmt = $db->prepare("SELECT r.*, v.name as vehicle_name FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id WHERE r.status = 'pending' ORDER BY r.created_at DESC LIMIT 3");
    $stmt->execute();
    $pending_reservations = $stmt->fetchAll();
    
    if (!empty($pending_reservations)) {
        echo "<p style='color: green;'>✅ " . count($pending_reservations) . " ausstehende Reservierungen gefunden</p>";
        
        foreach ($pending_reservations as $reservation) {
            echo "<div style='border: 1px solid #ddd; padding: 10px; margin: 5px 0; border-radius: 4px;'>";
            echo "<p><strong>ID:</strong> " . htmlspecialchars($reservation['id']) . "</p>";
            echo "<p><strong>Fahrzeug:</strong> " . htmlspecialchars($reservation['vehicle_name']) . "</p>";
            echo "<p><strong>Grund:</strong> " . htmlspecialchars($reservation['reason']) . "</p>";
            echo "<p><strong>Start (DB):</strong> " . htmlspecialchars($reservation['start_datetime']) . "</p>";
            echo "<p><strong>Ende (DB):</strong> " . htmlspecialchars($reservation['end_datetime']) . "</p>";
            echo "<p><strong>Status:</strong> " . htmlspecialchars($reservation['status']) . "</p>";
            
            // Teste Datum/Zeit Format
            echo "<h4>Datum/Zeit Format Test:</h4>";
            
            $start_datetime = $reservation['start_datetime'];
            $end_datetime = $reservation['end_datetime'];
            
            echo "<p><strong>Start DateTime (Original):</strong> " . htmlspecialchars($start_datetime) . "</p>";
            echo "<p><strong>End DateTime (Original):</strong> " . htmlspecialchars($end_datetime) . "</p>";
            
            // Teste verschiedene Formate
            $start_timestamp = strtotime($start_datetime);
            $end_timestamp = strtotime($end_datetime);
            
            echo "<p><strong>Start Timestamp:</strong> " . $start_timestamp . "</p>";
            echo "<p><strong>End Timestamp:</strong> " . $end_timestamp . "</p>";
            
            if ($start_timestamp !== false) {
                echo "<p style='color: green;'>✅ Start DateTime ist gültig</p>";
                echo "<p><strong>Start DateTime (formatiert):</strong> " . date('Y-m-d H:i:s', $start_timestamp) . "</p>";
            } else {
                echo "<p style='color: red;'>❌ Start DateTime ist ungültig</p>";
            }
            
            if ($end_timestamp !== false) {
                echo "<p style='color: green;'>✅ End DateTime ist gültig</p>";
                echo "<p><strong>End DateTime (formatiert):</strong> " . date('Y-m-d H:i:s', $end_timestamp) . "</p>";
            } else {
                echo "<p style='color: red;'>❌ End DateTime ist ungültig</p>";
            }
            
            // Teste Google Calendar Event Erstellung mit echten Daten
            echo "<h4>Google Calendar Event Test mit echten Daten:</h4>";
            
            if (function_exists('create_google_calendar_event')) {
                echo "<p style='color: green;'>✅ create_google_calendar_event Funktion ist verfügbar</p>";
                
                // Teste mit echten Daten
                $event_id = create_google_calendar_event(
                    $reservation['vehicle_name'],
                    $reservation['reason'],
                    $reservation['start_datetime'],
                    $reservation['end_datetime'],
                    $reservation['id']
                );
                
                if ($event_id) {
                    echo "<p style='color: green;'>✅ Google Calendar Event erfolgreich erstellt! Event ID: " . htmlspecialchars($event_id) . "</p>";
                    
                    // Prüfe ob Event in der Datenbank gespeichert wurde
                    $stmt = $db->prepare("SELECT * FROM calendar_events WHERE reservation_id = ?");
                    $stmt->execute([$reservation['id']]);
                    $event_record = $stmt->fetch();
                    
                    if ($event_record) {
                        echo "<p style='color: green;'>✅ Event in der Datenbank gespeichert</p>";
                        echo "<ul>";
                        echo "<li><strong>Event ID:</strong> " . htmlspecialchars($event_record['google_event_id']) . "</li>";
                        echo "<li><strong>Titel:</strong> " . htmlspecialchars($event_record['title']) . "</li>";
                        echo "<li><strong>Start:</strong> " . htmlspecialchars($event_record['start_datetime']) . "</li>";
                        echo "<li><strong>Ende:</strong> " . htmlspecialchars($event_record['end_datetime']) . "</li>";
                        echo "</ul>";
                    } else {
                        echo "<p style='color: red;'>❌ Event NICHT in der Datenbank gespeichert</p>";
                    }
                    
                    // Event löschen
                    try {
                        require_once 'includes/google_calendar_service_account.php';
                        
                        $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'google_calendar_%'");
                        $stmt->execute();
                        $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
                        
                        $calendar_id = $settings['google_calendar_id'] ?? 'primary';
                        $service_account_json = $settings['google_calendar_service_account_json'] ?? '';
                        
                        $google_calendar = new GoogleCalendarServiceAccount($service_account_json, $calendar_id, true);
                        $google_calendar->deleteEvent($event_id);
                        echo "<p>✅ Test Event gelöscht</p>";
                    } catch (Exception $e) {
                        echo "<p style='color: orange;'>⚠️ Fehler beim Löschen: " . htmlspecialchars($e->getMessage()) . "</p>";
                    }
                } else {
                    echo "<p style='color: red;'>❌ Google Calendar Event konnte NICHT erstellt werden</p>";
                }
            } else {
                echo "<p style='color: red;'>❌ create_google_calendar_event Funktion ist nicht verfügbar</p>";
            }
            
            echo "</div>";
        }
    } else {
        echo "<p style='color: orange;'>⚠️ Keine ausstehende Reservierung gefunden</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim Laden der Reservierungen: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<hr>";
echo "<p><strong>Debug abgeschlossen um:</strong> " . date('d.m.Y H:i:s') . "</p>";
?>
