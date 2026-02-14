<?php
/**
 * API: Autologin-URL für Systembenutzer abrufen.
 * Nur für Admins, nur für Systembenutzer.
 */
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    echo json_encode(['success' => false, 'error' => 'Nicht angemeldet']);
    exit;
}

if (is_system_user()) {
    echo json_encode(['success' => false, 'error' => 'Kein Zugriff']);
    exit;
}

if (!hasAdminPermission()) {
    echo json_encode(['success' => false, 'error' => 'Keine Admin-Berechtigung']);
    exit;
}

$user_id = (int)($_GET['user_id'] ?? 0);
if ($user_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Ungültige Benutzer-ID']);
    exit;
}

try {
    $stmt = $db->prepare("SELECT id, username, autologin_token, autologin_expires, is_system_user FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || empty($user['is_system_user'])) {
        echo json_encode(['success' => false, 'error' => 'Kein Systembenutzer gefunden']);
        exit;
    }

    if (empty($user['autologin_token'])) {
        echo json_encode(['success' => false, 'error' => 'Kein Autologin-Token. Bitte „Neuer Link“ verwenden.']);
        exit;
    }

    if (!empty($user['autologin_expires']) && strtotime($user['autologin_expires']) < time()) {
        echo json_encode(['success' => false, 'error' => 'Token abgelaufen. Bitte „Neuer Link“ verwenden.']);
        exit;
    }

    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script_dir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
    $app_base = rtrim(dirname($script_dir), '/');
    $autologin_url = $protocol . '://' . $host . $app_base . '/autologin.php?token=' . urlencode($user['autologin_token']);

    $validity_hint = empty($user['autologin_expires']) ? 'Unbegrenzt gültig.' : 'Gültig bis ' . date('d.m.Y', strtotime($user['autologin_expires'])) . '.';

    echo json_encode([
        'success' => true,
        'url' => $autologin_url,
        'username' => $user['username'],
        'validity_hint' => $validity_hint
    ]);
} catch (Exception $e) {
    error_log('get-autologin-url: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Fehler beim Laden']);
}
