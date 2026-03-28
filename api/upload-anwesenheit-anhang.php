<?php
/**
 * Liest Anhänge für die Anwesenheitsliste sofort in den Session-Entwurf (anhaenge_temp).
 */
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/bericht-anhaenge-helper.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Nicht angemeldet']);
    exit;
}

$draft_key = 'anwesenheit_draft';
if (empty($_SESSION[$draft_key]) || !is_array($_SESSION[$draft_key])) {
    echo json_encode(['success' => false, 'message' => 'Kein Entwurf']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$draft = &$_SESSION[$draft_key];
if (!isset($draft['anhaenge_temp']) || !is_array($draft['anhaenge_temp'])) {
    $draft['anhaenge_temp'] = [];
}

$fileField = $_FILES['anwesenheitsliste_anhaenge'] ?? null;
if ($fileField === null && isset($_FILES['anwesenheitsliste_anhaenge[]'])) {
    $fileField = $_FILES['anwesenheitsliste_anhaenge[]'];
}
if (empty($fileField['name'])) {
    echo json_encode(['success' => false, 'message' => 'Keine Datei']);
    exit;
}

$existing = $draft['anhaenge_temp'];
$remaining = BERICHT_ANHAENGE_MAX_FILES - count($existing);
if ($remaining <= 0) {
    echo json_encode(['success' => false, 'message' => 'Maximale Anzahl Anhänge erreicht']);
    exit;
}

$batch = bericht_anhaenge_normalize_files_array($fileField);
$batch = array_slice($batch, 0, $remaining);
$saved = bericht_anhaenge_list_draft_save_uploads_normalized($batch, $user_id, 'al');
if (empty($saved)) {
    $errs = $fileField['error'] ?? null;
    $firstErr = is_array($errs) ? reset($errs) : $errs;
    $firstErr = (int)$firstErr;
    $hint = '';
    if ($firstErr === UPLOAD_ERR_INI_SIZE || $firstErr === UPLOAD_ERR_FORM_SIZE) {
        $hint = ' Die Datei übersteigt das Server-Limit (upload_max_filesize / post_max_size).';
    } elseif ($firstErr === UPLOAD_ERR_PARTIAL) {
        $hint = ' Die Übertragung war unvollständig – bitte erneut versuchen.';
    } elseif ($firstErr === UPLOAD_ERR_NO_TMP_DIR || $firstErr === UPLOAD_ERR_CANT_WRITE) {
        $hint = ' Server konnte die temporäre Datei nicht speichern – Administrator informieren.';
    }
    echo json_encode(['success' => false, 'message' => 'Upload fehlgeschlagen (Dateityp JPG/PNG/WebP/GIF/PDF und max. ca. 12 MB; bei Fotos ggf. erneut versuchen).' . $hint]);
    exit;
}

$draft['anhaenge_temp'] = array_merge($existing, $saved);
echo json_encode(['success' => true, 'added' => $saved, 'items' => $draft['anhaenge_temp']]);
