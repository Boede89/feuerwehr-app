<?php
/**
 * Debug: Zeigt den genauen Fehler beim Laden von anwesenheitsliste-eingaben.php
 * Nach dem Beheben des Fehlers diese Datei wieder löschen.
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '1');

echo "<pre style='background:#f5f5f5;padding:1rem;font-size:14px;'>";
echo "Schritt 1: Session start... ";
session_start();
echo "OK\n";

echo "Schritt 2: config/database.php... ";
try {
    require_once __DIR__ . '/config/database.php';
    echo "OK\n";
} catch (Throwable $e) {
    echo "FEHLER: " . $e->getMessage() . " in " . $e->getFile() . " Zeile " . $e->getLine() . "\n";
    echo $e->getTraceAsString();
    exit;
}

echo "Schritt 3: \$db vorhanden? ";
echo ($db ? "JA" : "NEIN (Verbindung fehlgeschlagen)") . "\n";
if (!$db) exit;

echo "Schritt 4: includes/functions.php... ";
try {
    require_once __DIR__ . '/includes/functions.php';
    echo "OK\n";
} catch (Throwable $e) {
    echo "FEHLER: " . $e->getMessage() . " in " . $e->getFile() . " Zeile " . $e->getLine() . "\n";
    echo $e->getTraceAsString();
    exit;
}

echo "Schritt 5: includes/dienstplan-typen.php... ";
try {
    require_once __DIR__ . '/includes/dienstplan-typen.php';
    echo "OK\n";
} catch (Throwable $e) {
    echo "FEHLER: " . $e->getMessage() . " in " . $e->getFile() . " Zeile " . $e->getLine() . "\n";
    exit;
}

echo "Schritt 6: Session user_id? ";
echo (isset($_SESSION['user_id']) ? "JA (" . (int)$_SESSION['user_id'] . ")" : "NEIN (bitte einloggen)") . "\n";

$_GET['datum'] = $_GET['datum'] ?? '2026-02-09';
$_GET['auswahl'] = $_GET['auswahl'] ?? 'einsatz';
$datum = trim($_GET['datum'] ?? '');
$auswahl = trim($_GET['auswahl'] ?? '');

echo "Schritt 7: datum=$datum auswahl=$auswahl\n";

echo "Schritt 8: Tabelle anwesenheitslisten existiert? ";
try {
    $db->query("SELECT 1 FROM anwesenheitslisten LIMIT 1");
    echo "JA\n";
} catch (Throwable $e) {
    echo "NEIN oder Fehler: " . $e->getMessage() . "\n";
}

echo "Schritt 9: SELECT members... ";
try {
    $stmt = $db->query("SELECT id, first_name, last_name FROM members ORDER BY last_name, first_name LIMIT 1");
    $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "OK\n";
} catch (Throwable $e) {
    echo "FEHLER: " . $e->getMessage() . "\n";
}

echo "\n-- Wenn alle Schritte OK sind, liegt der Fehler woanders in anwesenheitsliste-eingaben.php\n";
echo "-- Rufen Sie nun die echte Seite auf: anwesenheitsliste-eingaben.php?datum=$datum&auswahl=$auswahl\n";
echo "</pre>";
