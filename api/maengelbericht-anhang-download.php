<?php
/**
 * Gespeicherten Anhang eines Mängelberichts ausliefern (Formularcenter / Bearbeiten).
 */
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bericht-anhaenge-helper.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('HTTP/1.1 403 Forbidden');
    echo 'Zugriff verweigert';
    exit;
}
if (!has_permission('forms')) {
    header('HTTP/1.1 403 Forbidden');
    echo 'Zugriff verweigert';
    exit;
}

$maengel_id = (int)($_GET['maengel_id'] ?? 0);
$anhang_id = (int)($_GET['id'] ?? 0);
if ($maengel_id <= 0 || $anhang_id <= 0) {
    header('HTTP/1.1 400 Bad Request');
    echo 'Ungültige Parameter';
    exit;
}

$row = null;
try {
    bericht_anhaenge_ensure_table($db);
    $stmt = $db->prepare('SELECT filename_original, storage_path, mime_type FROM bericht_anhaenge WHERE id = ? AND entity_type = ? AND entity_id = ?');
    $stmt->execute([$anhang_id, 'maengelbericht', $maengel_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $row = null;
}
if (!$row) {
    header('HTTP/1.1 404 Not Found');
    echo 'Anhang nicht gefunden';
    exit;
}

$abs = bericht_anhaenge_abs_path($row['storage_path']);
if (!is_file($abs) || !is_readable($abs)) {
    header('HTTP/1.1 404 Not Found');
    echo 'Datei fehlt';
    exit;
}

$mime = $row['mime_type'] ?: 'application/octet-stream';
$name = $row['filename_original'] ?: 'anhang';
header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . rawurlencode($name) . '"');
header('Content-Length: ' . filesize($abs));
header('X-Content-Type-Options: nosniff');
readfile($abs);
exit;
