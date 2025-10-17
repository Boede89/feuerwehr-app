<?php
require_once 'config/database.php';

try {
    $sql = file_get_contents('create-atemschutz-tables.sql');
    $db->exec($sql);
    echo "Atemschutz-Tabellen erfolgreich erstellt!\n";
} catch (Exception $e) {
    echo "Fehler: " . $e->getMessage() . "\n";
}
?>
