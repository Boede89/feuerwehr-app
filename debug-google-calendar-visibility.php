<?php
/**
 * Debug: Google Calendar Sichtbarkeit - Warum werden Events nicht durchgestrichen?
 */

require_once 'config/database.php';
require_once 'includes/functions.php';

echo "<h1>👁️ Debug: Google Calendar Sichtbarkeit</h1>";

// 1. Erstelle Test-Event
echo "<h2>1. Erstelle Test-Event</h2>";

try {
    $test_event_id = create_google_calendar_event(
        'MTF',
        'Sichtbarkeit Test',
        date('Y-m-d H:i:s'),
        date('Y-m-d H:i:s', strtotime('+1 hour')),
        null,
        'Feuerwehrhaus Ammern'
    );
    
    if ($test_event_id) {
        echo "<p style='color: green;'>✅ Test-Event erstellt: $test_event_id</p>";
        
        // 2. Detaillierte Event-Analyse
        echo "<h2>2. Detaillierte Event-Analyse</h2>";
        
        try {
            if (class_exists('GoogleCalendarServiceAccount')) {
                $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'google_calendar_service_account_json'");
                $stmt->execute();
                $service_account_json = $stmt->fetchColumn();
                
                if ($service_account_json) {
                    $calendar_service = new GoogleCalendarServiceAccount($service_account_json, 'a3f7e2f57f274ba2fe7d3a62a932a33c78ed468aafa6ac477b58f16495e5677a@group.calendar.google.com', true);
                    
                    // Event vor dem Löschen
                    echo "<h3>2.1 Event vor dem Löschen</h3>";
                    
                    try {
                        $event_before = $calendar_service->getEvent($test_event_id);
                        echo "<p style='color: green;'>✅ Event vor dem Löschen gefunden</p>";
                        echo "<p><strong>Status:</strong> " . ($event_before['status'] ?? 'Unbekannt') . "</p>";
                        echo "<p><strong>Summary:</strong> " . ($event_before['summary'] ?? 'Unbekannt') . "</p>";
                        echo "<p><strong>Visibility:</strong> " . ($event_before['visibility'] ?? 'Nicht gesetzt') . "</p>";
                        echo "<p><strong>Transparency:</strong> " . ($event_before['transparency'] ?? 'Nicht gesetzt') . "</p>";
                        echo "<p><strong>Created:</strong> " . ($event_before['created'] ?? 'Unbekannt') . "</p>";
                        echo "<p><strong>Updated:</strong> " . ($event_before['updated'] ?? 'Unbekannt') . "</p>";
                        
                    } catch (Exception $e) {
                        echo "<p style='color: red;'>❌ Fehler beim Abrufen des Events vor dem Löschen: " . $e->getMessage() . "</p>";
                    }
                    
                    // Event löschen
                    echo "<h3>2.2 Event löschen</h3>";
                    
                    $start_time = microtime(true);
                    $result = $calendar_service->deleteEvent($test_event_id);
                    $end_time = microtime(true);
                    
                    $duration = round(($end_time - $start_time) * 1000, 2);
                    
                    echo "<p><strong>Lösch-Dauer:</strong> {$duration}ms</p>";
                    echo "<p><strong>deleteEvent Ergebnis:</strong> " . ($result ? 'TRUE' : 'FALSE') . "</p>";
                    
                    // Event nach dem Löschen
                    echo "<h3>2.3 Event nach dem Löschen</h3>";
                    
                    try {
                        $event_after = $calendar_service->getEvent($test_event_id);
                        echo "<p style='color: orange;'>⚠️ Event existiert noch nach dem Löschen</p>";
                        echo "<p><strong>Status:</strong> " . ($event_after['status'] ?? 'Unbekannt') . "</p>";
                        echo "<p><strong>Summary:</strong> " . ($event_after['summary'] ?? 'Unbekannt') . "</p>";
                        echo "<p><strong>Visibility:</strong> " . ($event_after['visibility'] ?? 'Nicht gesetzt') . "</p>";
                        echo "<p><strong>Transparency:</strong> " . ($event_after['transparency'] ?? 'Nicht gesetzt') . "</p>";
                        echo "<p><strong>Created:</strong> " . ($event_after['created'] ?? 'Unbekannt') . "</p>";
                        echo "<p><strong>Updated:</strong> " . ($event_after['updated'] ?? 'Unbekannt') . "</p>";
                        
                        // Prüfe ob Event wirklich cancelled ist
                        if (isset($event_after['status']) && $event_after['status'] === 'cancelled') {
                            echo "<p style='color: blue;'>ℹ️ Event ist cancelled - sollte durchgestrichen sein</p>";
                            
                            // Prüfe ob Event in der Liste erscheint
                            echo "<h4>2.3.1 Prüfe Event in der Liste</h4>";
                            
                            try {
                                $events = $calendar_service->getEvents(date('Y-m-d H:i:s', strtotime('-1 day')), date('Y-m-d H:i:s', strtotime('+1 day')));
                                
                                if ($events && is_array($events)) {
                                    $found_in_list = false;
                                    foreach ($events as $event) {
                                        if (isset($event['id']) && $event['id'] === $test_event_id) {
                                            $found_in_list = true;
                                            echo "<p style='color: orange;'>⚠️ Event erscheint in der Liste</p>";
                                            echo "<p><strong>Liste Status:</strong> " . ($event['status'] ?? 'Unbekannt') . "</p>";
                                            echo "<p><strong>Liste Summary:</strong> " . ($event['summary'] ?? 'Unbekannt') . "</p>";
                                            break;
                                        }
                                    }
                                    
                                    if (!$found_in_list) {
                                        echo "<p style='color: green;'>✅ Event erscheint nicht in der Liste (korrekt)</p>";
                                    }
                                } else {
                                    echo "<p style='color: red;'>❌ Keine Events in der Liste gefunden</p>";
                                }
                                
                            } catch (Exception $e) {
                                echo "<p style='color: red;'>❌ Fehler beim Abrufen der Event-Liste: " . $e->getMessage() . "</p>";
                            }
                            
                        } else {
                            echo "<p style='color: red;'>❌ Event ist NICHT cancelled - das ist das Problem!</p>";
                        }
                        
                    } catch (Exception $e) {
                        if (strpos($e->getMessage(), '404') !== false) {
                            echo "<p style='color: green; font-weight: bold;'>🎉 Event wurde vollständig gelöscht (404 Not Found)!</p>";
                        } else {
                            echo "<p style='color: red;'>❌ Fehler beim Abrufen des Events nach dem Löschen: " . $e->getMessage() . "</p>";
                        }
                    }
                    
                }
            }
            
        } catch (Exception $e) {
            echo "<p style='color: red;'>❌ Fehler beim Test: " . $e->getMessage() . "</p>";
        }
        
    } else {
        echo "<p style='color: red;'>❌ Test-Event konnte nicht erstellt werden</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim Test: " . $e->getMessage() . "</p>";
}

// 3. Teste mit echtem Event
echo "<h2>3. Teste mit echtem Event</h2>";

$real_event_id = '6lu3icbt1ketrk3tp8kujqs4fs'; // Das Event aus Ihrer Meldung

echo "<p><strong>Teste mit echtem Event:</strong> $real_event_id</p>";

try {
    if (class_exists('GoogleCalendarServiceAccount')) {
        $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'google_calendar_service_account_json'");
        $stmt->execute();
        $service_account_json = $stmt->fetchColumn();
        
        if ($service_account_json) {
            $calendar_service = new GoogleCalendarServiceAccount($service_account_json, 'a3f7e2f57f274ba2fe7d3a62a932a33c78ed468aafa6ac477b58f16495e5677a@group.calendar.google.com', true);
            
            // Prüfe Event Status
            echo "<h3>3.1 Event Status prüfen</h3>";
            
            try {
                $event = $calendar_service->getEvent($real_event_id);
                echo "<p style='color: green;'>✅ Echtes Event gefunden</p>";
                echo "<p><strong>Status:</strong> " . ($event['status'] ?? 'Unbekannt') . "</p>";
                echo "<p><strong>Summary:</strong> " . ($event['summary'] ?? 'Unbekannt') . "</p>";
                echo "<p><strong>Visibility:</strong> " . ($event['visibility'] ?? 'Nicht gesetzt') . "</p>";
                echo "<p><strong>Transparency:</strong> " . ($event['transparency'] ?? 'Nicht gesetzt') . "</p>";
                echo "<p><strong>Created:</strong> " . ($event['created'] ?? 'Unbekannt') . "</p>";
                echo "<p><strong>Updated:</strong> " . ($event['updated'] ?? 'Unbekannt') . "</p>";
                
                if (isset($event['status']) && $event['status'] === 'cancelled') {
                    echo "<p style='color: blue;'>ℹ️ Echtes Event ist cancelled - sollte durchgestrichen sein</p>";
                } else {
                    echo "<p style='color: red;'>❌ Echtes Event ist NICHT cancelled - das ist das Problem!</p>";
                }
                
            } catch (Exception $e) {
                echo "<p style='color: red;'>❌ Fehler beim Abrufen des echten Events: " . $e->getMessage() . "</p>";
            }
        }
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim echten Event Test: " . $e->getMessage() . "</p>";
}

echo "<hr>";
echo "<h2>📋 Zusammenfassung</h2>";
echo "<p>Dieses Debug-Skript prüft:</p>";
echo "<ul>";
echo "<li>✅ Event Status vor und nach dem Löschen</li>";
echo "<li>✅ Event Visibility und Transparency</li>";
echo "<li>✅ Ob Event in der Liste erscheint</li>";
echo "<li>✅ Ob Event wirklich cancelled ist</li>";
echo "</ul>";

echo "<p><strong>Mögliche Ursachen für nicht durchgestrichene Events:</strong></p>";
echo "<ul>";
echo "<li>🔍 Event ist nicht wirklich cancelled</li>";
echo "<li>👁️ Google Calendar zeigt cancelled Events nicht durchgestrichen an</li>";
echo "<li>🔄 Cache-Problem im Google Calendar</li>";
echo "<li>⚙️ Google Calendar Einstellungen</li>";
echo "</ul>";

echo "<p><a href='admin/reservations.php'>→ Zur Reservierungen-Übersicht</a></p>";
echo "<p><a href='test-access-token-fix.php'>→ Access Token Fix Test</a></p>";
echo "<p><small>Sichtbarkeit Debug abgeschlossen: " . date('Y-m-d H:i:s') . "</small></p>";
?>
