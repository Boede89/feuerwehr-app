<?php
echo "DOCUMENT_ROOT=" . ($_SERVER['DOCUMENT_ROOT'] ?? 'unset') . "\n";
echo "SCRIPT_FILENAME=" . ($_SERVER['SCRIPT_FILENAME'] ?? 'unset') . "\n";
echo "__DIR__=" . __DIR__ . "\n";
echo "CWD=" . getcwd() . "\n";
echo "FILES_HERE:\n";
foreach (scandir(__DIR__) as $f) { echo " - $f\n"; }
