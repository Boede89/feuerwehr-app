<?php
/**
 * Debug: Dashboard Reservierungen - Warum werden ausstehende Anträge nicht angezeigt?
 */

echo "<h1>Debug: Dashboard Reservierungen - Warum werden ausstehende Anträge nicht angezeigt?</h1>";

// Lade Funktionen
require_once 'config/database.php';

echo "<h2>1. Prüfe alle Reservierungen in der Datenbank</h2>";

try {
    $stmt = $db->prepare("
        SELECT r.*, v.name as vehicle_name
        FROM reservations r
        JOIN vehicles v ON r.vehicle_id = v.id
        ORDER BY r.created_at DESC
    ");
    $stmt->execute();
    $all_reservations = $stmt->fetchAll();
    
    echo "<p>✅ Alle Reservierungen geladen: " . count($all_reservations) . "</p>";
    
    if (count($all_reservations) > 0) {
        echo "<h3>1.1 Alle Reservierungen:</h3>";
        echo "<table class='table table-striped'>";
        echo "<thead><tr><th>ID</th><th>Fahrzeug</th><th>Status</th><th>Grund</th><th>Erstellt</th></tr></thead>";
        echo "<tbody>";
        foreach ($all_reservations as $res) {
            echo "<tr>";
            echo "<td>" . $res['id'] . "</td>";
            echo "<td>" . htmlspecialchars($res['vehicle_name']) . "</td>";
            echo "<td><span class='badge bg-" . ($res['status'] === 'pending' ? 'warning' : ($res['status'] === 'approved' ? 'success' : 'danger')) . "'>" . $res['status'] . "</span></td>";
            echo "<td>" . htmlspecialchars($res['reason']) . "</td>";
            echo "<td>" . $res['created_at'] . "</td>";
            echo "</tr>";
        }
        echo "</tbody>";
        echo "</table>";
    }
    
    echo "<h2>2. Trenne Reservierungen nach Status</h2>";
    
    // Trenne in ausstehend und bearbeitet
    $pending_reservations = array_filter($all_reservations, function($r) {
        return $r['status'] === 'pending';
    });
    
    $processed_reservations = array_filter($all_reservations, function($r) {
        return in_array($r['status'], ['approved', 'rejected']);
    });
    
    echo "<p>✅ Ausstehende Reservierungen: " . count($pending_reservations) . "</p>";
    echo "<p>✅ Bearbeitete Reservierungen: " . count($processed_reservations) . "</p>";
    
    if (count($pending_reservations) > 0) {
        echo "<h3>2.1 Ausstehende Reservierungen:</h3>";
        echo "<table class='table table-striped'>";
        echo "<thead><tr><th>ID</th><th>Fahrzeug</th><th>Grund</th><th>Erstellt</th></tr></thead>";
        echo "<tbody>";
        foreach ($pending_reservations as $res) {
            echo "<tr>";
            echo "<td>" . $res['id'] . "</td>";
            echo "<td>" . htmlspecialchars($res['vehicle_name']) . "</td>";
            echo "<td>" . htmlspecialchars($res['reason']) . "</td>";
            echo "<td>" . $res['created_at'] . "</td>";
            echo "</tr>";
        }
        echo "</tbody>";
        echo "</table>";
    } else {
        echo "<p style='color: orange;'>⚠️ Keine ausstehenden Reservierungen gefunden!</p>";
    }
    
    if (count($processed_reservations) > 0) {
        echo "<h3>2.2 Bearbeitete Reservierungen:</h3>";
        echo "<table class='table table-striped'>";
        echo "<thead><tr><th>ID</th><th>Fahrzeug</th><th>Status</th><th>Grund</th><th>Erstellt</th></tr></thead>";
        echo "<tbody>";
        foreach ($processed_reservations as $res) {
            echo "<tr>";
            echo "<td>" . $res['id'] . "</td>";
            echo "<td>" . htmlspecialchars($res['vehicle_name']) . "</td>";
            echo "<td><span class='badge bg-" . ($res['status'] === 'approved' ? 'success' : 'danger') . "'>" . $res['status'] . "</span></td>";
            echo "<td>" . htmlspecialchars($res['reason']) . "</td>";
            echo "<td>" . $res['created_at'] . "</td>";
            echo "</tr>";
        }
        echo "</tbody>";
        echo "</table>";
    }
    
    echo "<h2>3. Prüfe Status-Werte</h2>";
    
    $status_counts = [];
    foreach ($all_reservations as $res) {
        $status = $res['status'];
        if (!isset($status_counts[$status])) {
            $status_counts[$status] = 0;
        }
        $status_counts[$status]++;
    }
    
    echo "<h3>3.1 Status-Verteilung:</h3>";
    echo "<ul>";
    foreach ($status_counts as $status => $count) {
        echo "<li><strong>$status:</strong> $count Reservierungen</li>";
    }
    echo "</ul>";
    
    echo "<h2>4. Prüfe Dashboard-Logik</h2>";
    
    echo "<h3>4.1 array_filter Test:</h3>";
    $test_pending = array_filter($all_reservations, function($r) {
        return $r['status'] === 'pending';
    });
    echo "<p>array_filter für 'pending': " . count($test_pending) . " Reservierungen</p>";
    
    $test_processed = array_filter($all_reservations, function($r) {
        return in_array($r['status'], ['approved', 'rejected']);
    });
    echo "<p>array_filter für 'approved'/'rejected': " . count($test_processed) . " Reservierungen</p>";
    
    echo "<h3>4.2 Einzelne Status-Prüfungen:</h3>";
    foreach ($all_reservations as $res) {
        $is_pending = $res['status'] === 'pending';
        $is_processed = in_array($res['status'], ['approved', 'rejected']);
        echo "<p>ID {$res['id']}: Status '{$res['status']}' - Pending: " . ($is_pending ? 'JA' : 'NEIN') . " - Processed: " . ($is_processed ? 'JA' : 'NEIN') . "</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler: " . $e->getMessage() . "</p>";
}

echo "<h2>5. Nächste Schritte</h2>";
echo "<p>1. <a href='admin/dashboard.php'>Teste das Dashboard</a> - Prüfe die Debug-Logs</p>";
echo "<p>2. <a href='debug-google-calendar-fixed.php'>Prüfe die Logs</a></p>";

echo "<hr>";
echo "<p><small>Zeitstempel: " . date('Y-m-d H:i:s') . "</small></p>";
?>
