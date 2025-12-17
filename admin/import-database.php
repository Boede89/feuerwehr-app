<?php
// Fehlerausgabe aktivieren für Debugging
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Prüfe ob Benutzer eingeloggt ist und Settings-Berechtigung hat
if (!isset($_SESSION['user_id']) || !has_permission('settings')) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method Not Allowed';
    exit;
}

if (!isset($_FILES['database_file']) || $_FILES['database_file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo 'Keine Datei hochgeladen.';
    exit;
}

$sql = file_get_contents($_FILES['database_file']['tmp_name']);
if ($sql === false || trim($sql) === '') {
    http_response_code(400);
    echo 'Leere oder ungültige SQL-Datei.';
    exit;
}

// Session-Daten speichern bevor die Datenbank überschrieben wird
$saved_session_data = $_SESSION;

try {
    // Foreign Key Checks deaktivieren
    $db->exec('SET FOREIGN_KEY_CHECKS=0');

    // SQL-Statements einzeln ausführen (ohne Transaktion, da DDL-Statements diese sowieso committen)
    $statements = array_filter(array_map('trim', preg_split('/;\s*\n|;\r?\n|;\s*$/m', $sql)));
    $executed = 0;
    
    foreach ($statements as $stmtSql) {
        // Leere Statements und Kommentare überspringen
        if ($stmtSql === '' || stripos($stmtSql, '--') === 0 || stripos($stmtSql, '/*') === 0) {
            continue;
        }
        $db->exec($stmtSql);
        $executed++;
    }

    // Foreign Key Checks wieder aktivieren
    $db->exec('SET FOREIGN_KEY_CHECKS=1');
    
    // Session-Daten wiederherstellen
    $_SESSION = $saved_session_data;
    session_write_close();
    
    header('Location: settings-backup.php?dbimport=success&count=' . $executed);
    exit;
    
} catch (Exception $e) {
    // Foreign Key Checks sicherheitshalber wieder aktivieren
    try {
        $db->exec('SET FOREIGN_KEY_CHECKS=1');
    } catch (Exception $ignored) {}
    
    error_log('Datenbank-Import Fehler: ' . $e->getMessage());
    
    // Session-Daten wiederherstellen auch bei Fehler
    $_SESSION = $saved_session_data;
    
    // Weiterleitung mit Fehlermeldung
    header('Location: settings-backup.php?dbimport=error&msg=' . urlencode($e->getMessage()));
    exit;
}
?>


