<?php
/**
 * CLI-Import fuer Alarmdepeschen (PDF-Anhaenge aus IMAP-Postfach).
 *
 * Beispiel:
 * php tools/import-alarmdepeschen-imap.php --host=imap.example.com --port=993 --user=foo@example.com --pass=secret --folder=INBOX --einheit-id=1
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
if (!function_exists('imap_open')) {
    fwrite(STDERR, "PHP-IMAP Erweiterung ist nicht installiert.\n");
    exit(1);
}

function arg_value(array $argv, string $name, string $default = ''): string {
    foreach ($argv as $arg) {
        if (strpos($arg, "--{$name}=") === 0) return substr($arg, strlen($name) + 3);
    }
    return $default;
}

function ensure_depesche_table(PDO $db): void {
    $db->exec("
        CREATE TABLE IF NOT EXISTS alarmdepesche_inbox (
            id INT AUTO_INCREMENT PRIMARY KEY,
            einheit_id INT NOT NULL DEFAULT 0,
            message_uid VARCHAR(191) NULL,
            subject VARCHAR(255) NOT NULL DEFAULT '',
            sender VARCHAR(255) NOT NULL DEFAULT '',
            received_at_utc DATETIME NOT NULL,
            filename_original VARCHAR(255) NOT NULL,
            storage_path VARCHAR(512) NOT NULL,
            sha256 VARCHAR(64) NULL,
            file_size_bytes BIGINT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_uid (message_uid),
            KEY idx_einheit_received (einheit_id, received_at_utc),
            KEY idx_received (received_at_utc)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function ensure_depesche_dir(): string {
    $dir = dirname(__DIR__) . '/uploads/alarmdepeschen';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir;
}

function decode_imap_part($data, int $encoding): string {
    switch ($encoding) {
        case 3: return base64_decode($data, true) ?: '';
        case 4: return quoted_printable_decode($data);
        default: return $data;
    }
}

function collect_pdf_parts($structure, string $prefix = ''): array {
    $out = [];
    if (!isset($structure->parts) || !is_array($structure->parts)) return $out;
    foreach ($structure->parts as $idx => $part) {
        $partNo = $prefix === '' ? (string)($idx + 1) : ($prefix . '.' . ($idx + 1));
        $isPdf = false;
        $filename = '';
        if (isset($part->subtype) && strtoupper((string)$part->subtype) === 'PDF') $isPdf = true;
        if (!empty($part->dparameters)) {
            foreach ($part->dparameters as $dp) {
                if (strtolower((string)$dp->attribute) === 'filename') $filename = (string)$dp->value;
            }
        }
        if (!empty($part->parameters)) {
            foreach ($part->parameters as $pp) {
                if (strtolower((string)$pp->attribute) === 'name') $filename = (string)$pp->value;
            }
        }
        if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'pdf') $isPdf = true;
        if ($isPdf) {
            $out[] = ['part_no' => $partNo, 'encoding' => (int)($part->encoding ?? 0), 'filename' => $filename ?: 'alarmdepesche.pdf'];
        }
        if (!empty($part->parts)) {
            $out = array_merge($out, collect_pdf_parts($part, $partNo));
        }
    }
    return $out;
}

$host = arg_value($argv, 'host', getenv('FAX_IMAP_HOST') ?: '');
$port = arg_value($argv, 'port', getenv('FAX_IMAP_PORT') ?: '993');
$user = arg_value($argv, 'user', getenv('FAX_IMAP_USER') ?: '');
$pass = arg_value($argv, 'pass', getenv('FAX_IMAP_PASS') ?: '');
$folder = arg_value($argv, 'folder', getenv('FAX_IMAP_FOLDER') ?: 'INBOX');
$einheitId = (int)arg_value($argv, 'einheit-id', getenv('FAX_EINHEIT_ID') ?: '0');

if ($host === '' || $user === '' || $pass === '') {
    fwrite(STDERR, "Fehlende IMAP Parameter (host/user/pass).\n");
    exit(1);
}

ensure_depesche_table($db);
$saveDir = ensure_depesche_dir();
$mailbox = sprintf('{%s:%d/imap/ssl/novalidate-cert}%s', $host, (int)$port, $folder);
$imap = @imap_open($mailbox, $user, $pass);
if (!$imap) {
    fwrite(STDERR, "IMAP Verbindung fehlgeschlagen.\n");
    exit(1);
}

$ids = imap_search($imap, 'UNSEEN') ?: [];
if (empty($ids)) {
    echo "Keine neuen Mails.\n";
    imap_close($imap);
    exit(0);
}

$inserted = 0;
foreach ($ids as $msgNo) {
    $overviewArr = imap_fetch_overview($imap, (string)$msgNo, 0);
    $overview = $overviewArr[0] ?? null;
    if (!$overview) continue;
    $uid = (string)imap_uid($imap, $msgNo);
    $subject = trim((string)($overview->subject ?? ''));
    $sender = trim((string)($overview->from ?? ''));
    $dateRaw = (string)($overview->date ?? '');
    $receivedTs = strtotime($dateRaw);
    if ($receivedTs === false) $receivedTs = time();
    $receivedUtc = gmdate('Y-m-d H:i:s', $receivedTs);

    $existsStmt = $db->prepare("SELECT id FROM alarmdepesche_inbox WHERE message_uid = ? LIMIT 1");
    $existsStmt->execute([$uid]);
    if ($existsStmt->fetchColumn()) {
        imap_setflag_full($imap, (string)$msgNo, "\\Seen");
        continue;
    }

    $structure = imap_fetchstructure($imap, $msgNo);
    if (!$structure) continue;
    $pdfParts = collect_pdf_parts($structure);
    if (empty($pdfParts)) {
        imap_setflag_full($imap, (string)$msgNo, "\\Seen");
        continue;
    }

    foreach ($pdfParts as $partInfo) {
        $partNo = (string)$partInfo['part_no'];
        $raw = imap_fetchbody($imap, $msgNo, $partNo);
        if (!is_string($raw) || $raw === '') continue;
        $binary = decode_imap_part($raw, (int)$partInfo['encoding']);
        if ($binary === '' || strpos($binary, '%PDF') !== 0) continue;

        $sha256 = hash('sha256', $binary);
        $filename = trim((string)$partInfo['filename']);
        if ($filename === '') $filename = 'alarmdepesche.pdf';
        $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($filename)) ?: 'alarmdepesche.pdf';
        $absPath = $saveDir . '/' . gmdate('Ymd_His', $receivedTs) . '_' . substr($sha256, 0, 10) . '_' . $safeName;
        if (@file_put_contents($absPath, $binary) === false) continue;

        $stmt = $db->prepare("
            INSERT INTO alarmdepesche_inbox
            (einheit_id, message_uid, subject, sender, received_at_utc, filename_original, storage_path, sha256, file_size_bytes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $einheitId,
            $uid,
            $subject,
            $sender,
            $receivedUtc,
            $filename,
            $absPath,
            $sha256,
            strlen($binary),
        ]);
        $inserted++;
    }
    imap_setflag_full($imap, (string)$msgNo, "\\Seen");
}

imap_close($imap);
echo "Import abgeschlossen. Neue PDFs: {$inserted}\n";

