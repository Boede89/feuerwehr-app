<?php
/**
 * Entfernt einen temporären Anwesenheitsliste-Anhang aus dem Entwurf und löscht die Datei.
 */
session_start();
require_once __DIR__ . '/../config/database.php';

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
$path = isset($_POST['path']) ? trim((string)$_POST['path']) : '';
$path = str_replace(['..', '\\'], ['', '/'], $path);
$path = ltrim($path, '/');

$expected = 'bericht_anhaenge_draft/' . $user_id . '/';
if ($path === '' || strncmp($path, $expected, strlen($expected)) !== 0) {
    echo json_encode(['success' => false, 'message' => 'Ungültiger Pfad']);
    exit;
}

$draft = &$_SESSION[$draft_key];
$list = $draft['anhaenge_temp'] ?? [];
if (!is_array($list)) {
    $list = [];
}

$found = false;
$newList = [];
foreach ($list as $it) {
    $p = $it['path'] ?? '';
    if ($p === $path) {
        $found = true;
        continue;
    }
    $newList[] = $it;
}

if (!$found) {
    echo json_encode(['success' => false, 'message' => 'Anhang nicht gefunden']);
    exit;
}

$draft['anhaenge_temp'] = $newList;
$abs = dirname(__DIR__) . '/uploads/' . $path;
if (is_file($abs)) {
    @unlink($abs);
}

echo json_encode(['success' => true, 'items' => $draft['anhaenge_temp']]);
