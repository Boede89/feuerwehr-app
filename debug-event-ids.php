<?php
/**
 * Debug: Event IDs und Client ID anzeigen
 */

require_once 'config/database.php';

echo "<h1>🔍 Debug: Event IDs und Client ID anzeigen</h1>";

// 1. Client ID aus Service Account JSON
echo "<h2>1. Client ID aus Service Account JSON</h2>";

try {
    $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'google_calendar_service_account_json'");
    $stmt->execute();
    $service_account_json = $stmt->fetchColumn();
    
    if ($service_account_json) {
        $json_data = json_decode($service_account_json, true);
        
        if ($json_data) {
            echo "<p style='color: green;'>✅ Service Account JSON gefunden</p>";
            echo "<p><strong>Client ID:</strong> " . ($json_data['client_id'] ?? 'Nicht gefunden') . "</p>";
            echo "<p><strong>Client Email:</strong> " . ($json_data['client_email'] ?? 'Nicht gefunden') . "</p>";
            echo "<p><strong>Project ID:</strong> " . ($json_data['project_id'] ?? 'Nicht gefunden') . "</p>";
            echo "<p><strong>Private Key ID:</strong> " . ($json_data['private_key_id'] ?? 'Nicht gefunden') . "</p>";
        } else {
            echo "<p style='color: red;'>❌ Service Account JSON ist ungültig</p>";
        }
    } else {
        echo "<p style='color: red;'>❌ Kein Service Account JSON gefunden</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim Laden der Client ID: " . $e->getMessage() . "</p>";
}

// 2. Alle Event IDs aus der Datenbank
echo "<h2>2. Alle Event IDs aus der Datenbank</h2>";

try {
    $stmt = $db->prepare("
        SELECT 
            r.id as reservation_id,
            r.requester_name,
            r.reason,
            v.name as vehicle_name,
            r.status,
            ce.google_event_id,
            ce.created_at as event_created
        FROM reservations r
        LEFT JOIN vehicles v ON r.vehicle_id = v.id
        LEFT JOIN calendar_events ce ON r.id = ce.reservation_id
        ORDER BY r.id DESC
        LIMIT 20
    ");
    $stmt->execute();
    $reservations = $stmt->fetchAll();
    
    if ($reservations) {
        echo "<p style='color: green;'>✅ " . count($reservations) . " Reservierungen gefunden</p>";
        
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr style='background-color: #f0f0f0;'>";
        echo "<th>Reservierung ID</th>";
        echo "<th>Fahrzeug</th>";
        echo "<th>Antragsteller</th>";
        echo "<th>Grund</th>";
        echo "<th>Status</th>";
        echo "<th>Google Event ID</th>";
        echo "<th>Event erstellt</th>";
        echo "<th>Aktionen</th>";
        echo "</tr>";
        
        foreach ($reservations as $reservation) {
            $status_color = $reservation['status'] === 'approved' ? 'green' : ($reservation['status'] === 'pending' ? 'orange' : 'red');
            $event_id_color = $reservation['google_event_id'] ? 'green' : 'red';
            
            echo "<tr>";
            echo "<td>" . $reservation['reservation_id'] . "</td>";
            echo "<td>" . $reservation['vehicle_name'] . "</td>";
            echo "<td>" . $reservation['requester_name'] . "</td>";
            echo "<td>" . $reservation['reason'] . "</td>";
            echo "<td style='color: $status_color;'>" . $reservation['status'] . "</td>";
            echo "<td style='color: $event_id_color;'>" . ($reservation['google_event_id'] ?: 'Keine Event ID') . "</td>";
            echo "<td>" . ($reservation['event_created'] ?: 'Nicht erstellt') . "</td>";
            echo "<td>";
            if ($reservation['google_event_id']) {
                echo "<a href='test-single-event.php?event_id=" . urlencode($reservation['google_event_id']) . "' target='_blank'>Test Event</a>";
            }
            echo "</td>";
            echo "</tr>";
        }
        
        echo "</table>";
        
    } else {
        echo "<p style='color: orange;'>⚠️ Keine Reservierungen gefunden</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim Laden der Event IDs: " . $e->getMessage() . "</p>";
}

// 3. Google Calendar Einstellungen
echo "<h2>3. Google Calendar Einstellungen</h2>";

try {
    $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'google_calendar_%'");
    $stmt->execute();
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr style='background-color: #f0f0f0;'>";
    echo "<th>Einstellung</th>";
    echo "<th>Wert</th>";
    echo "</tr>";
    
    foreach ($settings as $key => $value) {
        $display_value = $value;
        if (strlen($display_value) > 100) {
            $display_value = substr($display_value, 0, 100) . '...';
        }
        
        echo "<tr>";
        echo "<td>" . $key . "</td>";
        echo "<td>" . htmlspecialchars($display_value) . "</td>";
        echo "</tr>";
    }
    
    echo "</table>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim Laden der Einstellungen: " . $e->getMessage() . "</p>";
}

// 4. Test einzelnes Event
echo "<h2>4. Test einzelnes Event</h2>";

if (isset($_GET['event_id'])) {
    $event_id = $_GET['event_id'];
    echo "<h3>Teste Event ID: $event_id</h3>";
    
    try {
        // Lade Google Calendar Service Account
        $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'google_calendar_service_account_json'");
        $stmt->execute();
        $service_account_json = $stmt->fetchColumn();
        
        $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'google_calendar_id'");
        $stmt->execute();
        $calendar_id = $stmt->fetchColumn();
        
        if ($service_account_json && $calendar_id) {
            $calendar_service = new GoogleCalendarServiceAccount($service_account_json, $calendar_id, true);
            
            echo "<h4>4.1 Event Details abrufen</h4>";
            
            try {
                $event = $calendar_service->getEvent($event_id);
                
                if ($event) {
                    echo "<p style='color: green;'>✅ Event gefunden</p>";
                    echo "<p><strong>Event ID:</strong> " . ($event['id'] ?? 'Unbekannt') . "</p>";
                    echo "<p><strong>Status:</strong> " . ($event['status'] ?? 'Unbekannt') . "</p>";
                    echo "<p><strong>Summary:</strong> " . ($event['summary'] ?? 'Unbekannt') . "</p>";
                    echo "<p><strong>Created:</strong> " . ($event['created'] ?? 'Unbekannt') . "</p>";
                    echo "<p><strong>Updated:</strong> " . ($event['updated'] ?? 'Unbekannt') . "</p>";
                    
                    if (isset($event['creator'])) {
                        echo "<p><strong>Creator Email:</strong> " . ($event['creator']['email'] ?? 'Unbekannt') . "</p>";
                    }
                    
                    if (isset($event['organizer'])) {
                        echo "<p><strong>Organizer Email:</strong> " . ($event['organizer']['email'] ?? 'Unbekannt') . "</p>";
                    }
                    
                } else {
                    echo "<p style='color: red;'>❌ Event nicht gefunden</p>";
                }
                
            } catch (Exception $e) {
                echo "<p style='color: red;'>❌ Fehler beim Abrufen des Events: " . $e->getMessage() . "</p>";
            }
            
            echo "<h4>4.2 Event löschen testen</h4>";
            
            $start_time = microtime(true);
            $result = $calendar_service->deleteEvent($event_id);
            $end_time = microtime(true);
            
            $duration = round(($end_time - $start_time) * 1000, 2);
            
            echo "<p><strong>Lösch-Dauer:</strong> {$duration}ms</p>";
            echo "<p><strong>deleteEvent Ergebnis:</strong> " . ($result ? 'TRUE' : 'FALSE') . "</p>";
            
            if ($result) {
                echo "<p style='color: green;'>✅ Event erfolgreich gelöscht!</p>";
                
                // Prüfe Event Status nach dem Löschen
                echo "<h4>4.3 Event Status nach dem Löschen</h4>";
                
                try {
                    $event_after = $calendar_service->getEvent($event_id);
                    
                    if ($event_after) {
                        echo "<p style='color: orange;'>⚠️ Event existiert noch nach dem Löschen</p>";
                        echo "<p><strong>Status:</strong> " . ($event_after['status'] ?? 'Unbekannt') . "</p>";
                        echo "<p><strong>Summary:</strong> " . ($event_after['summary'] ?? 'Unbekannt') . "</p>";
                        
                        if (isset($event_after['status']) && $event_after['status'] === 'cancelled') {
                            echo "<p style='color: blue;'>ℹ️ Event ist cancelled - das ist normal bei Google Calendar</p>";
                        }
                    } else {
                        echo "<p style='color: green; font-weight: bold;'>🎉 Event wurde vollständig gelöscht!</p>";
                    }
                    
                } catch (Exception $e) {
                    if (strpos($e->getMessage(), '404') !== false) {
                        echo "<p style='color: green; font-weight: bold;'>🎉 Event wurde vollständig gelöscht (404 Not Found)!</p>";
                    } else {
                        echo "<p style='color: red;'>❌ Fehler beim Abrufen des Events: " . $e->getMessage() . "</p>";
                    }
                }
                
            } else {
                echo "<p style='color: red;'>❌ Event konnte nicht gelöscht werden</p>";
            }
            
        } else {
            echo "<p style='color: red;'>❌ Google Calendar Einstellungen nicht gefunden</p>";
        }
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Fehler beim Test: " . $e->getMessage() . "</p>";
    }
    
} else {
    echo "<p>Klicken Sie auf 'Test Event' bei einer Reservierung, um ein einzelnes Event zu testen.</p>";
}

echo "<hr>";
echo "<h2>📋 Zusammenfassung</h2>";
echo "<p><strong>Client ID:</strong> Wird aus dem Service Account JSON geladen</p>";
echo "<p><strong>Event IDs:</strong> Werden in der calendar_events Tabelle gespeichert</p>";
echo "<p><strong>Problem möglicherweise:</strong></p>";
echo "<ul>";
echo "<li>❌ <strong>Falsche Client ID</strong> - Service Account JSON ist ungültig</li>";
echo "<li>❌ <strong>Falsche Event ID</strong> - Event wurde nicht korrekt erstellt</li>";
echo "<li>❌ <strong>Berechtigungen</strong> - Service Account hat keine Lösch-Berechtigung</li>";
echo "<li>❌ <strong>Calendar ID</strong> - Falscher Kalender wird verwendet</li>";
echo "</ul>";

echo "<p><a href='admin/settings.php'>→ Einstellungen anzeigen</a></p>";
echo "<p><a href='admin/reservations.php'>→ Reservierungen anzeigen</a></p>";
echo "<p><small>Event IDs Debug abgeschlossen: " . date('Y-m-d H:i:s') . "</small></p>";
?>
