<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || !has_admin_access()) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

try {
    $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings ORDER BY setting_key");
    $stmt->execute();
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $payload = [
        'exported_at' => date('c'),
        'app' => 'Feuerwehr App',
        'type' => 'settings',
        'data' => $settings,
    ];

    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="settings-export-' . date('Ymd-His') . '.json"');
    header('Content-Length: ' . strlen($json));
    echo $json;
} catch (Exception $e) {
    http_response_code(500);
    echo 'Export-Fehler: ' . htmlspecialchars($e->getMessage());
}
?>


