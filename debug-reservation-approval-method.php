<?php
/**
 * Debug: Wie wird die Reservierung genehmigt?
 */

session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';

// Session-Fix für die App
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    $stmt = $db->query("SELECT id, username, email, user_role, is_admin, role, first_name, last_name FROM users WHERE user_role = 'admin' OR role = 'admin' OR is_admin = 1 LIMIT 1");
    $admin_user = $stmt->fetch();
    if ($admin_user) {
        $_SESSION['user_id'] = $admin_user['id'];
        $_SESSION['role'] = 'admin';
        $_SESSION['first_name'] = $admin_user['first_name'];
        $_SESSION['last_name'] = $admin_user['last_name'];
        $_SESSION['username'] = $admin_user['username'];
        $_SESSION['email'] = $admin_user['email'];
    }
}

echo "<h1>🔍 Debug: Wie wird die Reservierung genehmigt?</h1>";

try {
    // 1. Prüfe alle genehmigten Reservierungen
    echo "<h2>1. Alle genehmigten Reservierungen</h2>";
    $stmt = $db->query("SELECT id, requester_name, reason, start_datetime, end_datetime, status, approved_by, approved_at FROM reservations WHERE status = 'approved' ORDER BY approved_at DESC LIMIT 10");
    $approved_reservations = $stmt->fetchAll();
    
    if ($approved_reservations) {
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr><th>ID</th><th>Antragsteller</th><th>Grund</th><th>Start</th><th>Ende</th><th>Genehmigt von</th><th>Genehmigt am</th></tr>";
        foreach ($approved_reservations as $res) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($res['id']) . "</td>";
            echo "<td>" . htmlspecialchars($res['requester_name']) . "</td>";
            echo "<td>" . htmlspecialchars($res['reason']) . "</td>";
            echo "<td>" . htmlspecialchars($res['start_datetime']) . "</td>";
            echo "<td>" . htmlspecialchars($res['end_datetime']) . "</td>";
            echo "<td>" . htmlspecialchars($res['approved_by']) . "</td>";
            echo "<td>" . htmlspecialchars($res['approved_at']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p>Keine genehmigten Reservierungen gefunden.</p>";
    }
    
    // 2. Prüfe alle ausstehenden Reservierungen
    echo "<h2>2. Alle ausstehenden Reservierungen</h2>";
    $stmt = $db->query("SELECT id, requester_name, reason, start_datetime, end_datetime, status FROM reservations WHERE status = 'pending' ORDER BY id DESC LIMIT 10");
    $pending_reservations = $stmt->fetchAll();
    
    if ($pending_reservations) {
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr><th>ID</th><th>Antragsteller</th><th>Grund</th><th>Start</th><th>Ende</th><th>Status</th></tr>";
        foreach ($pending_reservations as $res) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($res['id']) . "</td>";
            echo "<td>" . htmlspecialchars($res['requester_name']) . "</td>";
            echo "<td>" . htmlspecialchars($res['reason']) . "</td>";
            echo "<td>" . htmlspecialchars($res['start_datetime']) . "</td>";
            echo "<td>" . htmlspecialchars($res['end_datetime']) . "</td>";
            echo "<td>" . htmlspecialchars($res['status']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p>Keine ausstehenden Reservierungen gefunden.</p>";
    }
    
    // 3. Prüfe Google Calendar Events
    echo "<h2>3. Google Calendar Events</h2>";
    $stmt = $db->query("SELECT * FROM calendar_events ORDER BY id DESC LIMIT 10");
    $calendar_events = $stmt->fetchAll();
    
    if ($calendar_events) {
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr><th>ID</th><th>Reservation ID</th><th>Google Event ID</th><th>Titel</th><th>Start</th><th>Ende</th></tr>";
        foreach ($calendar_events as $event) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($event['id']) . "</td>";
            echo "<td>" . htmlspecialchars($event['reservation_id']) . "</td>";
            echo "<td>" . htmlspecialchars($event['google_event_id']) . "</td>";
            echo "<td>" . htmlspecialchars($event['title']) . "</td>";
            echo "<td>" . htmlspecialchars($event['start_datetime']) . "</td>";
            echo "<td>" . htmlspecialchars($event['end_datetime']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p>Keine Google Calendar Events gefunden.</p>";
    }
    
    // 4. Teste direkte Genehmigung
    echo "<h2>4. Teste direkte Genehmigung</h2>";
    if (!empty($pending_reservations)) {
        $test_reservation = $pending_reservations[0];
        echo "<p>Teste Genehmigung für Reservierung ID: " . $test_reservation['id'] . "</p>";
        
        // Schreibe Test-Log
        error_log('TEST DIRECT APPROVAL: Starte direkte Genehmigung für ID: ' . $test_reservation['id']);
        echo "<p>🔍 Schreibe Test-Log für direkte Genehmigung...</p>";
        
        // Genehmige direkt
        $stmt = $db->prepare("UPDATE reservations SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?");
        $stmt->execute([$_SESSION['user_id'], $test_reservation['id']]);
        
        error_log('TEST DIRECT APPROVAL: Reservierung genehmigt - ID: ' . $test_reservation['id']);
        echo "<p>✅ Reservierung genehmigt - ID: " . $test_reservation['id'] . "</p>";
        
        // Google Calendar Event erstellen
        $stmt = $db->prepare("SELECT r.*, v.name as vehicle_name FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id WHERE r.id = ?");
        $stmt->execute([$test_reservation['id']]);
        $reservation = $stmt->fetch();
        
        if ($reservation) {
            error_log('TEST DIRECT APPROVAL: Reservierung für Google Calendar geladen - ID: ' . $reservation['id']);
            echo "<p>🔍 Reservierung für Google Calendar geladen - ID: " . $reservation['id'] . "</p>";
            
            if (function_exists('create_google_calendar_event')) {
                error_log('TEST DIRECT APPROVAL: create_google_calendar_event Funktion verfügbar');
                echo "<p>✅ create_google_calendar_event Funktion verfügbar</p>";
                
                $event_id = create_google_calendar_event(
                    $reservation['vehicle_name'],
                    $reservation['reason'],
                    $reservation['start_datetime'],
                    $reservation['end_datetime'],
                    $reservation['id'],
                    $reservation['location'] ?? null
                );
                
                error_log('TEST DIRECT APPROVAL: create_google_calendar_event Rückgabe: ' . ($event_id ? $event_id : 'false'));
                echo "<p>🔍 create_google_calendar_event Rückgabe: " . ($event_id ? $event_id : 'false') . "</p>";
                
                if ($event_id) {
                    echo "<p style='color: green;'>✅ Google Calendar Event erfolgreich erstellt! Event ID: " . $event_id . "</p>";
                } else {
                    echo "<p style='color: red;'>❌ Google Calendar Event konnte nicht erstellt werden.</p>";
                }
            } else {
                echo "<p style='color: red;'>❌ create_google_calendar_event Funktion nicht verfügbar.</p>";
            }
        } else {
            echo "<p style='color: red;'>❌ Reservierung nicht gefunden für Google Calendar.</p>";
        }
        
        echo "<p style='color: green;'>✅ Test-Genehmigung abgeschlossen. Prüfe die Logs!</p>";
    } else {
        echo "<p>Keine ausstehenden Reservierungen zum Testen gefunden.</p>";
    }
    
    echo "<h2>5. Nächste Schritte</h2>";
    echo "<p>1. Prüfe die Logs: <a href='debug-google-calendar-logs.php'>Debug Google Calendar Logs</a></p>";
    echo "<p>2. Prüfe die Admin-Seiten: <a href='admin/dashboard.php'>Dashboard</a> | <a href='admin/reservations.php'>Reservierungen</a></p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Fehler: " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>
