<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

header('Content-Type: text/html; charset=utf-8');

$reservationId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
echo "<h1>🔧 Debug: Vorhandenes Event suchen und Titel erweitern</h1>";
echo "<p><strong>Reservierung ID:</strong> " . ($reservationId ?: 'nicht gesetzt') . "</p>";

if ($reservationId <= 0) {
    echo "<p style='color:red'>❌ Bitte URL mit Parameter ?id=RESERVATION_ID aufrufen (z. B. ?id=234)</p>";
    exit;
}

try {
    // Reservierung laden
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

    echo "<h2>2) Suche vorhandenes Event (gleicher Zeitraum + Grund)</h2>";
    $stmt = $db->prepare("SELECT ce.google_event_id, ce.title FROM calendar_events ce JOIN reservations rr ON ce.reservation_id = rr.id WHERE rr.start_datetime = ? AND rr.end_datetime = ? AND rr.reason = ? LIMIT 1");
    $stmt->execute([$r['start_datetime'], $r['end_datetime'], $r['reason']]);
    $existing = $stmt->fetch();

    if ($existing) {
        echo "<p style='color:green'>✅ Gefunden: google_event_id = " . htmlspecialchars($existing['google_event_id']) . ", title = " . htmlspecialchars($existing['title']) . "</p>";
    } else {
        echo "<p style='color:orange'>⚠️ Kein vorhandenes Event gefunden (laut DB-Verknüpfung)</p>";
    }

    if ($existing && !empty($existing['google_event_id'])) {
        $currentTitle = $existing['title'] ?? '';
        $needsAppend = stripos($currentTitle, $r['vehicle_name']) === false;
        $newTitle = $needsAppend ? ($currentTitle ? ($currentTitle . ', ' . $r['vehicle_name']) : ($r['vehicle_name'] . ' - ' . $r['reason'])) : $currentTitle;

        echo "<h2>3) Titel-Entscheidung</h2>";
        echo "<p>Aktueller Titel: " . htmlspecialchars($currentTitle) . "</p>";
        echo "<p>Neuer Titel (falls nötig): " . htmlspecialchars($newTitle) . "</p>";

        if ($needsAppend) {
            if (function_exists('update_google_calendar_event_title')) {
                echo "<h2>4) Aktualisiere Google Calendar Event Titel</h2>";
                $ok = update_google_calendar_event_title($existing['google_event_id'], $newTitle);
                if ($ok) {
                    echo "<p style='color:green'>✅ Titel im Google Kalender aktualisiert</p>";
                    // DB-Titel angleichen
                    $stmt = $db->prepare("UPDATE calendar_events SET title = ? WHERE google_event_id = ?");
                    $stmt->execute([$newTitle, $existing['google_event_id']]);
                    echo "<p>Lokaler Titel aktualisiert.</p>";
                } else {
                    echo "<p style='color:red'>❌ update_google_calendar_event_title fehlgeschlagen</p>";
                }
            } else {
                echo "<p style='color:red'>❌ Funktion update_google_calendar_event_title nicht verfügbar</p>";
            }
        } else {
            echo "<p>Fahrzeug bereits im Titel enthalten – kein Update nötig.</p>";
        }

        // Verknüpfung für diese Reservierung sicherstellen
        echo "<h2>5) Verknüpfung in calendar_events sicherstellen</h2>";
        $stmt = $db->prepare("SELECT id FROM calendar_events WHERE reservation_id = ?");
        $stmt->execute([$reservationId]);
        $link = $stmt->fetch();
        if (!$link) {
            $stmt = $db->prepare("INSERT INTO calendar_events (reservation_id, google_event_id, title, start_datetime, end_datetime, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
            $stmt->execute([$reservationId, $existing['google_event_id'], $needsAppend ? $newTitle : $currentTitle, $r['start_datetime'], $r['end_datetime']]);
            echo "<p style='color:green'>✅ Verknüpfung hinzugefügt.</p>";
        } else {
            echo "<p>Verknüpfung existiert bereits.</p>";
        }
    }

    echo "<h2>6) Error-Logs</h2>";
    $errorLog = ini_get('error_log');
    if ($errorLog && file_exists($errorLog)) {
        $lines = @file($errorLog);
        if ($lines !== false) {
            $tail = array_slice($lines, -80);
            echo "<pre style='background:#f6f8fa;padding:10px;max-height:400px;overflow:auto'>" . htmlspecialchars(implode('', $tail)) . "</pre>";
        } else {
            echo "<p>Keine Log-Datei lesbar.</p>";
        }
    } else {
        echo "<p>error_log nicht gesetzt oder Datei existiert nicht.</p>";
    }

    echo "<hr><p><a href='debug-dashboard-live-reservations.php'>← Zurück</a></p>";
} catch (Exception $e) {
    echo "<p style='color:red'>❌ Exception: " . htmlspecialchars($e->getMessage()) . "</p>";
}

?>


