<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Prüfe ob Benutzer eingeloggt ist und Settings-Berechtigung hat
if (!isset($_SESSION['user_id']) || !has_permission('settings')) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

// Einfache SQL-Dump-Erstellung (Schema + Daten) für MySQL/MariaDB
// Hinweis: Für große Datenbanken besser mysqldump verwenden
try {
    $db->query('SET NAMES utf8mb4');
    $tables = [];
    $q = $db->query('SHOW TABLES');
    while ($row = $q->fetch(PDO::FETCH_NUM)) { $tables[] = $row[0]; }

    $dump = "-- Feuerwehr App SQL Export\n";
    $dump .= "-- Exported: " . date('c') . "\n\n";
    $dump .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

    foreach ($tables as $table) {
        // Schema
        $stmt = $db->query("SHOW CREATE TABLE `{$table}`");
        $create = $stmt->fetch(PDO::FETCH_ASSOC);
        $dump .= "DROP TABLE IF EXISTS `{$table}`;\n";
        $dump .= $create['Create Table'] . ";\n\n";

        // Daten
        $rows = $db->query("SELECT * FROM `{$table}`", PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $cols = array_map(fn($c) => "`" . str_replace("`","``",$c) . "`", array_keys($r));
            $vals = array_map(function($v) use ($db) {
                if (is_null($v)) return 'NULL';
                return $db->quote($v);
            }, array_values($r));
            $dump .= "INSERT INTO `{$table}` (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ");\n";
        }
        $dump .= "\n";
    }

    $dump .= "SET FOREIGN_KEY_CHECKS=1;\n";

    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="database-export-' . date('Ymd-His') . '.sql"');
    echo $dump;
} catch (Exception $e) {
    http_response_code(500);
    echo 'Export-Fehler: ' . htmlspecialchars($e->getMessage());
}
?>


