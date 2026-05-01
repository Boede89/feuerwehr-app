<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    http_response_code(401);
    echo 'Nicht eingeloggt.';
    exit;
}
if (!hasAdminPermission()) {
    http_response_code(403);
    echo 'Keine Berechtigung.';
    exit;
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo 'Ungueltige ID.';
    exit;
}

try {
    $stmt = $db->prepare("SELECT filename_original, storage_path FROM alarmdepesche_inbox WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        http_response_code(404);
        echo 'Depesche nicht gefunden.';
        exit;
    }
    $path = (string)($row['storage_path'] ?? '');
    $filename = trim((string)($row['filename_original'] ?? 'alarmdepesche.pdf'));
    if ($path === '' || !is_file($path)) {
        http_response_code(404);
        echo 'Datei nicht verfuegbar.';
        exit;
    }
    header('Content-Type: application/pdf');
    header('Content-Length: ' . (string)filesize($path));
    header('Content-Disposition: inline; filename="' . str_replace('"', '', basename($filename)) . '"');
    readfile($path);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Fehler beim Laden der Depesche.';
}

