<?php
/**
 * Einfaches Debug-Skript für Dashboard-Problem
 * Öffnen Sie diese Datei in Ihrem Browser: http://ihre-domain/debug-dashboard-simple.php
 */

// Alle Fehler anzeigen
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<!DOCTYPE html>";
echo "<html><head><title>Dashboard Debug</title>";
echo "<style>body{font-family:Arial,sans-serif;margin:20px;} .success{color:green;} .error{color:red;} .warning{color:orange;} table{border-collapse:collapse;width:100%;} th,td{border:1px solid #ddd;padding:8px;text-align:left;} th{background-color:#f2f2f2;}</style>";
echo "</head><body>";

echo "<h1>🔍 Dashboard Debug - Warum werden offene Anträge nicht angezeigt?</h1>";

try {
    // 1. Datenbankverbindung
    echo "<h2>1. Datenbankverbindung testen</h2>";
    require_once 'config/database.php';
    echo "<p class='success'>✅ Datenbankverbindung erfolgreich</p>";
    
    // 2. Prüfe alle Reservierungen
    echo "<h2>2. Alle Reservierungen in der Datenbank</h2>";
    $stmt = $db->prepare("
        SELECT r.*, v.name as vehicle_name
        FROM reservations r
        JOIN vehicles v ON r.vehicle_id = v.id
        ORDER BY r.created_at DESC
    ");
    $stmt->execute();
    $all_reservations = $stmt->fetchAll();
    
    echo "<p>Gefundene Reservierungen: <strong>" . count($all_reservations) . "</strong></p>";
    
    if (count($all_reservations) > 0) {
        echo "<table>";
        echo "<tr><th>ID</th><th>Fahrzeug</th><th>Status</th><th>Antragsteller</th><th>Grund</th><th>Erstellt</th></tr>";
        foreach ($all_reservations as $res) {
            $status_color = $res['status'] === 'pending' ? 'warning' : ($res['status'] === 'approved' ? 'success' : 'error');
            echo "<tr>";
            echo "<td>" . $res['id'] . "</td>";
            echo "<td>" . htmlspecialchars($res['vehicle_name']) . "</td>";
            echo "<td class='$status_color'><strong>" . $res['status'] . "</strong></td>";
            echo "<td>" . htmlspecialchars($res['requester_name']) . "</td>";
            echo "<td>" . htmlspecialchars(substr($res['reason'], 0, 50)) . "...</td>";
            echo "<td>" . $res['created_at'] . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p class='warning'>⚠️ Keine Reservierungen in der Datenbank gefunden!</p>";
    }
    
    // 3. Teste Dashboard-Logik
    echo "<h2>3. Dashboard-Logik testen</h2>";
    
    // Trenne in ausstehend und bearbeitet (genau wie im Dashboard)
    $pending_reservations = array_filter($all_reservations, function($r) {
        return $r['status'] === 'pending';
    });
    
    $processed_reservations = array_filter($all_reservations, function($r) {
        return in_array($r['status'], ['approved', 'rejected']);
    });
    
    echo "<p>Ausstehende Reservierungen: <strong>" . count($pending_reservations) . "</strong></p>";
    echo "<p>Bearbeitete Reservierungen: <strong>" . count($processed_reservations) . "</strong></p>";
    
    // 4. Teste die Bedingung die im Dashboard verwendet wird
    echo "<h2>4. Dashboard-Bedingung testen</h2>";
    
    $is_empty = empty($pending_reservations);
    echo "<p>empty(\$pending_reservations): <strong>" . ($is_empty ? 'TRUE (leer)' : 'FALSE (nicht leer)') . "</strong></p>";
    
    if ($is_empty) {
        echo "<p class='error'>❌ Das Dashboard zeigt 'Keine ausstehenden Anträge' an, weil empty() TRUE ist</p>";
    } else {
        echo "<p class='success'>✅ Das Dashboard sollte die Reservierungen anzeigen</p>";
    }
    
    // 5. Detaillierte Status-Analyse
    echo "<h2>5. Status-Analyse</h2>";
    $status_counts = [];
    foreach ($all_reservations as $res) {
        $status = $res['status'];
        if (!isset($status_counts[$status])) {
            $status_counts[$status] = 0;
        }
        $status_counts[$status]++;
    }
    
    echo "<ul>";
    foreach ($status_counts as $status => $count) {
        echo "<li><strong>$status:</strong> $count Reservierungen</li>";
    }
    echo "</ul>";
    
    // 6. Teste array_filter einzeln
    echo "<h2>6. array_filter Test</h2>";
    echo "<p>Teste array_filter für 'pending':</p>";
    $test_pending = array_filter($all_reservations, function($r) {
        $result = $r['status'] === 'pending';
        echo "<small>ID {$r['id']}: Status '{$r['status']}' === 'pending' = " . ($result ? 'TRUE' : 'FALSE') . "</small><br>";
        return $result;
    });
    echo "<p>Ergebnis: <strong>" . count($test_pending) . "</strong> ausstehende Reservierungen</p>";
    
    // 7. Lösungsvorschläge
    echo "<h2>7. Lösungsvorschläge</h2>";
    
    if (count($all_reservations) == 0) {
        echo "<p class='warning'>⚠️ <strong>Problem:</strong> Keine Reservierungen in der Datenbank</p>";
        echo "<p><strong>Lösung:</strong> Erstellen Sie eine Test-Reservierung über das Reservierungsformular</p>";
    } elseif (count($pending_reservations) == 0) {
        echo "<p class='warning'>⚠️ <strong>Problem:</strong> Alle Reservierungen sind bereits bearbeitet (approved/rejected)</p>";
        echo "<p><strong>Lösung:</strong> Erstellen Sie eine neue Reservierung oder setzen Sie eine bestehende auf 'pending' zurück</p>";
    } else {
        echo "<p class='success'>✅ <strong>Problem nicht gefunden:</strong> Es gibt ausstehende Reservierungen, das Dashboard sollte sie anzeigen</p>";
        echo "<p><strong>Mögliche Ursachen:</strong></p>";
        echo "<ul>";
        echo "<li>JavaScript-Fehler im Browser</li>";
        echo "<li>CSS-Probleme die die Anzeige verhindern</li>";
        echo "<li>Session-Probleme</li>";
        echo "<li>PHP-Fehler die nicht angezeigt werden</li>";
        echo "</ul>";
    }
    
} catch (Exception $e) {
    echo "<p class='error'>❌ Fehler: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p>Stack Trace:</p><pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}

echo "<hr>";
echo "<p><a href='admin/dashboard.php'>← Zurück zum Dashboard</a></p>";
echo "<p><small>Zeitstempel: " . date('Y-m-d H:i:s') . "</small></p>";

echo "</body></html>";
?>
