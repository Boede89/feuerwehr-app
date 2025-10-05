<?php
/**
 * Debug: Dashboard 500-Fehler beheben
 */

// Alle Fehler anzeigen
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

echo "🔍 Dashboard 500-Fehler Debug<br><br>";

// 1. Prüfe Session
echo "1. Session prüfen:<br>";
session_start();
echo "Session gestartet: " . (session_status() === PHP_SESSION_ACTIVE ? '✅' : '❌') . "<br>";

// 2. Prüfe Datenbankverbindung
echo "<br>2. Datenbankverbindung prüfen:<br>";
try {
    require_once 'config/database.php';
    echo "Datenbankverbindung: ✅<br>";
} catch (Exception $e) {
    echo "Datenbankfehler: ❌ " . $e->getMessage() . "<br>";
    exit;
}

// 3. Prüfe Functions
echo "<br>3. Functions prüfen:<br>";
try {
    require_once 'includes/functions.php';
    echo "Functions geladen: ✅<br>";
} catch (Exception $e) {
    echo "Functions-Fehler: ❌ " . $e->getMessage() . "<br>";
    exit;
}

// 4. Prüfe can_approve_reservations Funktion
echo "<br>4. can_approve_reservations Funktion prüfen:<br>";
if (function_exists('can_approve_reservations')) {
    echo "Funktion existiert: ✅<br>";
    try {
        $result = can_approve_reservations();
        echo "Funktion funktioniert: ✅ (Rückgabe: " . ($result ? 'true' : 'false') . ")<br>";
    } catch (Exception $e) {
        echo "Funktion-Fehler: ❌ " . $e->getMessage() . "<br>";
    }
} else {
    echo "Funktion existiert nicht: ❌<br>";
}

// 5. Prüfe Session-Variablen
echo "<br>5. Session-Variablen prüfen:<br>";
echo "user_id: " . ($_SESSION['user_id'] ?? 'nicht gesetzt') . "<br>";
echo "role: " . ($_SESSION['role'] ?? 'nicht gesetzt') . "<br>";

// 6. Teste Reservierungen laden
echo "<br>6. Reservierungen laden testen:<br>";
try {
    $stmt = $db->prepare("
        SELECT r.*, v.name as vehicle_name
        FROM reservations r
        JOIN vehicles v ON r.vehicle_id = v.id
        ORDER BY r.created_at DESC
    ");
    $stmt->execute();
    $all_reservations = $stmt->fetchAll();
    echo "Reservierungen geladen: ✅ (" . count($all_reservations) . " gefunden)<br>";
} catch (Exception $e) {
    echo "Reservierungen-Fehler: ❌ " . $e->getMessage() . "<br>";
}

echo "<br>7. Teste array_filter:<br>";
try {
    $pending_reservations = array_filter($all_reservations, function($r) {
        return $r['status'] === 'pending';
    });
    echo "array_filter funktioniert: ✅ (" . count($pending_reservations) . " ausstehend)<br>";
} catch (Exception $e) {
    echo "array_filter-Fehler: ❌ " . $e->getMessage() . "<br>";
}

echo "<br>8. Teste HTML-Output:<br>";
try {
    echo "HTML-Test: ";
    echo htmlspecialchars("Test-String");
    echo " ✅<br>";
} catch (Exception $e) {
    echo "HTML-Fehler: ❌ " . $e->getMessage() . "<br>";
}

echo "<br>9. Teste date() Funktion:<br>";
try {
    $test_date = date('d.m.Y', strtotime('2024-01-01 10:00:00'));
    echo "date() funktioniert: ✅ ($test_date)<br>";
} catch (Exception $e) {
    echo "date()-Fehler: ❌ " . $e->getMessage() . "<br>";
}

echo "<br><strong>Debug abgeschlossen!</strong><br>";
echo "Zeitstempel: " . date('Y-m-d H:i:s') . "<br>";
?>
