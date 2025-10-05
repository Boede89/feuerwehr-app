<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || !has_admin_access()) {
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

try {
    $db->beginTransaction();
    $db->exec('SET FOREIGN_KEY_CHECKS=0');

    // Einfache naive Ausführung, getrennt an Semikolon
    $statements = array_filter(array_map('trim', preg_split('/;\s*\n|;\r?\n|;\s*$/m', $sql)));
    foreach ($statements as $stmtSql) {
        if ($stmtSql === '' || stripos($stmtSql, '--') === 0) continue;
        $db->exec($stmtSql);
    }

    $db->exec('SET FOREIGN_KEY_CHECKS=1');
    $db->commit();
    header('Location: settings-global.php?dbimport=success');
} catch (Exception $e) {
    $db->rollBack();
    http_response_code(500);
    echo 'Import-Fehler: ' . htmlspecialchars($e->getMessage());
}
?>


