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

if (empty($_FILES['anwesenheitsliste_anhaenge']['name'])) {
    echo json_encode(['success' => false, 'message' => 'Keine Datei']);
    exit;
}

$existing = $draft['anhaenge_temp'];
$remaining = BERICHT_ANHAENGE_MAX_FILES - count($existing);
if ($remaining <= 0) {
    echo json_encode(['success' => false, 'message' => 'Maximale Anzahl Anhänge erreicht']);
    exit;
}

$batch = bericht_anhaenge_normalize_files_array($_FILES['anwesenheitsliste_anhaenge']);
$batch = array_slice($batch, 0, $remaining);
$saved = bericht_anhaenge_list_draft_save_uploads_normalized($batch, $user_id, 'al');
if (empty($saved)) {
    echo json_encode(['success' => false, 'message' => 'Upload fehlgeschlagen (Dateityp oder Größe prüfen, max. ca. 12 MB)']);
    exit;
}

$draft['anhaenge_temp'] = array_merge($existing, $saved);
echo json_encode(['success' => true, 'added' => $saved, 'items' => $draft['anhaenge_temp']]);
