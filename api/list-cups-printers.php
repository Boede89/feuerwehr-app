<?php
/**
 * Listet verfügbare CUPS-Drucker auf (lpstat -p).
 */
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=UTF-8');

if (!isset($_SESSION['user_id']) || !hasAdminPermission()) {
    echo json_encode(['success' => false, 'message' => 'Zugriff verweigert']);
    exit;
}

$printers = [];
$lpstat_path = '';
foreach (['/usr/bin/lpstat', '/usr/local/bin/lpstat', 'lpstat'] as $path) {
    if (strpos($path, '/') !== false) {
        if (is_executable($path)) {
            $lpstat_path = $path;
            break;
        }
    } else {
        $out = @shell_exec('which ' . escapeshellarg($path) . ' 2>/dev/null');
        if ($out && trim($out) !== '') {
            $lpstat_path = trim(explode("\n", $out)[0]);
            break;
        }
    }
}

if ($lpstat_path !== '') {
    $output = [];
    @exec(escapeshellarg($lpstat_path) . ' -p 2>/dev/null', $output);
    foreach ($output as $line) {
        if (preg_match('/^printer\s+(\S+)\s+/', trim($line), $m)) {
            $printers[] = ['name' => $m[1], 'display' => $m[1]];
        }
    }
}

echo json_encode([
    'success' => true,
    'printers' => $printers,
    'cups_available' => $lpstat_path !== '',
]);
