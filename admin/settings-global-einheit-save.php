<?php
/**
 * Handler für das Einheit-Formular (Name, Kurzbeschreibung, App-Optionen).
 * Separates Formular vermeidet Formular-Verschachtelung.
 */
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/einheiten-setup.php';
require_once __DIR__ . '/../includes/einheit-settings-helper.php';

if (!isset($_SESSION['user_id']) || !hasAdminPermission()) {
    header('Location: ../login.php');
    exit;
}

$einheit_id = (int)($_POST['einheit_id'] ?? $_GET['einheit_id'] ?? 0);
if ($einheit_id <= 0) {
    header('Location: settings-global.php?error=invalid');
    exit;
}

if (!user_has_einheit_access($_SESSION['user_id'], $einheit_id)) {
    header('Location: settings-global.php?einheit_id=' . $einheit_id . '&error=access_denied');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: settings-global.php?einheit_id=' . $einheit_id . '&tab=einheit');
    exit;
}

if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
    header('Location: settings-global.php?einheit_id=' . $einheit_id . '&tab=einheit&error=csrf');
    exit;
}

try {
    $settings = load_settings_for_einheit($db, $einheit_id);
    ensure_einheit_settings_table($db);
    $app = ['geraetehaus_adresse' => trim(sanitize_input($_POST['geraetehaus_adresse'] ?? ''))];

    $upload_err = $_FILES['app_logo']['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($upload_err === UPLOAD_ERR_OK && !empty($_FILES['app_logo']['tmp_name']) && is_uploaded_file($_FILES['app_logo']['tmp_name'])) {
        $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/pjpeg'];
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? @finfo_file($finfo, $_FILES['app_logo']['tmp_name']) : '';
        if ($finfo) finfo_close($finfo);
        if (in_array($mime, $allowed)) {
            $ext = ['image/jpeg' => 'jpg', 'image/pjpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'][$mime] ?? 'png';
            $upload_dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads';
            if (is_dir($upload_dir) && is_writable($upload_dir)) {
                $logo_path = $upload_dir . DIRECTORY_SEPARATOR . 'logo_einheit_' . $einheit_id . '.' . $ext;
                if (move_uploaded_file($_FILES['app_logo']['tmp_name'], $logo_path)) {
                    $app['app_logo'] = 'uploads/logo_einheit_' . $einheit_id . '.' . $ext;
                }
            }
        }
    }
    if (empty($app['app_logo'])) {
        $app['app_logo'] = $settings['app_logo'] ?? '';
    }
    save_settings_bulk_for_einheit($db, $einheit_id, $app);

    header('Location: settings-global.php?einheit_id=' . (int)$einheit_id . '&tab=einheit&saved=1');
    exit;
} catch (Exception $e) {
    error_log('settings-global-einheit-save: ' . $e->getMessage());
    header('Location: settings-global.php?einheit_id=' . (int)$einheit_id . '&tab=einheit&error=save');
    exit;
}
