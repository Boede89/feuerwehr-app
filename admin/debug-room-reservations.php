<?php
/**
 * Debug: Zeigt alle pending Raumreservierungen und prüft die Datenbank-Struktur.
 * Aufruf: admin/debug-room-reservations.php
 */
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/rooms-setup.php';

if (!isset($_SESSION['user_id']) || !hasAdminPermission()) {
    header('Location: ../login.php');
    exit;
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Debug: Raumreservierungen</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="p-4">
<div class="container">
    <h1>Debug: Raumreservierungen</h1>
    <p><a href="dashboard.php" class="btn btn-outline-secondary">← Zum Dashboard</a></p>

    <?php
    try {
        // Tabelle existiert?
        $stmt = $db->query("SHOW TABLES LIKE 'room_reservations'");
        if (!$stmt->fetch()) {
            echo '<div class="alert alert-danger">Tabelle room_reservations existiert nicht!</div>';
            exit;
        }
        echo '<div class="alert alert-success">Tabelle room_reservations existiert.</div>';

        // Alle pending Reservierungen
        $stmt = $db->query("
            SELECT rr.*, ro.name as room_name, ro.einheit_id as room_einheit_id
            FROM room_reservations rr
            JOIN rooms ro ON rr.room_id = ro.id
            WHERE rr.status = 'pending'
            ORDER BY rr.created_at DESC
        ");
        $all = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo '<h3>Pending Raumreservierungen: ' . count($all) . '</h3>';
        if (empty($all)) {
            echo '<p class="text-muted">Keine ausstehenden Raumreservierungen in der Datenbank.</p>';
            echo '<p>Erstellen Sie eine neue Raumreservierung über die App, dann prüfen Sie diese Seite erneut.</p>';
        } else {
            echo '<table class="table table-bordered"><thead><tr><th>ID</th><th>Raum</th><th>Antragsteller</th><th>Datum</th><th>rr.einheit_id</th><th>ro.einheit_id</th></tr></thead><tbody>';
            foreach ($all as $r) {
                echo '<tr>';
                echo '<td>' . (int)$r['id'] . '</td>';
                echo '<td>' . htmlspecialchars($r['room_name']) . '</td>';
                echo '<td>' . htmlspecialchars($r['requester_name']) . '</td>';
                echo '<td>' . htmlspecialchars($r['start_datetime']) . '</td>';
                echo '<td>' . ($r['einheit_id'] ?? 'NULL') . '</td>';
                echo '<td>' . ($r['room_einheit_id'] ?? 'NULL') . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        // Session-Werte
        echo '<h3 class="mt-4">Session</h3>';
        echo '<p>current_unit_id: ' . ($_SESSION['current_unit_id'] ?? 'nicht gesetzt') . '</p>';
        echo '<p>current_einheit_id: ' . ($_SESSION['current_einheit_id'] ?? 'nicht gesetzt') . '</p>';
    } catch (Exception $e) {
        echo '<div class="alert alert-danger">Fehler: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
    ?>
</div>
</body>
</html>
