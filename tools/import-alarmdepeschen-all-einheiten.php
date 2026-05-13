<?php
/**
 * Ruft fuer jede Einheit mit hinterlegtem alarmdepesche_imap_host den Python-IMAP-Import auf.
 * Voraussetzung: python3, pymysql (siehe README), tools/import-alarmdepeschen-imap.py
 *
 * Cron-Beispiele: siehe tools/cron-alarmdepeschen.example
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Nur fuer CLI.\n");
    exit(1);
}

require_once __DIR__ . '/../config/database.php';
if (!$db instanceof PDO) {
    fwrite(STDERR, "Datenbankverbindung nicht verfuegbar.\n");
    exit(1);
}

$root = realpath(__DIR__ . '/..');
$py = $root . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'import-alarmdepeschen-imap.py';
if (!is_file($py)) {
    fwrite(STDERR, "Fehlt: tools/import-alarmdepeschen-imap.py\n");
    exit(1);
}

try {
    $stmt = $db->query("
        SELECT DISTINCT einheit_id
        FROM einheit_settings
        WHERE setting_key = 'alarmdepesche_imap_host'
          AND TRIM(COALESCE(setting_value, '')) <> ''
        ORDER BY einheit_id ASC
    ");
    $ids = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
} catch (Throwable $e) {
    fwrite(STDERR, "Abfrage fehlgeschlagen: " . $e->getMessage() . "\n");
    exit(1);
}

$ids = array_values(array_unique(array_map('intval', is_array($ids) ? $ids : [])));
$ids = array_filter($ids, fn ($id) => $id > 0);

if ($ids === []) {
    echo "Keine Einheit mit alarmdepesche_imap_host – nichts zu tun.\n";
    exit(0);
}

$python = PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3';
$exitMax = 0;

foreach ($ids as $einheitId) {
    echo "--- Einheit {$einheitId} ---\n";
    $cmd = sprintf(
        '%s %s --einheit-id %d',
        escapeshellarg($python),
        escapeshellarg($py),
        $einheitId
    );
    passthru($cmd, $code);
    if ($code > $exitMax) {
        $exitMax = $code;
    }
}

exit($exitMax > 0 ? 1 : 0);
