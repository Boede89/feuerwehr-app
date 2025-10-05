<?php
/**
 * Debug: Dashboard Modals Problem
 */

require_once 'config/database.php';
require_once 'includes/functions.php';

echo "<h1>🔍 Dashboard Modals Debug</h1>";

// 1. Lade Reservierungen
echo "<h2>1. Reservierungen laden</h2>";

try {
    $stmt = $db->prepare("
        SELECT r.*, v.name as vehicle_name, u.first_name, u.last_name, u.email as requester_email
        FROM reservations r 
        JOIN vehicles v ON r.vehicle_id = v.id 
        JOIN users u ON r.user_id = u.id 
        WHERE r.status = 'pending' 
        ORDER BY r.created_at DESC
    ");
    $stmt->execute();
    $pending_reservations = $stmt->fetchAll();
    
    echo "<p style='color: green;'>✅ " . count($pending_reservations) . " ausstehende Reservierungen gefunden</p>";
    
    if (!empty($pending_reservations)) {
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>ID</th><th>Fahrzeug</th><th>Antragsteller</th><th>Status</th></tr>";
        foreach ($pending_reservations as $reservation) {
            echo "<tr>";
            echo "<td>" . $reservation['id'] . "</td>";
            echo "<td>" . htmlspecialchars($reservation['vehicle_name']) . "</td>";
            echo "<td>" . htmlspecialchars($reservation['first_name'] . ' ' . $reservation['last_name']) . "</td>";
            echo "<td>" . $reservation['status'] . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim Laden der Reservierungen: " . $e->getMessage() . "</p>";
}

// 2. Teste Modal-HTML
echo "<h2>2. Modal-HTML Test</h2>";

if (!empty($pending_reservations)) {
    $test_reservation = $pending_reservations[0];
    echo "<p><strong>Teste Modal für Reservierung #{$test_reservation['id']}</strong></p>";
    
    echo "<h3>2.1 Modal-Button</h3>";
    echo "<button type='button' class='btn btn-primary' data-bs-toggle='modal' data-bs-target='#detailsModal{$test_reservation['id']}'>";
    echo "<i class='fas fa-info-circle'></i> Details anzeigen";
    echo "</button>";
    
    echo "<h3>2.2 Modal-HTML</h3>";
    echo "<div class='modal fade' id='detailsModal{$test_reservation['id']}' tabindex='-1'>";
    echo "<div class='modal-dialog modal-lg'>";
    echo "<div class='modal-content'>";
    echo "<div class='modal-header'>";
    echo "<h5 class='modal-title'>Reservierungsdetails #{$test_reservation['id']}</h5>";
    echo "<button type='button' class='btn-close' data-bs-dismiss='modal'></button>";
    echo "</div>";
    echo "<div class='modal-body'>";
    echo "<p><strong>Fahrzeug:</strong> " . htmlspecialchars($test_reservation['vehicle_name']) . "</p>";
    echo "<p><strong>Antragsteller:</strong> " . htmlspecialchars($test_reservation['first_name'] . ' ' . $test_reservation['last_name']) . "</p>";
    echo "</div>";
    echo "<div class='modal-footer'>";
    echo "<button type='button' class='btn btn-secondary' data-bs-dismiss='modal'>Schließen</button>";
    echo "</div>";
    echo "</div>";
    echo "</div>";
    echo "</div>";
}

// 3. Bootstrap JavaScript Test
echo "<h2>3. Bootstrap JavaScript Test</h2>";

echo "<p>Prüfe ob Bootstrap JavaScript geladen ist...</p>";
echo "<script>";
echo "if (typeof bootstrap !== 'undefined') {";
echo "  document.write('<p style=\"color: green;\">✅ Bootstrap JavaScript ist geladen</p>');";
echo "} else {";
echo "  document.write('<p style=\"color: red;\">❌ Bootstrap JavaScript ist NICHT geladen</p>');";
echo "}";
echo "</script>";

// 4. Teste Modal-Funktionalität
echo "<h2>4. Modal-Funktionalität Test</h2>";

if (!empty($pending_reservations)) {
    $test_reservation = $pending_reservations[0];
    echo "<p><strong>Teste Modal-Öffnung für Reservierung #{$test_reservation['id']}</strong></p>";
    
    echo "<button type='button' class='btn btn-primary' onclick='testModal()'>Modal testen</button>";
    
    echo "<script>";
    echo "function testModal() {";
    echo "  var modal = new bootstrap.Modal(document.getElementById('detailsModal{$test_reservation['id']}'));";
    echo "  modal.show();";
    echo "}";
    echo "</script>";
}

echo "<hr>";
echo "<p><a href='admin/dashboard.php'>← Zurück zum Dashboard</a></p>";
echo "<p><small>Zeitstempel: " . date('Y-m-d H:i:s') . "</small></p>";
?>
