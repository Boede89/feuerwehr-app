<?php
// Debug: Reservierungen-Problem analysieren
require_once 'config/database.php';
require_once 'includes/functions.php';

echo "<h1>🔍 Debug: Reservierungen-Problem</h1>";

// 1. Prüfe ob Reservierungen in der Datenbank existieren
echo "<h2>1. Alle Reservierungen in der Datenbank:</h2>";
try {
    $stmt = $db->query("SELECT id, vehicle_id, requester_name, requester_email, reason, start_datetime, end_datetime, status, created_at FROM reservations ORDER BY created_at DESC LIMIT 10");
    $reservations = $stmt->fetchAll();
    
    if (empty($reservations)) {
        echo "<p style='color: red;'>❌ Keine Reservierungen in der Datenbank gefunden!</p>";
    } else {
        echo "<p style='color: green;'>✅ " . count($reservations) . " Reservierungen gefunden</p>";
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr><th>ID</th><th>Fahrzeug ID</th><th>Antragsteller</th><th>E-Mail</th><th>Grund</th><th>Start</th><th>Ende</th><th>Status</th><th>Erstellt</th></tr>";
        foreach ($reservations as $res) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($res['id']) . "</td>";
            echo "<td>" . htmlspecialchars($res['vehicle_id']) . "</td>";
            echo "<td>" . htmlspecialchars($res['requester_name']) . "</td>";
            echo "<td>" . htmlspecialchars($res['requester_email']) . "</td>";
            echo "<td>" . htmlspecialchars(substr($res['reason'], 0, 50)) . "...</td>";
            echo "<td>" . htmlspecialchars($res['start_datetime']) . "</td>";
            echo "<td>" . htmlspecialchars($res['end_datetime']) . "</td>";
            echo "<td style='color: " . ($res['status'] === 'pending' ? 'green' : 'red') . ";'>" . htmlspecialchars($res['status'] ?? 'NULL') . "</td>";
            echo "<td>" . htmlspecialchars($res['created_at']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim Laden der Reservierungen: " . htmlspecialchars($e->getMessage()) . "</p>";
}

// 2. Prüfe Dashboard-Abfrage
echo "<h2>2. Dashboard-Abfrage (nur pending):</h2>";
try {
    $stmt = $db->prepare("SELECT r.*, v.name as vehicle_name FROM reservations r JOIN vehicles v ON r.vehicle_id = v.id WHERE r.status = 'pending' ORDER BY r.created_at DESC LIMIT 5");
    $stmt->execute();
    $pending_reservations = $stmt->fetchAll();
    
    if (empty($pending_reservations)) {
        echo "<p style='color: red;'>❌ Keine pending Reservierungen gefunden!</p>";
    } else {
        echo "<p style='color: green;'>✅ " . count($pending_reservations) . " pending Reservierungen gefunden</p>";
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr><th>ID</th><th>Fahrzeug</th><th>Antragsteller</th><th>Status</th><th>Erstellt</th></tr>";
        foreach ($pending_reservations as $res) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($res['id']) . "</td>";
            echo "<td>" . htmlspecialchars($res['vehicle_name']) . "</td>";
            echo "<td>" . htmlspecialchars($res['requester_name']) . "</td>";
            echo "<td style='color: green;'>" . htmlspecialchars($res['status']) . "</td>";
            echo "<td>" . htmlspecialchars($res['created_at']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim Laden der pending Reservierungen: " . htmlspecialchars($e->getMessage()) . "</p>";
}

// 3. Prüfe E-Mail-Benachrichtigungen
echo "<h2>3. E-Mail-Benachrichtigungen prüfen:</h2>";
try {
    $stmt = $db->prepare("SELECT email FROM users WHERE user_role IN ('admin', 'approver') AND is_active = 1 AND email_notifications = 1");
    $stmt->execute();
    $admin_emails = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (empty($admin_emails)) {
        echo "<p style='color: red;'>❌ Keine Admin-E-Mails für Benachrichtigungen gefunden!</p>";
    } else {
        echo "<p style='color: green;'>✅ " . count($admin_emails) . " Admin-E-Mails gefunden:</p>";
        echo "<ul>";
        foreach ($admin_emails as $email) {
            echo "<li>" . htmlspecialchars($email) . "</li>";
        }
        echo "</ul>";
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim Laden der Admin-E-Mails: " . htmlspecialchars($e->getMessage()) . "</p>";
}

// 4. Prüfe E-Mail-Log
echo "<h2>4. E-Mail-Log prüfen:</h2>";
try {
    $stmt = $db->query("SELECT * FROM email_log ORDER BY created_at DESC LIMIT 5");
    $email_logs = $stmt->fetchAll();
    
    if (empty($email_logs)) {
        echo "<p style='color: orange;'>⚠️ Keine E-Mail-Logs gefunden</p>";
    } else {
        echo "<p style='color: green;'>✅ " . count($email_logs) . " E-Mail-Logs gefunden</p>";
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr><th>ID</th><th>Empfänger</th><th>Betreff</th><th>Status</th><th>Erstellt</th></tr>";
        foreach ($email_logs as $log) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($log['id']) . "</td>";
            echo "<td>" . htmlspecialchars($log['recipient_email']) . "</td>";
            echo "<td>" . htmlspecialchars(substr($log['subject'], 0, 50)) . "...</td>";
            echo "<td style='color: " . ($log['status'] === 'sent' ? 'green' : 'red') . ";'>" . htmlspecialchars($log['status']) . "</td>";
            echo "<td>" . htmlspecialchars($log['created_at']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim Laden der E-Mail-Logs: " . htmlspecialchars($e->getMessage()) . "</p>";
}

// 5. Teste send_email Funktion
echo "<h2>5. Teste send_email Funktion:</h2>";
if (function_exists('send_email')) {
    echo "<p style='color: green;'>✅ send_email Funktion ist verfügbar</p>";
    
    // Test-E-Mail senden
    $test_result = send_email('test@example.com', 'Test E-Mail', 'Dies ist eine Test-E-Mail');
    if ($test_result) {
        echo "<p style='color: green;'>✅ Test-E-Mail erfolgreich gesendet</p>";
    } else {
        echo "<p style='color: red;'>❌ Test-E-Mail fehlgeschlagen</p>";
    }
} else {
    echo "<p style='color: red;'>❌ send_email Funktion nicht verfügbar</p>";
}

echo "<hr>";
echo "<p><strong>Fazit:</strong> Prüfen Sie die obigen Ergebnisse, um das Problem zu identifizieren.</p>";
?>
