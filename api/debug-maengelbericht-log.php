<?php
/**
 * Test: Schreibt in /tmp/feuerwehr-debug-maengelbericht.log
 * Aufruf: https://ihre-domain/api/debug-maengelbericht-log.php
 */
$log_file = '/tmp/feuerwehr-debug-maengelbericht.log';
$msg = date('Y-m-d H:i:s') . " TEST: Debug-Script aufgerufen\n";
$ok = @file_put_contents($log_file, $msg, FILE_APPEND | LOCK_EX);
header('Content-Type: text/plain; charset=UTF-8');
echo "Log-Datei: $log_file\n";
echo "Schreiben: " . ($ok !== false ? "OK ($ok Bytes)" : "FEHLER") . "\n";
echo "Datei existiert: " . (file_exists($log_file) ? "ja" : "nein") . "\n";
