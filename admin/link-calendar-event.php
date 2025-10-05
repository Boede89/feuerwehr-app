<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || !has_admin_access()) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

function h($v) { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }

$reservation_id = (int)($_GET['reservation_id'] ?? $_POST['reservation_id'] ?? 0);
if ($reservation_id <= 0) {
    http_response_code(400);
    echo 'Ungültige Reservierungs-ID';
    exit;
}

$message = '';
$error = '';

// Hole Reservierungsdaten
$stmt = $db->prepare('SELECT r.*, v.name AS vehicle_name FROM reservations r LEFT JOIN vehicles v ON r.vehicle_id = v.id WHERE r.id = ?');
$stmt->execute([$reservation_id]);
$reservation = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$reservation) {
    http_response_code(404);
    echo 'Reservierung nicht gefunden';
    exit;
}

// Verarbeitung: Verknüpfen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['google_event_id'])) {
    $google_event_id = trim($_POST['google_event_id']);
    $title = ($reservation['vehicle_name'] ? $reservation['vehicle_name'] . ' - ' : '') . ($reservation['reason'] ?? '');
    try {
        // calendar_events upsert: wenn vorhanden -> update, sonst insert
        $check = $db->prepare('SELECT id FROM calendar_events WHERE reservation_id = ?');
        $check->execute([$reservation_id]);
        $exists = $check->fetchColumn();
        if ($exists) {
            $upd = $db->prepare('UPDATE calendar_events SET google_event_id = ?, title = ?, start_datetime = ?, end_datetime = ? WHERE reservation_id = ?');
            $upd->execute([$google_event_id, $title, $reservation['start_datetime'], $reservation['end_datetime'], $reservation_id]);
        } else {
            $ins = $db->prepare('INSERT INTO calendar_events (reservation_id, google_event_id, title, start_datetime, end_datetime, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
            $ins->execute([$reservation_id, $google_event_id, $title, $reservation['start_datetime'], $reservation['end_datetime']]);
        }
        $message = 'Kalender-ID erfolgreich verknüpft.';
    } catch (Throwable $t) {
        $error = 'Fehler beim Verknüpfen: ' . $t->getMessage();
    }
}

// Kandidaten suchen (nur bei GET oder wenn Fehler)
$candidates = [];
try {
    // Google Calendar Einstellungen laden
    $st = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'google_calendar_%'");
    $st->execute();
    $settings = $st->fetchAll(PDO::FETCH_KEY_PAIR);
    $calendar_id = $settings['google_calendar_id'] ?? 'primary';
    $service_account_json = $settings['google_calendar_service_account_json'] ?? '';

    if (class_exists('GoogleCalendarServiceAccount') && !empty($service_account_json)) {
        $svc = new GoogleCalendarServiceAccount($service_account_json, $calendar_id, true);
        $timeWindowSeconds = 24 * 3600;
        $startIso = date('c', strtotime($reservation['start_datetime']) - $timeWindowSeconds);
        $endIso = date('c', strtotime($reservation['end_datetime']) + $timeWindowSeconds);
        $title = trim(($reservation['vehicle_name'] ? $reservation['vehicle_name'] . ' - ' : '') . ($reservation['reason'] ?? ''));

        // verschiedene Queries
        $vehiclePart = trim(strtok($title, '-'));
        $reasonPart = '';
        if (strpos($title, '-') !== false) {
            $parts = explode('-', $title, 2);
            $vehiclePart = trim($parts[0]);
            $reasonPart = trim($parts[1]);
        }
        $queries = array_values(array_unique(array_filter([$title, $vehiclePart, $reasonPart])));

        $agg = [];
        foreach ($queries as $q) {
            $res = $svc->getEvents($startIso, $endIso, $q);
            if (is_array($res)) {
                foreach ($res as $ev) {
                    $id = $ev['id'] ?? null;
                    if ($id && !isset($agg[$id])) { $agg[$id] = $ev; }
                }
            }
        }
        $candidates = array_values($agg);
    } else {
        $error = 'Google Service Account nicht konfiguriert.';
    }
} catch (Throwable $t) {
    $error = 'Fehler bei der Kandidatensuche: ' . $t->getMessage();
}

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kalender-ID verknüpfen</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <style> body{padding:20px;} code{word-break:break-all;} </style>
    </head>
<body>
    <div class="container">
        <h1 class="h4 mb-3"><i class="fas fa-link"></i> Kalender-ID verknüpfen</h1>
        <p class="text-muted">Reservierung #<?php echo (int)$reservation_id; ?> • <?php echo h($reservation['vehicle_name'] . ' | ' . $reservation['reason']); ?> • <?php echo h($reservation['start_datetime'] . ' - ' . $reservation['end_datetime']); ?></p>

        <?php if ($message): ?>
            <div class="alert alert-success"><?php echo h($message); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo h($error); ?></div>
        <?php endif; ?>

        <div class="card mb-4">
            <div class="card-header"><i class="fas fa-magnifying-glass"></i> Gefundene Events (±24h)</div>
            <div class="card-body">
                <?php if (empty($candidates)): ?>
                    <p class="text-muted m-0">Keine passenden Events gefunden.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead>
                                <tr>
                                    <th>Titel</th>
                                    <th>Start</th>
                                    <th>Ende</th>
                                    <th>Event-ID</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($candidates as $ev):
                                    $eid = $ev['id'] ?? '';
                                    $summary = $ev['summary'] ?? '';
                                    $s = $ev['start']['dateTime'] ?? ($ev['start']['date'] ?? '');
                                    $e = $ev['end']['dateTime'] ?? ($ev['end']['date'] ?? '');
                                ?>
                                <tr>
                                    <td><?php echo h($summary); ?></td>
                                    <td><small><?php echo h($s); ?></small></td>
                                    <td><small><?php echo h($e); ?></small></td>
                                    <td><code><?php echo h($eid); ?></code></td>
                                    <td>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Dieses Event mit der Reservierung verknüpfen?');">
                                            <input type="hidden" name="reservation_id" value="<?php echo (int)$reservation_id; ?>">
                                            <input type="hidden" name="google_event_id" value="<?php echo h($eid); ?>">
                                            <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-link"></i> Verknüpfen</button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <a class="btn btn-outline-secondary" href="reservations.php"><i class="fas fa-arrow-left"></i> Zurück zu Reservierungen</a>
    </div>
</body>
</html>


