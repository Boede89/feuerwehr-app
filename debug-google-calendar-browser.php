<?php
require_once __DIR__ . '/includes/debug-auth.php';
/**
 * Debug: Google Calendar mit Browser-Logging
 */

require_once 'config/database.php';

echo "<!DOCTYPE html>";
echo "<html><head><title>Debug Google Calendar Browser</title></head><body>";
echo "<h1>🔍 Debug: Google Calendar mit Browser-Logging</h1>";
echo "<p>Zeitstempel: " . date('d.m.Y H:i:s') . "</p>";

try {
    echo "<h2>1. Setze Session-Werte</h2>";
    
    session_start();
    $_SESSION['user_id'] = 5;
    $_SESSION['role'] = 'admin';
    $_SESSION['first_name'] = 'Daniel';
    $_SESSION['last_name'] = 'Leuchtenberg';
    $_SESSION['username'] = 'Boede';
    $_SESSION['email'] = 'dleuchtenberg89@gmail.com';
    
    echo "✅ Session-Werte gesetzt<br>";
    
    echo "<h2>2. Lade Google Calendar Komponenten</h2>";
    
    // Lade alle Google Calendar Komponenten
    if (file_exists('includes/functions.php')) {
        require_once 'includes/functions.php';
        echo "✅ includes/functions.php geladen<br>";
    }
    
    if (file_exists('includes/google_calendar_service_account.php')) {
        require_once 'includes/google_calendar_service_account.php';
        echo "✅ includes/google_calendar_service_account.php geladen<br>";
    }
    
    if (file_exists('includes/google_calendar.php')) {
        require_once 'includes/google_calendar.php';
        echo "✅ includes/google_calendar.php geladen<br>";
    }
    
    echo "<h2>3. Prüfe Funktionen</h2>";
    
    if (function_exists('create_google_calendar_event')) {
        echo "✅ create_google_calendar_event Funktion ist verfügbar<br>";
    } else {
        echo "❌ create_google_calendar_event Funktion ist NICHT verfügbar<br>";
    }
    
    if (class_exists('GoogleCalendarServiceAccount')) {
        echo "✅ GoogleCalendarServiceAccount Klasse ist verfügbar<br>";
    } else {
        echo "❌ GoogleCalendarServiceAccount Klasse ist NICHT verfügbar<br>";
    }
    
    echo "<h2>4. Teste Google Calendar Integration mit detailliertem Logging</h2>";
    
    // Prüfe ausstehende Reservierungen
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
        echo "Teste Genehmigung für Reservierung ID: {$reservation['id']}<br>";
        echo "Fahrzeug: {$reservation['vehicle_name']}<br>";
        echo "Grund: {$reservation['reason']}<br>";
        echo "Start: {$reservation['start_datetime']}<br>";
        echo "Ende: {$reservation['end_datetime']}<br>";
        echo "Ort: {$reservation['location']}<br>";
        
        // Simuliere Genehmigung
        $stmt = $db->prepare("UPDATE reservations SET status = 'approved', approved_by = 5, approved_at = NOW() WHERE id = ?");
        $result = $stmt->execute([$reservation['id']]);
        
        if ($result) {
            echo "✅ Reservierung erfolgreich genehmigt!<br>";
            
            // Teste Google Calendar Event Erstellung mit detailliertem Logging
            echo "Erstelle Google Calendar Event...<br>";
            
            // Logge alle Parameter
            echo "Parameter für create_google_calendar_event:<br>";
            echo "- vehicle_name: {$reservation['vehicle_name']}<br>";
            echo "- reason: {$reservation['reason']}<br>";
            echo "- start_datetime: {$reservation['start_datetime']}<br>";
            echo "- end_datetime: {$reservation['end_datetime']}<br>";
            echo "- reservation_id: {$reservation['id']}<br>";
            echo "- location: {$reservation['location']}<br>";
            
            try {
                // Teste Google Calendar Einstellungen
                $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'google_calendar_%'");
                $stmt->execute();
                $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
                
                echo "Google Calendar Einstellungen:<br>";
                echo "- auth_type: " . ($settings['google_calendar_auth_type'] ?? 'Nicht gesetzt') . "<br>";
                echo "- calendar_id: " . ($settings['google_calendar_id'] ?? 'Nicht gesetzt') . "<br>";
                echo "- service_account_json: " . (isset($settings['google_calendar_service_account_json']) ? 'Gesetzt (' . strlen($settings['google_calendar_service_account_json']) . ' Zeichen)' : 'Nicht gesetzt') . "<br>";
                
                // Teste Service Account Initialisierung
                if (class_exists('GoogleCalendarServiceAccount')) {
                    $service_account_json = $settings['google_calendar_service_account_json'] ?? '';
                    $calendar_id = $settings['google_calendar_id'] ?? 'primary';
                    
                    if (!empty($service_account_json)) {
                        echo "Initialisiere GoogleCalendarServiceAccount...<br>";
                        $google_calendar = new GoogleCalendarServiceAccount($service_account_json, $calendar_id, true);
                        echo "✅ GoogleCalendarServiceAccount initialisiert<br>";
                        
                        // Teste Access Token
                        echo "Teste Access Token...<br>";
                        $access_token = $google_calendar->getAccessToken();
                        if ($access_token) {
                            echo "✅ Access Token erhalten: " . substr($access_token, 0, 20) . "...<br>";
                        } else {
                            echo "❌ Access Token konnte nicht erhalten werden<br>";
                        }
                    } else {
                        echo "❌ Service Account JSON ist leer<br>";
                    }
                } else {
                    echo "❌ GoogleCalendarServiceAccount Klasse ist nicht verfügbar<br>";
                }
                
                // Teste create_google_calendar_event Funktion
                echo "Rufe create_google_calendar_event auf...<br>";
                $event_id = create_google_calendar_event(
                    $reservation['vehicle_name'],
                    $reservation['reason'],
                    $reservation['start_datetime'],
                    $reservation['end_datetime'],
                    $reservation['id'],
                    $reservation['location']
                );
                
                if ($event_id) {
                    echo "✅ Google Calendar Event erfolgreich erstellt! Event ID: $event_id<br>";
                    
                    // Prüfe ob Event in der Datenbank gespeichert wurde
                    $stmt = $db->prepare("SELECT * FROM calendar_events WHERE reservation_id = ?");
                    $stmt->execute([$reservation['id']]);
                    $calendar_event = $stmt->fetch();
                    
                    if ($calendar_event) {
                        echo "✅ Event in der Datenbank gespeichert (ID: {$calendar_event['id']})<br>";
                    } else {
                        echo "⚠️ Event nicht in der Datenbank gespeichert<br>";
                    }
                    
                    // Lösche Test Event
                    if (class_exists('GoogleCalendarServiceAccount')) {
                        $service_account_json = $settings['google_calendar_service_account_json'] ?? '';
                        $calendar_id = $settings['google_calendar_id'] ?? 'primary';
                        
                        if (!empty($service_account_json)) {
                            $google_calendar = new GoogleCalendarServiceAccount($service_account_json, $calendar_id, true);
                            $google_calendar->deleteEvent($event_id);
                            echo "✅ Test Event gelöscht<br>";
                        }
                    }
                    
                    // Lösche Test Event aus der Datenbank
                    $stmt = $db->prepare("DELETE FROM calendar_events WHERE reservation_id = ?");
                    $stmt->execute([$reservation['id']]);
                    echo "✅ Test Event aus der Datenbank gelöscht<br>";
                    
                } else {
                    echo "❌ Google Calendar Event konnte nicht erstellt werden<br>";
                    echo "create_google_calendar_event() hat false zurückgegeben<br>";
                }
                
            } catch (Exception $e) {
                echo "❌ Google Calendar Fehler: " . htmlspecialchars($e->getMessage()) . "<br>";
                echo "Stack Trace:<br>";
                echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
            }
            
            // Setze zurück für weiteren Test
            $stmt = $db->prepare("UPDATE reservations SET status = 'pending', approved_by = NULL, approved_at = NULL WHERE id = ?");
            $stmt->execute([$reservation['id']]);
            echo "✅ Reservierung zurückgesetzt für weiteren Test<br>";
            
        } else {
            echo "❌ Fehler bei der Genehmigung!<br>";
        }
    } else {
        echo "ℹ️ Keine ausstehenden Reservierungen zum Testen gefunden<br>";
    }
    
} catch (Exception $e) {
    echo "<div style='color: red;'>";
    echo "<h3>❌ Fehler aufgetreten:</h3>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    echo "</div>";
}

echo "<h2>5. Browser Console Logging aktivieren</h2>";
?>

<script>
console.log('🔍 Google Calendar Debug gestartet');
console.log('Zeitstempel:', new Date().toLocaleString('de-DE'));

// Teste Google Calendar Funktionen
<?php if (function_exists('create_google_calendar_event')): ?>
    console.log('✅ create_google_calendar_event Funktion ist verfügbar');
<?php else: ?>
    console.error('❌ create_google_calendar_event Funktion ist NICHT verfügbar');
<?php endif; ?>

<?php if (class_exists('GoogleCalendarServiceAccount')): ?>
    console.log('✅ GoogleCalendarServiceAccount Klasse ist verfügbar');
<?php else: ?>
    console.error('❌ GoogleCalendarServiceAccount Klasse ist NICHT verfügbar');
<?php endif; ?>

// Teste Reservierungsgenehmigung mit Google Calendar
function testReservationApproval() {
    console.log('🧪 Teste Reservierungsgenehmigung mit Google Calendar');
    
    // Simuliere Formular-Submit
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'admin/dashboard.php';
    
    const actionInput = document.createElement('input');
    actionInput.type = 'hidden';
    actionInput.name = 'action';
    actionInput.value = 'approve';
    form.appendChild(actionInput);
    
    const reservationIdInput = document.createElement('input');
    reservationIdInput.type = 'hidden';
    reservationIdInput.name = 'reservation_id';
    reservationIdInput.value = '<?php echo $reservation['id'] ?? '0'; ?>';
    form.appendChild(reservationIdInput);
    
    document.body.appendChild(form);
    
    console.log('Formular erstellt:', {
        action: 'approve',
        reservation_id: '<?php echo $reservation['id'] ?? '0'; ?>'
    });
    
    // Formular nicht wirklich absenden, nur für Test
    console.log('Formular würde abgesendet werden');
    
    document.body.removeChild(form);
}

// Teste Google Calendar Event Erstellung
function testGoogleCalendarEvent() {
    console.log('🧪 Teste Google Calendar Event Erstellung');
    
    // Simuliere Parameter
    const params = {
        vehicle_name: '<?php echo $reservation['vehicle_name'] ?? 'Test Fahrzeug'; ?>',
        reason: '<?php echo $reservation['reason'] ?? 'Test Grund'; ?>',
        start_datetime: '<?php echo $reservation['start_datetime'] ?? '2025-10-04 15:00:00'; ?>',
        end_datetime: '<?php echo $reservation['end_datetime'] ?? '2025-10-04 16:00:00'; ?>',
        reservation_id: '<?php echo $reservation['id'] ?? '0'; ?>',
        location: '<?php echo $reservation['location'] ?? 'Test Ort'; ?>'
    };
    
    console.log('Parameter für create_google_calendar_event:', params);
    
    // Teste ob Funktion verfügbar ist
    <?php if (function_exists('create_google_calendar_event')): ?>
        console.log('✅ create_google_calendar_event ist verfügbar');
        
        // Teste direkten Aufruf
        try {
            <?php
require_once __DIR__ . '/includes/debug-auth.php';
            if ($reservation) {
                echo "const result = create_google_calendar_event(";
                echo "'{$reservation['vehicle_name']}', ";
                echo "'{$reservation['reason']}', ";
                echo "'{$reservation['start_datetime']}', ";
                echo "'{$reservation['end_datetime']}', ";
                echo "{$reservation['id']}, ";
                echo "'{$reservation['location']}'";
                echo ");";
            }
            ?>
            
            if (result) {
                console.log('✅ Google Calendar Event erfolgreich erstellt:', result);
            } else {
                console.error('❌ Google Calendar Event konnte nicht erstellt werden');
            }
        } catch (error) {
            console.error('❌ Fehler beim Erstellen des Google Calendar Events:', error);
        }
    <?php else: ?>
        console.error('❌ create_google_calendar_event ist NICHT verfügbar');
    <?php endif; ?>
}

// Führe Tests aus
testReservationApproval();
testGoogleCalendarEvent();

console.log('🔍 Google Calendar Debug abgeschlossen');
</script>

<?php
require_once __DIR__ . '/includes/debug-auth.php';
echo "<hr>";
echo "<p><a href='admin/dashboard.php'>Zum Dashboard</a> | <a href='admin/reservations.php'>Zu den Reservierungen</a></p>";
echo "</body></html>";
?>
