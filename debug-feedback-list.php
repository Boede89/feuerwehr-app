<?php
session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';

echo "<h2>Feedback-Liste Debug</h2>";

try {
    // Alle Feedbacks anzeigen
    $stmt = $db->query("
        SELECT f.*, u.username, u.first_name, u.last_name 
        FROM feedback f 
        LEFT JOIN users u ON f.user_id = u.id 
        ORDER BY f.created_at DESC
    ");
    $all_feedback = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<h3>Alle Feedbacks (insgesamt: " . count($all_feedback) . ")</h3>";
    
    if (empty($all_feedback)) {
        echo "<p style='color: red;'>Keine Feedbacks gefunden!</p>";
    } else {
        echo "<table border='1' cellpadding='5' cellspacing='0' style='width: 100%;'>";
        echo "<tr><th>ID</th><th>Status</th><th>Typ</th><th>Betreff</th><th>Benutzer</th><th>Erstellt</th><th>Aktualisiert</th></tr>";
        
        foreach ($all_feedback as $feedback) {
            $status_color = [
                'new' => 'blue',
                'in_progress' => 'orange', 
                'resolved' => 'green',
                'closed' => 'gray'
            ][$feedback['status']] ?? 'black';
            
            echo "<tr>";
            echo "<td>{$feedback['id']}</td>";
            echo "<td style='color: $status_color; font-weight: bold;'>{$feedback['status']}</td>";
            echo "<td>{$feedback['feedback_type']}</td>";
            echo "<td>" . htmlspecialchars(substr($feedback['subject'], 0, 50)) . "...</td>";
            echo "<td>" . htmlspecialchars($feedback['first_name'] . ' ' . $feedback['last_name']) . "</td>";
            echo "<td>{$feedback['created_at']}</td>";
            echo "<td>{$feedback['updated_at']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
    // Status-Statistiken
    echo "<h3>Status-Statistiken:</h3>";
    $stats_sql = "SELECT status, COUNT(*) as count FROM feedback GROUP BY status";
    $stats_stmt = $db->query($stats_sql);
    $stats = $stats_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    echo "<ul>";
    foreach ($stats as $status => $count) {
        echo "<li><strong>$status:</strong> $count</li>";
    }
    echo "</ul>";
    
    // Geschlossene Feedbacks separat anzeigen
    echo "<h3>Geschlossene Feedbacks:</h3>";
    $stmt = $db->prepare("
        SELECT f.*, u.username, u.first_name, u.last_name 
        FROM feedback f 
        LEFT JOIN users u ON f.user_id = u.id 
        WHERE f.status = 'closed'
        ORDER BY f.created_at DESC
    ");
    $stmt->execute();
    $closed_feedback = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($closed_feedback)) {
        echo "<p style='color: red;'>Keine geschlossenen Feedbacks gefunden!</p>";
    } else {
        echo "<p style='color: green;'>Gefunden: " . count($closed_feedback) . " geschlossene Feedbacks</p>";
        echo "<table border='1' cellpadding='5' cellspacing='0' style='width: 100%;'>";
        echo "<tr><th>ID</th><th>Betreff</th><th>Benutzer</th><th>Erstellt</th><th>Aktualisiert</th></tr>";
        
        foreach ($closed_feedback as $feedback) {
            echo "<tr>";
            echo "<td>{$feedback['id']}</td>";
            echo "<td>" . htmlspecialchars($feedback['subject']) . "</td>";
            echo "<td>" . htmlspecialchars($feedback['first_name'] . ' ' . $feedback['last_name']) . "</td>";
            echo "<td>{$feedback['created_at']}</td>";
            echo "<td>{$feedback['updated_at']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Fehler: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<hr>";
echo "<p><a href='admin/feedback.php'>← Zurück zur Feedback-Verwaltung</a></p>";
echo "<p><a href='debug-feedback.php'>← Zurück zur Debug-Seite</a></p>";
?>
