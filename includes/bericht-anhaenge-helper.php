<?php
/**
 * Dateianhänge für Anwesenheitslisten und Mängelberichte (Speicherung + DB).
 */

defined('BERICHT_ANHAENGE_MAX_BYTES') || define('BERICHT_ANHAENGE_MAX_BYTES', 12 * 1024 * 1024);
defined('BERICHT_ANHAENGE_MAX_FILES') || define('BERICHT_ANHAENGE_MAX_FILES', 15);

function bericht_anhaenge_base_upload_dir(): string {
    return dirname(__DIR__) . '/uploads/bericht_anhaenge';
}

function bericht_anhaenge_draft_dir(int $user_id): string {
    return dirname(__DIR__) . '/uploads/bericht_anhaenge_draft/' . $user_id;
}

/**
 * Erkennt erlaubten Bild-/PDF-Typ anhand Dateiinhalt (falls finfo/Browser unzuverlässig sind).
 */
function bericht_anhaenge_sniff_allowed_mime(string $tmpPath): ?string {
    $h = @file_get_contents($tmpPath, false, null, 0, 32);
    if ($h === false || $h === '') {
        return null;
    }
    if (strncmp($h, "\xff\xd8\xff", 3) === 0) {
        return 'image/jpeg';
    }
    if (strlen($h) >= 8 && strncmp($h, "\x89PNG\r\n\x1a\n", 8) === 0) {
        return 'image/png';
    }
    if (strlen($h) >= 12 && substr($h, 0, 4) === 'RIFF' && substr($h, 8, 4) === 'WEBP') {
        return 'image/webp';
    }
    if (strlen($h) >= 6 && (strncmp($h, 'GIF87a', 6) === 0 || strncmp($h, 'GIF89a', 6) === 0)) {
        return 'image/gif';
    }
    if (strncmp($h, '%PDF', 4) === 0) {
        return 'application/pdf';
    }
    // ISO-BMFF (u. a. Apple HEIC/HEIF)
    if (strlen($h) >= 12 && substr($h, 4, 4) === 'ftyp') {
        $brand = substr($h, 8, 4);
        if (in_array($brand, ['heic', 'heix', 'hevc', 'hevx', 'mif1', 'msf1'], true)) {
            return 'image/heic';
        }
        if ($brand === 'heif') {
            return 'image/heif';
        }
    }
    return null;
}

function bericht_anhaenge_normalize_detected_mime(string $mime): string {
    $m = strtolower(trim($mime));
    $aliases = [
        'image/jpg' => 'image/jpeg',
        'image/pjpeg' => 'image/jpeg',
        'image/x-jpeg' => 'image/jpeg',
        'image/x-png' => 'image/png',
        'image/x-webp' => 'image/webp',
        'application/x-pdf' => 'application/pdf',
        'image/heif-sequence' => 'image/heif',
        'image/heic-sequence' => 'image/heic',
    ];
    return $aliases[$m] ?? $m;
}

function bericht_anhaenge_allowed_mime_from_file(string $tmpPath, string $fallbackMime = ''): ?string {
    if (!is_file($tmpPath) || !is_readable($tmpPath)) {
        return null;
    }
    $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf', 'image/heic', 'image/heif'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $detected = $finfo->file($tmpPath);
    if (is_string($detected)) {
        $detected = bericht_anhaenge_normalize_detected_mime($detected);
        if (in_array($detected, $allowed, true)) {
            return $detected;
        }
    }
    $fb = bericht_anhaenge_normalize_detected_mime($fallbackMime);
    if ($fb !== '' && in_array($fb, $allowed, true)) {
        return $fb;
    }
    $sniff = bericht_anhaenge_sniff_allowed_mime($tmpPath);
    if ($sniff !== null && in_array($sniff, $allowed, true)) {
        return $sniff;
    }
    return null;
}

function bericht_anhaenge_safe_orig_name(string $name): string {
    $name = basename(str_replace(["\0", '/'], '', $name));
    return substr($name, 0, 220);
}

function bericht_anhaenge_file_extension_for_mime(string $mime): string {
    static $map = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'application/pdf' => 'pdf',
        'image/heic' => 'heic',
        'image/heif' => 'heif',
    ];
    return $map[$mime] ?? 'bin';
}

/**
 * Pfad so auflösen, dass is_uploaded_file() unter Windows/Hypervisor zuverlässiger greift.
 */
function bericht_anhaenge_resolve_upload_tmp_path(string $tmpFromPost): string {
    if ($tmpFromPost === '') {
        return '';
    }
    if (@is_uploaded_file($tmpFromPost)) {
        return $tmpFromPost;
    }
    $rp = @realpath($tmpFromPost);
    if ($rp !== false && @is_uploaded_file($rp)) {
        return $rp;
    }
    $norm = str_replace('/', DIRECTORY_SEPARATOR, $tmpFromPost);
    if ($norm !== $tmpFromPost && @is_uploaded_file($norm)) {
        return $norm;
    }
    return $tmpFromPost;
}

/**
 * move_uploaded_file mit copy-Fallback (z. B. Temp- und Zielordner auf verschiedenen Laufwerken).
 */
function bericht_anhaenge_move_uploaded_to(string $tmpPath, string $destinationAbs): bool {
    if (@move_uploaded_file($tmpPath, $destinationAbs)) {
        return true;
    }
    if (@is_uploaded_file($tmpPath) && @is_readable($tmpPath) && @copy($tmpPath, $destinationAbs)) {
        @unlink($tmpPath);
        return true;
    }
    return false;
}

/**
 * Kurzer deutscher Hinweis nach internem Ablehnungs-Code (für API-Antworten).
 */
function bericht_anhaenge_upload_reject_message(?string $code): string {
    switch ($code) {
        case 'empty_batch':
            return 'Keine verwertbare Datei nach dem Upload (evt. Größe 0 oder Übertragungsfehler).';
        case 'not_uploaded_file':
            return 'Die temporäre Upload-Datei wurde vom Server nicht akzeptiert. Bitte Hoster prüfen: upload_tmp_dir, open_basedir, genug Speicher. Auf einem anderen Browser/Endgerät testen.';
        case 'size':
            return 'Datei ist leer oder größer als das erlaubte Limit.';
        case 'mime':
            return 'Dateityp wird nicht unterstützt (erlaubt: JPG, PNG, WebP, GIF, PDF, ggf. HEIC/HEIF vom iPhone).';
        case 'move_failed':
        case 'mkdir_failed':
        case 'uploads_not_writable':
            return 'Der Ordner „uploads“ konnte nicht beschrieben werden (Rechte auf dem Webserver prüfen).';
        case 'bad_user':
            return 'Ungültige Benutzer-Session.';
        case 'unknown':
            return 'Upload wurde aus unbekanntem Grund abgelehnt.';
        default:
            return '';
    }
}

function bericht_anhaenge_ensure_table(PDO $db): void {
    $db->exec("
        CREATE TABLE IF NOT EXISTS bericht_anhaenge (
            id INT AUTO_INCREMENT PRIMARY KEY,
            entity_type VARCHAR(32) NOT NULL,
            entity_id INT NOT NULL,
            filename_original VARCHAR(255) NOT NULL,
            storage_path VARCHAR(512) NOT NULL,
            mime_type VARCHAR(120) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_entity (entity_type, entity_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

/**
 * @return array<int, array{filename_original:string,storage_path:string,mime_type:string}>
 */
function bericht_anhaenge_fetch_for_entity(PDO $db, string $entity_type, int $entity_id): array {
    if ($entity_id <= 0) {
        return [];
    }
    try {
        bericht_anhaenge_ensure_table($db);
        $stmt = $db->prepare('SELECT filename_original, storage_path, mime_type FROM bericht_anhaenge WHERE entity_type = ? AND entity_id = ? ORDER BY sort_order ASC, id ASC');
        $stmt->execute([$entity_type, $entity_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {
        return [];
    }
}

function bericht_anhaenge_abs_path(string $storage_path): string {
    return bericht_anhaenge_base_upload_dir() . '/' . $storage_path;
}

/**
 * @param array<int, array{tmp:string,name:string,size?:int,type?:string}> $uploaded
 * @return array<int, array{path:string,orig:string,mime:string}> Relativ zu bericht_anhaenge_base_upload_dir() ohne Basis
 */
function bericht_anhaenge_store_files(PDO $db, string $entity_type, int $entity_id, array $uploaded): array {
    if ($entity_id <= 0 || empty($uploaded)) {
        return [];
    }
    bericht_anhaenge_ensure_table($db);
    $stmt = $db->prepare('INSERT INTO bericht_anhaenge (entity_type, entity_id, filename_original, storage_path, mime_type, sort_order) VALUES (?, ?, ?, ?, ?, ?)');
    $saved = [];
    $sort = 0;
    $subdir = preg_replace('/[^a-z0-9_\-]/i', '_', $entity_type) . '/' . $entity_id;
    $destDir = bericht_anhaenge_base_upload_dir() . '/' . $subdir;
    if (!is_dir($destDir)) {
        mkdir($destDir, 0755, true);
    }
    foreach ($uploaded as $item) {
        if (count($saved) >= BERICHT_ANHAENGE_MAX_FILES) {
            break;
        }
        $tmp = $item['tmp'] ?? '';
        $name = bericht_anhaenge_safe_orig_name($item['name'] ?? 'datei');
        $tmpResolved = bericht_anhaenge_resolve_upload_tmp_path($tmp);
        if ($tmpResolved === '' || !is_uploaded_file($tmpResolved)) {
            continue;
        }
        $size = (int)($item['size'] ?? filesize($tmpResolved));
        if ($size <= 0 || $size > BERICHT_ANHAENGE_MAX_BYTES) {
            continue;
        }
        $mime = bericht_anhaenge_allowed_mime_from_file($tmpResolved, (string)($item['type'] ?? ''));
        if ($mime === null) {
            continue;
        }
        $ext = bericht_anhaenge_file_extension_for_mime($mime);
        $stored = $subdir . '/' . bin2hex(random_bytes(16)) . '.' . $ext;
        $abs = bericht_anhaenge_base_upload_dir() . '/' . $stored;
        if (!bericht_anhaenge_move_uploaded_to($tmpResolved, $abs)) {
            continue;
        }
        $sort++;
        $stmt->execute([$entity_type, $entity_id, $name, $stored, $mime, $sort]);
        $saved[] = ['path' => $stored, 'orig' => $name, 'mime' => $mime];
    }
    return $saved;
}

/**
 * Normalisiert ein einzelnes File-Input-Feld (name wie anwesenheitsliste_anhaenge[]).
 *
 * @return array<int, array{tmp:string,name:string,size:int,type:string}>
 */
function bericht_anhaenge_normalize_files_array(array $fileField): array {
    $out = [];
    if (empty($fileField['name'])) {
        return $out;
    }
    if (is_array($fileField['name'])) {
        foreach ($fileField['name'] as $i => $name) {
            if (($fileField['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                continue;
            }
            $out[] = [
                'tmp' => $fileField['tmp_name'][$i],
                'name' => (string)$name,
                'size' => (int)($fileField['size'][$i] ?? 0),
                'type' => (string)($fileField['type'][$i] ?? ''),
            ];
        }
    } else {
        if (($fileField['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return $out;
        }
        $out[] = [
            'tmp' => $fileField['tmp_name'],
            'name' => (string)$fileField['name'],
            'size' => (int)($fileField['size'] ?? 0),
            'type' => (string)($fileField['type'] ?? ''),
        ];
    }
    return $out;
}

/**
 * Speichert hochgeladene Anhänge für die Anwesenheitsliste (Formularfeld anwesenheitsliste_anhaenge).
 */
function bericht_anhaenge_save_for_anwesenheitsliste(PDO $db, int $list_id, ?array $filesGlobal): void {
    if ($list_id <= 0 || empty($filesGlobal['anwesenheitsliste_anhaenge']['name'])) {
        return;
    }
    $batch = bericht_anhaenge_normalize_files_array($filesGlobal['anwesenheitsliste_anhaenge']);
    bericht_anhaenge_store_files($db, 'anwesenheitsliste', $list_id, $batch);
}

function bericht_anhaenge_save_for_maengelbericht(PDO $db, int $maengel_id, ?array $filesGlobal): void {
    if ($maengel_id <= 0 || empty($filesGlobal['maengelbericht_anhaenge']['name'])) {
        return;
    }
    $batch = bericht_anhaenge_normalize_files_array($filesGlobal['maengelbericht_anhaenge']);
    bericht_anhaenge_store_files($db, 'maengelbericht', $maengel_id, $batch);
}

/**
 * Übernimmt temporäre Dateien aus dem Entwurf (relativ zu bericht_anhaenge_base_upload_dir oder voller Pfad unter draft).
 *
 * @param array<int, array{path:string,orig:string,mime:string}> $tempMeta
 */
function bericht_anhaenge_commit_draft_files(PDO $db, string $entity_type, int $entity_id, array $tempMeta): void {
    if ($entity_id <= 0 || empty($tempMeta)) {
        return;
    }
    bericht_anhaenge_ensure_table($db);
    $stmt = $db->prepare('INSERT INTO bericht_anhaenge (entity_type, entity_id, filename_original, storage_path, mime_type, sort_order) VALUES (?, ?, ?, ?, ?, ?)');
    $stmtMax = $db->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM bericht_anhaenge WHERE entity_type = ? AND entity_id = ?');
    $stmtMax->execute([$entity_type, $entity_id]);
    $sort = (int)$stmtMax->fetchColumn();
    $subdir = preg_replace('/[^a-z0-9_\-]/i', '_', $entity_type) . '/' . $entity_id;
    $destDir = bericht_anhaenge_base_upload_dir() . '/' . $subdir;
    if (!is_dir($destDir)) {
        mkdir($destDir, 0755, true);
    }
    foreach ($tempMeta as $item) {
        $sort++;
        $path = $item['path'] ?? '';
        $orig = bericht_anhaenge_safe_orig_name($item['orig'] ?? 'datei');
        $mime = $item['mime'] ?? 'application/octet-stream';
        if ($path === '') {
            continue;
        }
        $absSrc = $path;
        if ($path[0] !== '/' && !preg_match('#^[a-z]:[\\\\/]#i', $path)) {
            $absSrc = dirname(__DIR__) . '/uploads/' . str_replace('..', '', $path);
        }
        if (!is_file($absSrc) || !is_readable($absSrc)) {
            continue;
        }
        $ext = bericht_anhaenge_file_extension_for_mime($mime);
        $stored = $subdir . '/' . bin2hex(random_bytes(16)) . '.' . $ext;
        $absDest = bericht_anhaenge_base_upload_dir() . '/' . $stored;
        if (!@copy($absSrc, $absDest)) {
            continue;
        }
        @unlink($absSrc);
        $stmt->execute([$entity_type, $entity_id, $orig, $stored, $mime, $sort]);
    }
}

/**
 * Speichert neue Dateien für einen Mängel-Entwurfsblock unter draft/ und gibt Metadaten zurück.
 *
 * @return array<int, array{path:string,orig:string,mime:string}> Pfade relativ zu /uploads/ (beginnt mit bericht_anhaenge_draft/...)
 */
function bericht_anhaenge_maengel_draft_save_uploads(array $filesPost, int $blockIndex, int $user_id): array {
    if ($user_id <= 0 || empty($filesPost['maengel']['name'][$blockIndex]['anhaenge'])) {
        return [];
    }
    $dir = bericht_anhaenge_draft_dir($user_id);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $names = $filesPost['maengel']['name'][$blockIndex]['anhaenge'];
    $tmps = $filesPost['maengel']['tmp_name'][$blockIndex]['anhaenge'];
    $errs = $filesPost['maengel']['error'][$blockIndex]['anhaenge'];
    $sizes = $filesPost['maengel']['size'][$blockIndex]['anhaenge'];
    $types = $filesPost['maengel']['type'][$blockIndex]['anhaenge'];
    if (!is_array($names)) {
        $names = [$names];
        $tmps = [$tmps];
        $errs = [$errs];
        $sizes = [$sizes];
        $types = [$types];
    }
    $out = [];
    foreach ($names as $i => $name) {
        if (count($out) >= BERICHT_ANHAENGE_MAX_FILES) {
            break;
        }
        if (($errs[$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            continue;
        }
        $tmp = bericht_anhaenge_resolve_upload_tmp_path((string)($tmps[$i] ?? ''));
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            continue;
        }
        $size = (int)($sizes[$i] ?? 0);
        if ($size <= 0 || $size > BERICHT_ANHAENGE_MAX_BYTES) {
            continue;
        }
        $mime = bericht_anhaenge_allowed_mime_from_file($tmp, (string)($types[$i] ?? ''));
        if ($mime === null) {
            continue;
        }
        $ext = bericht_anhaenge_file_extension_for_mime($mime);
        $storedRel = 'bericht_anhaenge_draft/' . $user_id . '/' . bin2hex(random_bytes(12)) . '_' . $blockIndex . '.' . $ext;
        $abs = dirname(__DIR__) . '/uploads/' . $storedRel;
        $par = dirname($abs);
        if (!is_dir($par)) {
            mkdir($par, 0755, true);
        }
        if (!bericht_anhaenge_move_uploaded_to($tmp, $abs)) {
            continue;
        }
        $out[] = ['path' => $storedRel, 'orig' => bericht_anhaenge_safe_orig_name((string)$name), 'mime' => $mime];
    }
    return $out;
}

/**
 * Löscht temporäre Anhang-Dateien eines Entwurfs (Anwesenheitsliste auf oberster Ebene + Mängelblöcke).
 */
function bericht_anhaenge_draft_cleanup_files(array $draft): void {
    $paths = [];
    if (!empty($draft['anhaenge_temp']) && is_array($draft['anhaenge_temp'])) {
        foreach ($draft['anhaenge_temp'] as $it) {
            if (!empty($it['path']) && is_string($it['path'])) {
                $paths[] = $it['path'];
            }
        }
    }
    if (!empty($draft['maengel']) && is_array($draft['maengel'])) {
        foreach ($draft['maengel'] as $m) {
            if (!empty($m['anhaenge_temp']) && is_array($m['anhaenge_temp'])) {
                foreach ($m['anhaenge_temp'] as $it) {
                    if (!empty($it['path']) && is_string($it['path'])) {
                        $paths[] = $it['path'];
                    }
                }
            }
        }
    }
    $base = dirname(__DIR__) . '/uploads/';
    foreach ($paths as $rel) {
        $rel = str_replace('..', '', $rel);
        $abs = $base . str_replace('\\', '/', ltrim($rel, '/'));
        if (is_file($abs)) {
            @unlink($abs);
        }
    }
}

/**
 * Speichert Hochladungen für die Anwesenheitsliste (Entwurf) unter uploads/bericht_anhaenge_draft/{user_id}/.
 *
 * @return array<int, array{path:string,orig:string,mime:string}>
 */
function bericht_anhaenge_list_draft_save_uploads(array $filesPost, int $user_id): array {
    if ($user_id <= 0 || empty($filesPost['anwesenheitsliste_anhaenge']['name'])) {
        return [];
    }
    return bericht_anhaenge_list_draft_save_uploads_normalized(
        bericht_anhaenge_normalize_files_array($filesPost['anwesenheitsliste_anhaenge']),
        $user_id,
        'al'
    );
}

/**
 * @param array<int, array{tmp:string,name:string,size?:int,type?:string}> $batch
 */
function bericht_anhaenge_list_draft_save_uploads_normalized(array $batch, int $user_id, string $prefix = 'al', &$failReason = null): array {
    $failReason = null;
    if ($user_id <= 0) {
        $failReason = 'bad_user';
        return [];
    }
    if (empty($batch)) {
        $failReason = 'empty_batch';
        return [];
    }
    $dir = bericht_anhaenge_draft_dir($user_id);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        $failReason = 'mkdir_failed';
        return [];
    }
    if (!is_writable($dir)) {
        $failReason = 'uploads_not_writable';
        return [];
    }
    $out = [];
    $lastReject = null;
    foreach ($batch as $item) {
        if (count($out) >= BERICHT_ANHAENGE_MAX_FILES) {
            break;
        }
        $tmp = bericht_anhaenge_resolve_upload_tmp_path((string)($item['tmp'] ?? ''));
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            $lastReject = 'not_uploaded_file';
            continue;
        }
        $size = (int)($item['size'] ?? (is_file($tmp) ? filesize($tmp) : 0));
        if ($size <= 0 || $size > BERICHT_ANHAENGE_MAX_BYTES) {
            $lastReject = 'size';
            continue;
        }
        $mime = bericht_anhaenge_allowed_mime_from_file($tmp, (string)($item['type'] ?? ''));
        if ($mime === null) {
            $lastReject = 'mime';
            continue;
        }
        $ext = bericht_anhaenge_file_extension_for_mime($mime);
        $storedRel = 'bericht_anhaenge_draft/' . $user_id . '/' . bin2hex(random_bytes(12)) . '_' . preg_replace('/[^a-z0-9_]/i', '', $prefix) . '.' . $ext;
        $abs = dirname(__DIR__) . '/uploads/' . $storedRel;
        $par = dirname($abs);
        if (!is_dir($par) && !@mkdir($par, 0755, true)) {
            $lastReject = 'mkdir_failed';
            continue;
        }
        if (!bericht_anhaenge_move_uploaded_to($tmp, $abs)) {
            $lastReject = 'move_failed';
            continue;
        }
        $out[] = ['path' => $storedRel, 'orig' => bericht_anhaenge_safe_orig_name($item['name'] ?? 'datei'), 'mime' => $mime];
    }
    if (empty($out)) {
        $failReason = $lastReject ?? 'unknown';
    }
    return $out;
}
