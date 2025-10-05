<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

header('Content-Type: text/html; charset=utf-8');

$reservationId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
echo "<h1>🔧 Test: create_google_calendar_event für Reservierung</h1>";
echo "<p><strong>Reservierung ID:</strong> " . ($reservationId ?: 'nicht gesetzt') . "</p>";

if ($reservationId <= 0) {
    echo "<p style='color:red'>❌ Bitte URL mit Parameter ?id=RESERVATION_ID aufrufen (z. B. ?id=226)</p>";
    exit;
}

try {
    $stmt = $db->prepare("SELECT r.*, v.name AS vehicle_name FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id WHERE r.id = ?");
    $stmt->execute([$reservationId]);
    $r = $stmt->fetch();
    if (!$r) {
        echo "<p style='color:red'>❌ Reservierung nicht gefunden</p>";
        exit;
    }

    echo "<h2>1) Reservierungsdaten</h2>";
    echo "<ul>";
    echo "<li>Fahrzeug: " . htmlspecialchars($r['vehicle_name']) . "</li>";
    echo "<li>Grund: " . htmlspecialchars($r['reason']) . "</li>";
    echo "<li>Start: " . htmlspecialchars($r['start_datetime']) . "</li>";
    echo "<li>Ende: " . htmlspecialchars($r['end_datetime']) . "</li>";
    echo "<li>Ort: " . htmlspecialchars($r['location'] ?? '') . "</li>";
    echo "<li>Status: " . htmlspecialchars($r['status']) . "</li>";
    echo "</ul>";

    echo "<h2>2) Google Calendar Einstellungen</h2>";
    $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'google_calendar_%'");
    $stmt->execute();
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $authType = $settings['google_calendar_auth_type'] ?? 'service_account';
    $calendarId = $settings['google_calendar_id'] ?? '';
    $jsonSet = !empty($settings['google_calendar_service_account_json']);
    $fileSet = !empty($settings['google_calendar_service_account_file']);
    echo "<ul>";
    echo "<li>auth_type: " . htmlspecialchars($authType) . "</li>";
    echo "<li>calendar_id: " . htmlspecialchars($calendarId) . "</li>";
    echo "<li>service_account_json: " . ($jsonSet ? 'gesetzt' : 'leer') . "</li>";
    echo "<li>service_account_file: " . ($fileSet ? htmlspecialchars($settings['google_calendar_service_account_file']) : 'leer') . "</li>";
    echo "</ul>";

    echo "<h2>3) Direkter Aufruf: create_google_calendar_event</h2>";
    $title = $r['vehicle_name'] . ' - ' . $r['reason'];
    echo "<p><strong>Titel:</strong> " . htmlspecialchars($title) . "</p>";

    // Ausführung
    $eventId = create_google_calendar_event(
        $title,
        $r['reason'],
        $r['start_datetime'],
        $r['end_datetime'],
        (int)$r['id'],
        $r['location'] ?? null
    );

    if ($eventId) {
        echo "<p style='color:green'>✅ Erfolgreich: Event ID = " . htmlspecialchars($eventId) . "</p>";
    } else {
        echo "<p style='color:red'>❌ create_google_calendar_event hat FALSE zurückgegeben</p>";
    }

    echo "<h2>4) Letzte Error-Logs (falls konfiguriert)</h2>";
    $errorLog = ini_get('error_log');
    if ($errorLog && file_exists($errorLog)) {
        $lines = @file($errorLog);
        if ($lines !== false) {
            $tail = array_slice($lines, -60);
            echo "<pre style='background:#f6f8fa;padding:10px;max-height:400px;overflow:auto'>" . htmlspecialchars(implode('', $tail)) . "</pre>";
        } else {
            echo "<p>Keine Log-Datei lesbar.</p>";
        }
    } else {
        echo "<p>error_log nicht gesetzt oder Datei existiert nicht.</p>";
    }

    echo "<h2>5) Prüfe calendar_events Eintrag</h2>";
    $stmt = $db->prepare("SELECT * FROM calendar_events WHERE reservation_id = ? ORDER BY id DESC");
    $stmt->execute([$reservationId]);
    $rows = $stmt->fetchAll();
    if ($rows) {
        echo "<ul>";
        foreach ($rows as $row) {
            echo "<li>ID=" . (int)$row['id'] . ", google_event_id=" . htmlspecialchars($row['google_event_id']) . ", title=" . htmlspecialchars($row['title']) . "</li>";
        }
        echo "</ul>";
    } else {
        echo "<p>Kein Eintrag in calendar_events.</p>";
    }

    echo "<hr><p><a href='admin/dashboard.php'>← Zum Dashboard</a></p>";
} catch (Exception $e) {
    echo "<p style='color:red'>❌ Exception: " . htmlspecialchars($e->getMessage()) . "</p>";
}

?>


