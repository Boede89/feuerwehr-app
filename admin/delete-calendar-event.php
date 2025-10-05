<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || !has_admin_access()) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

function safe_redirect_back($msg = '', $is_error = false) {
    $base = 'reservations.php';
    if (!empty($msg)) {
        $param = $is_error ? 'error' : 'message';
        $base .= '?' . $param . '=' . urlencode($msg);
    }
    header('Location: ' . $base);
    exit;
}

// Stelle sicher, dass die Debug-Log-Tabelle existiert und schreibe einen Start-Logeintrag
try {
    $db->exec("CREATE TABLE IF NOT EXISTS debug_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
        level ENUM('INFO','WARNING','ERROR','DEBUG') DEFAULT 'INFO',
        message TEXT,
        context TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    $stmt = $db->prepare("INSERT INTO debug_logs (level, message, context) VALUES (?, ?, ?)");
    $stmt->execute(['INFO', 'DELETE-ENDPOINT aufgerufen', 'admin/delete-calendar-event.php']);
} catch (Throwable $t) {
    // still proceed even if logging table cannot be created
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        safe_redirect_back('Ungültige Methode', true);
    }

    $reservation_id = (int)($_POST['reservation_id'] ?? 0);
    if ($reservation_id <= 0) {
        safe_redirect_back('Ungültige Reservierungs-ID', true);
    }

    // Versuche alle verknüpften Events per ID zu löschen
    $stmt = $db->prepare('SELECT google_event_id FROM calendar_events WHERE reservation_id = ?');
    $stmt->execute([$reservation_id]);
    $eventIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($eventIds)) {
        foreach ($eventIds as $geid) {
            if (!empty($geid) && function_exists('delete_google_calendar_event')) {
                $ok = delete_google_calendar_event($geid);
                if (!$ok) {
                    error_log('DELETE-ENDPOINT: delete_google_calendar_event fehlgeschlagen für ' . $geid);
                    try {
                        $stmt = $db->prepare("INSERT INTO debug_logs (level, message, context) VALUES (?, ?, ?)");
                        $stmt->execute(['WARNING', 'DELETE-ENDPOINT: delete_google_calendar_event fehlgeschlagen für ' . $geid, 'admin/delete-calendar-event.php']);
                    } catch (Throwable $t) {}
                }
            }
        }
    } else {
        error_log('DELETE-ENDPOINT: Keine calendar_events Verknüpfungen für reservation_id=' . $reservation_id);
        try {
            $stmt = $db->prepare("INSERT INTO debug_logs (level, message, context) VALUES (?, ?, ?)");
            $stmt->execute(['INFO', 'DELETE-ENDPOINT: Keine calendar_events Verknüpfungen für reservation_id=' . $reservation_id, 'admin/delete-calendar-event.php']);
        } catch (Throwable $t) {}
    }

    // Fallback per Titel/Zeitraum
    try {
        $stmt = $db->prepare('SELECT v.name as vehicle_name, r.reason, r.start_datetime, r.end_datetime FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id WHERE r.id = ?');
        $stmt->execute([$reservation_id]);
        $res = $stmt->fetch();
        if ($res && function_exists('delete_google_calendar_event_by_hint')) {
            $title = ($res['vehicle_name'] ?? '') . ' - ' . ($res['reason'] ?? '');
            // DB-Logging: Fallback wird versucht
            try {
                $stmt2 = $db->prepare("INSERT INTO debug_logs (level, message, context) VALUES (?, ?, ?)");
                $stmt2->execute(['INFO', 'DELETE-ENDPOINT: Versuche Fallback-Löschung per Hint: title=' . $title . ', start=' . $res['start_datetime'] . ', end=' . $res['end_datetime'], 'admin/delete-calendar-event.php']);
            } catch (Throwable $t) {}
            $hintOk = delete_google_calendar_event_by_hint($title, $res['start_datetime'], $res['end_datetime']);
            if ($hintOk) {
                error_log('DELETE-ENDPOINT: Fallback-Löschung per Hint erfolgreich für reservation_id=' . $reservation_id);
                try {
                    $stmt = $db->prepare("INSERT INTO debug_logs (level, message, context) VALUES (?, ?, ?)");
                    $stmt->execute(['INFO', 'DELETE-ENDPOINT: Fallback-Löschung per Hint erfolgreich für reservation_id=' . $reservation_id, 'admin/delete-calendar-event.php']);
                } catch (Throwable $t) {}
            } else {
                try {
                    $stmt3 = $db->prepare("INSERT INTO debug_logs (level, message, context) VALUES (?, ?, ?)");
                    $stmt3->execute(['WARNING', 'DELETE-ENDPOINT: Fallback-Löschung per Hint NICHT erfolgreich für reservation_id=' . $reservation_id, 'admin/delete-calendar-event.php']);
                } catch (Throwable $t) {}
            }
        }
    } catch (Exception $ie) {
        error_log('DELETE-ENDPOINT: Fallback-Query Fehler: ' . $ie->getMessage());
        try {
            $stmt = $db->prepare("INSERT INTO debug_logs (level, message, context) VALUES (?, ?, ?)");
            $stmt->execute(['ERROR', 'DELETE-ENDPOINT: Fallback-Query Fehler: ' . $ie->getMessage(), 'admin/delete-calendar-event.php']);
        } catch (Throwable $t) {}
    }

    // Lokale Verknüpfungen entfernen (nie fatal)
    $stmt = $db->prepare('DELETE FROM calendar_events WHERE reservation_id = ?');
    $stmt->execute([$reservation_id]);

    safe_redirect_back('Kalender-Eintrag(e) entfernt. Reservierung bleibt bestehen.');
} catch (Throwable $t) {
    error_log('DELETE-ENDPOINT: Fatal: ' . $t->getMessage());
    safe_redirect_back('Löschen fehlgeschlagen: ' . $t->getMessage(), true);
}
?>


