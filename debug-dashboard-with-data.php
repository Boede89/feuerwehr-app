<?php
/**
 * Debug: Dashboard mit echten Daten testen
 */

require_once 'config/database.php';
require_once 'includes/functions.php';

// Session starten
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Simuliere Admin-Login
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'admin';
$_SESSION['first_name'] = 'Debug';
$_SESSION['last_name'] = 'User';

echo "<!DOCTYPE html>";
echo "<html lang='de'>";
echo "<head>";
echo "<meta charset='UTF-8'>";
echo "<meta name='viewport' content='width=device-width, initial-scale=1.0'>";
echo "<title>Dashboard Debug mit Daten</title>";
echo "<link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css' rel='stylesheet'>";
echo "<link href='https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css' rel='stylesheet'>";
echo "</head>";
echo "<body>";

echo "<div class='container mt-4'>";
echo "<h1>🔍 Dashboard Debug mit echten Daten</h1>";

// Lade Reservierungen
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
    
    echo "<h2>1. Reservierungen geladen</h2>";
    echo "<p>Gefunden: " . count($pending_reservations) . " ausstehende Reservierungen</p>";
    
    if (!empty($pending_reservations)) {
        $test_reservation = $pending_reservations[0];
        echo "<h3>Test-Reservierung #{$test_reservation['id']}</h3>";
        echo "<ul>";
        echo "<li><strong>Fahrzeug:</strong> " . htmlspecialchars($test_reservation['vehicle_name']) . "</li>";
        echo "<li><strong>Antragsteller:</strong> " . htmlspecialchars($test_reservation['first_name'] . ' ' . $test_reservation['last_name']) . "</li>";
        echo "<li><strong>Start:</strong> " . $test_reservation['start_datetime'] . "</li>";
        echo "<li><strong>Ende:</strong> " . $test_reservation['end_datetime'] . "</li>";
        echo "</ul>";
        
        // Teste Modal-Button
        echo "<h3>2. Modal-Button Test</h3>";
        echo "<button type='button' class='btn btn-primary' data-bs-toggle='modal' data-bs-target='#detailsModal{$test_reservation['id']}'>";
        echo "<i class='fas fa-info-circle'></i> Details anzeigen";
        echo "</button>";
        
        // Teste Modal-HTML
        echo "<h3>3. Modal-HTML Test</h3>";
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
        echo "<p><strong>E-Mail:</strong> " . htmlspecialchars($test_reservation['requester_email']) . "</p>";
        echo "<p><strong>Start:</strong> " . date('d.m.Y H:i', strtotime($test_reservation['start_datetime'])) . "</p>";
        echo "<p><strong>Ende:</strong> " . date('d.m.Y H:i', strtotime($test_reservation['end_datetime'])) . "</p>";
        echo "<p><strong>Grund:</strong> " . htmlspecialchars($test_reservation['reason']) . "</p>";
        echo "<p><strong>Ort:</strong> " . htmlspecialchars($test_reservation['location'] ?? 'Nicht angegeben') . "</p>";
        echo "</div>";
        echo "<div class='modal-footer'>";
        echo "<button type='button' class='btn btn-success'>Genehmigen</button>";
        echo "<button type='button' class='btn btn-danger'>Ablehnen</button>";
        echo "<button type='button' class='btn btn-secondary' data-bs-dismiss='modal'>Schließen</button>";
        echo "</div>";
        echo "</div>";
        echo "</div>";
        echo "</div>";
        
        // JavaScript Test
        echo "<h3>4. JavaScript Test</h3>";
        echo "<button type='button' class='btn btn-success' onclick='testModal()'>Modal manuell öffnen</button>";
        echo "<button type='button' class='btn btn-info' onclick='debugElements()'>Elemente debuggen</button>";
        
    } else {
        echo "<p style='color: orange;'>⚠️ Keine ausstehenden Reservierungen gefunden</p>";
        echo "<p><a href='create-test-reservation-for-dashboard.php'>Test-Reservierung erstellen</a></p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Fehler beim Laden der Reservierungen: " . $e->getMessage() . "</p>";
}

echo "</div>";

echo "<script src='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js'></script>";
echo "<script>";
echo "function testModal() {";
if (!empty($pending_reservations)) {
    $test_reservation = $pending_reservations[0];
    echo "  console.log('Teste Modal für Reservierung #{$test_reservation['id']}');";
    echo "  var modalElement = document.getElementById('detailsModal{$test_reservation['id']}');";
    echo "  console.log('Modal Element:', modalElement);";
    echo "  if (modalElement) {";
    echo "    var modal = new bootstrap.Modal(modalElement);";
    echo "    modal.show();";
    echo "  } else {";
    echo "    console.error('Modal Element nicht gefunden!');";
    echo "  }";
}
echo "}";

echo "function debugElements() {";
echo "  console.log('=== Element Debug ===');";
echo "  console.log('Bootstrap verfügbar:', typeof bootstrap !== 'undefined');";
echo "  console.log('Modal Elemente:', document.querySelectorAll('.modal').length);";
echo "  console.log('Button Elemente:', document.querySelectorAll('[data-bs-toggle=\"modal\"]').length);";
if (!empty($pending_reservations)) {
    $test_reservation = $pending_reservations[0];
    echo "  console.log('Test Modal ID: detailsModal{$test_reservation['id']}');";
    echo "  console.log('Test Modal Element:', document.getElementById('detailsModal{$test_reservation['id']}'));";
    echo "  console.log('Test Button Element:', document.querySelector('[data-bs-target=\"#detailsModal{$test_reservation['id']}\"]'));";
}
echo "}";

echo "document.addEventListener('DOMContentLoaded', function() {";
echo "  console.log('DOM geladen - Dashboard Debug mit Daten');";
echo "  debugElements();";
echo "});";
echo "</script>";

echo "</body>";
echo "</html>";
?>
