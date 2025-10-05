<?php
require_once 'config/database.php';

header('Content-Type: text/html; charset=utf-8');
echo "<h1>🔧 Fix: calendar_events Indexe für Mehrfach-Zuordnung</h1>";

try {
    // 1) Indexe anzeigen
    echo "<h2>1) Aktuelle Indexe</h2>";
    $stmt = $db->query("SHOW INDEX FROM calendar_events");
    $indexes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($indexes) {
        echo "<pre style='background:#f6f8fa;padding:10px'>" . htmlspecialchars(print_r($indexes, true)) . "</pre>";
    } else {
        echo "<p>Keine Index-Infos gelesen.</p>";
    }

    // 2) UNIQUE-Index(e) auf google_event_id finden und entfernen
    echo "<h2>2) Entferne UNIQUE-Index auf google_event_id (falls vorhanden)</h2>";
    $uniqueKeys = array_filter($indexes, function ($ix) {
        return isset($ix['Column_name'], $ix['Non_unique']) && $ix['Column_name'] === 'google_event_id' && (int)$ix['Non_unique'] === 0;
    });
    if ($uniqueKeys) {
        // Es kann mehrere Einträge pro Key_name geben; wir droppen per Key_name einmal
        $seen = [];
        foreach ($uniqueKeys as $ix) {
            $keyName = $ix['Key_name'];
            if (isset($seen[$keyName])) continue;
            $seen[$keyName] = true;
            $sql = "ALTER TABLE calendar_events DROP INDEX `" . str_replace("`", "``", $keyName) . "`";
            echo "<p>Dropping UNIQUE index: <code>" . htmlspecialchars($keyName) . "</code> ...</p>";
            $db->exec($sql);
            echo "<p style='color:green'>✅ Entfernt</p>";
        }
    } else {
        echo "<p>Kein UNIQUE-Index auf google_event_id gefunden.</p>";
    }

    // 3) Sicherstellen: reservation_id ist eindeutig (jede Reservierung max. ein Mapping)
    echo "<h2>3) Setze UNIQUE(reservation_id) falls nicht vorhanden</h2>";
    $stmt = $db->query("SHOW INDEX FROM calendar_events");
    $idx2 = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $hasUniqueReservation = false;
    foreach ($idx2 as $ix) {
        if ($ix['Column_name'] === 'reservation_id' && (int)$ix['Non_unique'] === 0) {
            $hasUniqueReservation = true;
            break;
        }
    }
    if (!$hasUniqueReservation) {
        echo "<p>Erstelle UNIQUE Index auf reservation_id...</p>";
        $db->exec("ALTER TABLE calendar_events ADD UNIQUE KEY `uniq_reservation_id` (`reservation_id`)");
        echo "<p style='color:green'>✅ UNIQUE(reservation_id) gesetzt</p>";
    } else {
        echo "<p>UNIQUE(reservation_id) besteht bereits.</p>";
    }

    // 4) Normalen (nicht-unique) Index auf google_event_id setzen (für Suche)
    echo "<h2>4) Setze Index auf google_event_id (nicht unique)</h2>";
    $stmt = $db->query("SHOW INDEX FROM calendar_events");
    $idx3 = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $hasNonUniqueGoogle = false;
    foreach ($idx3 as $ix) {
        if ($ix['Column_name'] === 'google_event_id') {
            // Wenn irgendein Index existiert (auch non-unique), akzeptieren
            $hasNonUniqueGoogle = true;
            break;
        }
    }
    if (!$hasNonUniqueGoogle) {
        echo "<p>Erstelle Index auf google_event_id...</p>";
        $db->exec("ALTER TABLE calendar_events ADD KEY `idx_google_event_id` (`google_event_id`)");
        echo "<p style='color:green'>✅ Index(google_event_id) gesetzt</p>";
    } else {
        echo "<p>Index auf google_event_id existiert bereits.</p>";
    }

    echo "<hr><p style='color:green'><strong>Fertig.</strong> Jetzt können mehrere Reservierungen dem gleichen Google-Event zugeordnet werden.</p>";
    echo "<p><a href='debug-update-existing-event.php?id=234'>→ Erneut Titel-Erweiterung für 234 testen</a></p>";
    echo "<p><a href='admin/dashboard.php'>→ Zum Dashboard</a></p>";
} catch (Exception $e) {
    echo "<p style='color:red'>❌ Exception: " . htmlspecialchars($e->getMessage()) . "</p>";
}

?>


