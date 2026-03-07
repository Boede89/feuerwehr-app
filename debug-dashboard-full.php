<?php
require_once __DIR__ . '/includes/debug-auth.php';
/**
 * Debug: Dashboard mit vollständiger Fehleranzeige
 */

// Alle Fehler anzeigen
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
ini_set('memory_limit', '256M');
ini_set('max_execution_time', 60);

echo "🔍 Dashboard Debug - Vollständige Fehleranzeige<br><br>";

// Lade Funktionen
require_once 'config/database.php';
require_once 'includes/functions.php';

// Session-Fix für die App
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    // Lade Admin-Benutzer aus der Datenbank
    $stmt = $db->query("SELECT id, username, email, user_role, is_admin, role, first_name, last_name FROM users WHERE user_role = 'admin' OR role = 'admin' OR is_admin = 1 LIMIT 1");
    $admin_user = $stmt->fetch();
    
    if ($admin_user) {
        $_SESSION['user_id'] = $admin_user['id'];
        $_SESSION['role'] = 'admin';
        $_SESSION['first_name'] = $admin_user['first_name'];
        $_SESSION['last_name'] = $admin_user['last_name'];
        $_SESSION['username'] = $admin_user['username'];
        $_SESSION['email'] = $admin_user['email'];
    }
}

echo "✅ Session geladen: User ID " . ($_SESSION['user_id'] ?? 'nicht gesetzt') . "<br>";

// Nur für eingeloggte Benutzer mit Genehmiger-Zugriff
if (!can_approve_reservations()) {
    echo "❌ Keine Berechtigung für Genehmigungen<br>";
    exit;
}

echo "✅ Berechtigung OK<br>";

$error = '';
$message = '';

echo "🔍 Lade Reservierungen...<br>";

try {
    // Alle Reservierungen (ausstehend + bearbeitet)
    $stmt = $db->prepare("
        SELECT r.*, v.name as vehicle_name
        FROM reservations r
        JOIN vehicles v ON r.vehicle_id = v.id
        ORDER BY r.created_at DESC
    ");
    $stmt->execute();
    $all_reservations = $stmt->fetchAll();
    
    echo "✅ Alle Reservierungen geladen: " . count($all_reservations) . "<br>";
    
    // Trenne in ausstehend und bearbeitet
    $pending_reservations = array_filter($all_reservations, function($r) {
        return $r['status'] === 'pending';
    });
    
    $processed_reservations = array_filter($all_reservations, function($r) {
        return in_array($r['status'], ['approved', 'rejected']);
    });
    
    echo "✅ Ausstehende Reservierungen: " . count($pending_reservations) . "<br>";
    echo "✅ Bearbeitete Reservierungen: " . count($processed_reservations) . "<br>";
    
} catch(PDOException $e) {
    $error = "Fehler beim Laden der Reservierungen: " . $e->getMessage();
    echo "❌ Fehler: " . $error . "<br>";
    $all_reservations = [];
    $pending_reservations = [];
    $processed_reservations = [];
}

echo "<hr>";
echo "<h1>Dashboard Test</h1>";

echo "<h2>Offene Anträge (" . count($pending_reservations) . ")</h2>";

if (empty($pending_reservations)) {
    echo "<p>Keine ausstehenden Anträge</p>";
} else {
    echo "<p>Es gibt " . count($pending_reservations) . " ausstehende Anträge:</p>";
    
    foreach ($pending_reservations as $index => $reservation) {
        echo "<div style='border: 1px solid #ccc; margin: 10px 0; padding: 10px;'>";
        echo "<h3>Reservierung #" . ($index + 1) . " (ID: " . $reservation['id'] . ")</h3>";
        echo "<p><strong>Fahrzeug:</strong> " . htmlspecialchars($reservation['vehicle_name']) . "</p>";
        echo "<p><strong>Antragsteller:</strong> " . htmlspecialchars($reservation['requester_name']) . "</p>";
        echo "<p><strong>Grund:</strong> " . htmlspecialchars($reservation['reason']) . "</p>";
        echo "<p><strong>Zeitraum:</strong> " . $reservation['start_datetime'] . " bis " . $reservation['end_datetime'] . "</p>";
        echo "<p><strong>Status:</strong> " . $reservation['status'] . "</p>";
        echo "</div>";
    }
}

echo "<hr>";
echo "<p><strong>Test abgeschlossen!</strong></p>";
echo "<p>Zeitstempel: " . date('Y-m-d H:i:s') . "</p>";
?>
