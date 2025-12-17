<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Prüfe ob Benutzer eingeloggt ist und Settings-Berechtigung hat
if (!isset($_SESSION['user_id']) || !has_permission('settings')) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method Not Allowed';
    exit;
}

if (!isset($_FILES['settings_file']) || $_FILES['settings_file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo 'Keine Datei hochgeladen.';
    exit;
}

$content = file_get_contents($_FILES['settings_file']['tmp_name']);
$data = json_decode($content, true);
if (!is_array($data) || ($data['type'] ?? '') !== 'settings' || !is_array($data['data'] ?? null)) {
    http_response_code(400);
    echo 'Ungültiges Datei-Format.';
    exit;
}

try {
    $settings = $data['data'];
    $db->beginTransaction();
    foreach ($settings as $key => $value) {
        $stmt = $db->prepare('UPDATE settings SET setting_value = ? WHERE setting_key = ?');
        $stmt->execute([ (string)$value, (string)$key ]);
    }
    $db->commit();
    header('Location: settings-backup.php?import=success');
} catch (Exception $e) {
    $db->rollBack();
    http_response_code(500);
    echo 'Import-Fehler: ' . htmlspecialchars($e->getMessage());
}
?>


