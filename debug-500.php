<?php
/**
 * Temporäre Debug-Datei – zeigt PHP-Fehler an.
 * Nach dem Test wieder löschen!
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

echo "<h2>Debug: Schritt für Schritt</h2>";

try {
    echo "<p>1. Config laden...</p>";
    require_once __DIR__ . '/config/database.php';
    echo "<p style='color:green'>✓ Config OK</p>";
} catch (Throwable $e) {
    echo "<p style='color:red'>✗ Config: " . $e->getMessage() . "</p>";
    exit;
}

try {
    echo "<p>2. Functions laden...</p>";
    require_once __DIR__ . '/includes/functions.php';
    echo "<p style='color:green'>✓ Functions OK</p>";
} catch (Throwable $e) {
    echo "<p style='color:red'>✗ Functions: " . $e->getMessage() . "</p>";
    exit;
}

try {
    echo "<p>3. Einheiten-Setup laden...</p>";
    require_once __DIR__ . '/includes/einheiten-setup.php';
    echo "<p style='color:green'>✓ Einheiten-Setup OK</p>";
} catch (Throwable $e) {
    echo "<p style='color:red'>✗ Einheiten-Setup: " . $e->getMessage() . "</p>";
    exit;
}

try {
    echo "<p>4. Einheiten abfragen...</p>";
    $stmt = $db->query("SELECT id, name FROM einheiten WHERE is_active = 1");
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    echo "<p style='color:green'>✓ Einheiten: " . count($rows) . " gefunden</p>";
} catch (Throwable $e) {
    echo "<p style='color:red'>✗ Einheiten-Abfrage: " . $e->getMessage() . "</p>";
    exit;
}

echo "<p style='color:green;font-weight:bold'>Alle Checks OK. Wenn index.php trotzdem 500 zeigt, liegt der Fehler woanders.</p>";
echo "<p><a href='index.php'>→ Zur Startseite</a></p>";
