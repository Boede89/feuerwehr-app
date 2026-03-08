<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once __DIR__ . '/../includes/einheiten-setup.php';
require_once __DIR__ . '/../includes/einheit-settings-helper.php';
require_once __DIR__ . '/../includes/rooms-setup.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('Location: ../login.php');
    exit;
}
if (!hasAdminPermission()) {
    header('Location: ../login.php?error=access_denied');
    exit;
}

$einheit_id = isset($_GET['einheit_id']) ? (int)$_GET['einheit_id'] : 0;
$einheit = null;
$valid_tabs = ['smtp', 'google', 'einheit', 'drucker', 'divera', 'fahrzeuge', 'raeume'];
$global_valid_tabs = ['app', 'feedback', 'smtp'];
if ($einheit_id <= 0) {
    $active_tab = isset($_GET['tab']) ? preg_replace('/[^a-z0-9_-]/', '', $_GET['tab']) : 'app';
    if (!in_array($active_tab, $global_valid_tabs)) $active_tab = 'app';
} else {
    $active_tab = isset($_GET['tab']) ? preg_replace('/[^a-z0-9_-]/', '', $_GET['tab']) : 'smtp';
    if (!in_array($active_tab, $valid_tabs)) $active_tab = 'smtp';
    if ($active_tab === 'app') $active_tab = 'einheit'; // Kompatibilität mit alten Links
}
if ($einheit_id > 0) {
    try {
        $stmt = $db->prepare("SELECT id, name, kurzbeschreibung FROM einheiten WHERE id = ?");
        $stmt->execute([$einheit_id]);
        $einheit = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

$message = '';
$error = '';

// Fahrzeugverwaltung: POST (add/edit) und GET (delete) vor dem Hauptformular verarbeiten
$vehicle_action = $_POST['action'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($vehicle_action, ['add', 'edit'], true) && $einheit_id > 0) {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Ungültiger Sicherheitstoken.';
    } else {
        $name = sanitize_input($_POST['name'] ?? '');
        $description = sanitize_input($_POST['description'] ?? '');
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $sort_order = (int)($_POST['sort_order'] ?? 0);
        $vehicle_id = (int)($_POST['vehicle_id'] ?? 0);
        if (empty($name)) {
            $error = 'Name ist erforderlich.';
        } else {
            try {
                if ($vehicle_action === 'add') {
                    if ($sort_order == 0) {
                        $stmt = $db->prepare("SELECT COALESCE(MAX(sort_order), 0) + 1 as next_order FROM vehicles WHERE einheit_id = ? OR einheit_id IS NULL");
                        $stmt->execute([$einheit_id]);
                        $sort_order = (int)($stmt->fetch(PDO::FETCH_ASSOC)['next_order'] ?? 1);
                    }
                    $stmt = $db->prepare("INSERT INTO vehicles (name, description, is_active, sort_order, einheit_id) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$name, $description, $is_active, $sort_order, $einheit_id]);
                    log_activity($_SESSION['user_id'], 'vehicle_added', "Fahrzeug '$name' hinzugefügt");
                    header('Location: settings-global.php?einheit_id=' . (int)$einheit_id . '&tab=fahrzeuge&vehicle_success=added');
                    exit;
                } elseif ($vehicle_action === 'edit' && $vehicle_id > 0) {
                    $stmt = $db->prepare("UPDATE vehicles SET name = ?, description = ?, is_active = ?, sort_order = ? WHERE id = ? AND (einheit_id = ? OR einheit_id IS NULL)");
                    $stmt->execute([$name, $description, $is_active, $sort_order, $vehicle_id, $einheit_id]);
                    if ($stmt->rowCount() > 0) {
                        log_activity($_SESSION['user_id'], 'vehicle_updated', "Fahrzeug '$name' aktualisiert");
                    }
                    header('Location: settings-global.php?einheit_id=' . (int)$einheit_id . '&tab=fahrzeuge&vehicle_success=updated');
                    exit;
                }
            } catch (PDOException $e) {
                $error = 'Fehler beim Speichern des Fahrzeugs: ' . $e->getMessage();
            }
        }
    }
}
if (isset($_GET['vehicle_delete']) && $einheit_id > 0) {
    $vid = (int)$_GET['vehicle_delete'];
    try {
        $stmt = $db->prepare("SELECT COUNT(*) as c FROM reservations WHERE vehicle_id = ?");
        $stmt->execute([$vid]);
        if ((int)($stmt->fetch(PDO::FETCH_ASSOC)['c'] ?? 0) > 0) {
            $error = 'Das Fahrzeug kann nicht gelöscht werden, da es in Reservierungen verwendet wird.';
        } else {
            $stmt = $db->prepare("DELETE FROM vehicles WHERE id = ? AND (einheit_id = ? OR einheit_id IS NULL)");
            $stmt->execute([$vid, $einheit_id]);
            if ($stmt->rowCount() > 0) {
                log_activity($_SESSION['user_id'], 'vehicle_deleted', "Fahrzeug ID $vid gelöscht");
                header('Location: settings-global.php?einheit_id=' . (int)$einheit_id . '&tab=fahrzeuge&vehicle_success=deleted');
                exit;
            }
        }
    } catch (PDOException $e) {
        $error = 'Fehler beim Löschen: ' . $e->getMessage();
    }
}

// Raumverwaltung: POST (add/edit) und GET (delete)
$room_action = $_POST['room_action'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($room_action, ['add', 'edit'], true) && $einheit_id > 0) {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Ungültiger Sicherheitstoken.';
    } else {
        $name = sanitize_input($_POST['room_name'] ?? '');
        $description = sanitize_input($_POST['room_description'] ?? '');
        $is_active = isset($_POST['room_is_active']) ? 1 : 0;
        $sort_order = (int)($_POST['room_sort_order'] ?? 0);
        $room_id = (int)($_POST['room_id'] ?? 0);
        if (empty($name)) {
            $error = 'Name ist erforderlich.';
        } else {
            try {
                if ($room_action === 'add') {
                    if ($sort_order == 0) {
                        $stmt = $db->prepare("SELECT COALESCE(MAX(sort_order), 0) + 1 as next_order FROM rooms WHERE einheit_id = ? OR einheit_id IS NULL");
                        $stmt->execute([$einheit_id]);
                        $sort_order = (int)($stmt->fetch(PDO::FETCH_ASSOC)['next_order'] ?? 1);
                    }
                    $stmt = $db->prepare("INSERT INTO rooms (name, description, is_active, sort_order, einheit_id) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$name, $description, $is_active, $sort_order, $einheit_id]);
                    log_activity($_SESSION['user_id'], 'room_added', "Raum '$name' hinzugefügt");
                    header('Location: settings-global.php?einheit_id=' . (int)$einheit_id . '&tab=raeume&room_success=added');
                    exit;
                } elseif ($room_action === 'edit' && $room_id > 0) {
                    $stmt = $db->prepare("UPDATE rooms SET name = ?, description = ?, is_active = ?, sort_order = ? WHERE id = ? AND (einheit_id = ? OR einheit_id IS NULL)");
                    $stmt->execute([$name, $description, $is_active, $sort_order, $room_id, $einheit_id]);
                    if ($stmt->rowCount() > 0) {
                        log_activity($_SESSION['user_id'], 'room_updated', "Raum '$name' aktualisiert");
                    }
                    header('Location: settings-global.php?einheit_id=' . (int)$einheit_id . '&tab=raeume&room_success=updated');
                    exit;
                }
            } catch (PDOException $e) {
                $error = 'Fehler beim Speichern des Raums: ' . $e->getMessage();
            }
        }
    }
}
if (isset($_GET['room_delete']) && $einheit_id > 0) {
    $rid = (int)$_GET['room_delete'];
    try {
        $stmt = $db->prepare("SELECT COUNT(*) as c FROM room_reservations WHERE room_id = ?");
        $stmt->execute([$rid]);
        if ((int)($stmt->fetch(PDO::FETCH_ASSOC)['c'] ?? 0) > 0) {
            $error = 'Der Raum kann nicht gelöscht werden, da er in Reservierungen verwendet wird.';
        } else {
            $stmt = $db->prepare("DELETE FROM rooms WHERE id = ? AND (einheit_id = ? OR einheit_id IS NULL)");
            $stmt->execute([$rid, $einheit_id]);
            if ($stmt->rowCount() > 0) {
                log_activity($_SESSION['user_id'], 'room_deleted', "Raum ID $rid gelöscht");
                header('Location: settings-global.php?einheit_id=' . (int)$einheit_id . '&tab=raeume&room_success=deleted');
                exit;
            }
        }
    } catch (PDOException $e) {
        $error = 'Fehler beim Löschen: ' . $e->getMessage();
    }
}

// Fahrzeuge laden (nur bei Einheiten-Einstellungen)
$vehicles = [];
$rooms = [];
if ($einheit_id > 0 && user_has_einheit_access($_SESSION['user_id'], $einheit_id)) {
    try {
        $stmt = $db->prepare("SELECT * FROM vehicles WHERE einheit_id = ? OR einheit_id IS NULL ORDER BY sort_order ASC, name ASC");
        $stmt->execute([$einheit_id]);
        $vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error = ($error ? $error . ' ' : '') . 'Fehler beim Laden der Fahrzeuge: ' . $e->getMessage();
    }
    try {
        $stmt = $db->prepare("SELECT * FROM rooms WHERE einheit_id = ? OR einheit_id IS NULL ORDER BY sort_order ASC, name ASC");
        $stmt->execute([$einheit_id]);
        $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error = ($error ? $error . ' ' : '') . 'Fehler beim Laden der Räume: ' . $e->getMessage();
    }
}

// Erfolgsmeldungen prüfen
if (isset($_GET['import']) && $_GET['import'] === 'success') {
    $message = 'Einstellungen wurden erfolgreich importiert!';
}
if (isset($_GET['dbimport']) && $_GET['dbimport'] === 'success') {
    $message = 'Datenbank wurde erfolgreich importiert!';
}
if (isset($_GET['saved']) && $_GET['saved'] === '1') {
    $message = $einheit_id > 0 ? 'Einstellungen gespeichert.' : 'Globale Einstellungen gespeichert.';
}
if (isset($_GET['error'])) {
    $err = $_GET['error'];
    if ($err === 'name_required') $error = 'Name der Einheit ist erforderlich.';
    elseif ($err === 'csrf') $error = 'Ungültiger Sicherheitstoken.';
    elseif ($err === 'save') $error = 'Fehler beim Speichern.';
}
if (isset($_GET['vehicle_success'])) {
    if ($_GET['vehicle_success'] === 'added') $message = 'Fahrzeug wurde erfolgreich hinzugefügt.';
    elseif ($_GET['vehicle_success'] === 'updated') $message = 'Fahrzeug wurde erfolgreich aktualisiert.';
    elseif ($_GET['vehicle_success'] === 'deleted') $message = 'Fahrzeug wurde erfolgreich gelöscht.';
}
if (isset($_GET['room_success'])) {
    if ($_GET['room_success'] === 'added') $message = 'Raum wurde erfolgreich hinzugefügt.';
    elseif ($_GET['room_success'] === 'updated') $message = 'Raum wurde erfolgreich aktualisiert.';
    elseif ($_GET['room_success'] === 'deleted') $message = 'Raum wurde erfolgreich gelöscht.';
}
if (isset($_GET['global_smtp_applied']) && $_GET['global_smtp_applied'] === '1') {
    $message = 'Globale SMTP-Einstellungen wurden übernommen.';
}

// Laden
$settings = [];
$divera_reservation_groups = [];
try {
    $settings = load_settings_for_einheit($db, $einheit_id > 0 ? $einheit_id : null);
    if ($einheit_id > 0) {
        $legacy_ids_raw = trim((string) ($settings['divera_reservation_group_ids'] ?? ''));
        if (!empty($settings['divera_reservation_groups'])) {
            $dec = json_decode($settings['divera_reservation_groups'], true);
            $divera_reservation_groups = is_array($dec) ? $dec : [];
        }
        if (empty($divera_reservation_groups) && !empty($legacy_ids_raw)) {
            $ids = array_filter(array_map('intval', preg_split('/[\s,;]+/', $legacy_ids_raw)));
            foreach ($ids as $id) {
                if ($id > 0) $divera_reservation_groups[] = ['id' => $id, 'name' => 'Gruppe ' . $id];
            }
        }
    }
} catch (Exception $e) {
    $error = 'Fehler beim Laden der Einstellungen: ' . $e->getMessage();
}

// Globale SMTP auf Einheit übernehmen (nur bei Button-Klick)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'use_global_smtp' && $einheit_id > 0) {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Ungültiger Sicherheitstoken.';
    } elseif (user_has_einheit_access($_SESSION['user_id'], $einheit_id)) {
        try {
            $global_settings = load_settings_for_einheit($db, null);
            $smtp_keys = ['smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_encryption', 'smtp_from_email', 'smtp_from_name'];
            $to_save = [];
            foreach ($smtp_keys as $k) {
                if (isset($global_settings[$k])) $to_save[$k] = $global_settings[$k];
            }
            if (!empty($to_save)) {
                save_settings_bulk_for_einheit($db, $einheit_id, $to_save);
                header('Location: settings-global.php?einheit_id=' . (int)$einheit_id . '&tab=smtp&global_smtp_applied=1');
                exit;
            }
        } catch (Exception $e) {
            $error = 'Fehler beim Übernehmen: ' . $e->getMessage();
        }
    }
}

// Speichern (nicht bei use_global_smtp – wird oben behandelt)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!isset($_POST['action']) || $_POST['action'] !== 'use_global_smtp')) {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Ungültiger Sicherheitstoken.';
    } else {
        try {
            $save_einheit_id = (int)($_POST['einheit_id'] ?? 0);
            // Tabelle vorher sicherstellen (CREATE TABLE verursacht implizites COMMIT – keine Transaktion verwenden)
            if ($save_einheit_id > 0) {
                ensure_einheit_settings_table($db);
            }

            if ($save_einheit_id > 0) {
                // Einheitenspezifisch: alle Einstellungen
                $smtp = [
                    'smtp_host' => sanitize_input($_POST['smtp_host'] ?? ''),
                    'smtp_port' => sanitize_input($_POST['smtp_port'] ?? ''),
                    'smtp_username' => sanitize_input($_POST['smtp_username'] ?? ''),
                    'smtp_encryption' => sanitize_input($_POST['smtp_encryption'] ?? ''),
                    'smtp_from_email' => sanitize_input($_POST['smtp_from_email'] ?? ''),
                    'smtp_from_name' => sanitize_input($_POST['smtp_from_name'] ?? ''),
                ];
                if (!empty(trim($_POST['smtp_password'] ?? ''))) {
                    $smtp['smtp_password'] = trim($_POST['smtp_password']);
                } else {
                    $smtp['smtp_password'] = $settings['smtp_password'] ?? '';
                }
                $json_content = trim($_POST['google_calendar_service_account_json'] ?? '');
                $file_path = sanitize_input($_POST['google_calendar_service_account_file'] ?? '');
                $google = [
                    'google_calendar_service_account_file' => $file_path,
                    'google_calendar_service_account_json' => $json_content,
                    'google_calendar_id' => sanitize_input($_POST['google_calendar_id'] ?? ''),
                    'google_calendar_auth_type' => sanitize_input($_POST['google_calendar_auth_type'] ?? 'service_account'),
                ];
                $app = [
                    'geraetehaus_adresse' => trim(sanitize_input($_POST['geraetehaus_adresse'] ?? ($settings['geraetehaus_adresse'] ?? ''))),
                ];
                $upload_err = $_FILES['app_logo']['error'] ?? UPLOAD_ERR_NO_FILE;
                $logo_upload_error = '';
                if ($upload_err === UPLOAD_ERR_OK && !empty($_FILES['app_logo']['tmp_name']) && is_uploaded_file($_FILES['app_logo']['tmp_name'])) {
                    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/pjpeg'];
                    $finfo = @finfo_open(FILEINFO_MIME_TYPE);
                    $mime = $finfo ? @finfo_file($finfo, $_FILES['app_logo']['tmp_name']) : '';
                    if ($finfo) finfo_close($finfo);
                    if (in_array($mime, $allowed)) {
                        $ext = ['image/jpeg' => 'jpg', 'image/pjpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'][$mime] ?? 'png';
                        $upload_dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads';
                        if (!is_dir($upload_dir)) {
                            if (!@mkdir($upload_dir, 0755, true)) {
                                $logo_upload_error = 'Upload-Ordner konnte nicht erstellt werden. Bitte uploads/ manuell anlegen.';
                            }
                        }
                        if (empty($logo_upload_error) && !is_writable($upload_dir)) {
                            $logo_upload_error = 'Upload-Ordner uploads/ ist nicht beschreibbar. Schreibrechte prüfen (z.B. chmod 755).';
                        }
                        if (empty($logo_upload_error)) {
                            $logo_path = $upload_dir . DIRECTORY_SEPARATOR . 'logo_einheit_' . $save_einheit_id . '.' . $ext;
                            if (move_uploaded_file($_FILES['app_logo']['tmp_name'], $logo_path)) {
                                $app['app_logo'] = 'uploads/logo_einheit_' . $save_einheit_id . '.' . $ext;
                            } else {
                                $logo_upload_error = 'Logo konnte nicht gespeichert werden. Schreibrechte für uploads/ prüfen (Docker: Volume-Mount).';
                            }
                        }
                    } else {
                        $logo_upload_error = 'Ungültiges Bildformat. Erlaubt: JPG, PNG, GIF, WebP. Erkannt: ' . ($mime ?: 'unbekannt');
                    }
                } elseif ($upload_err !== UPLOAD_ERR_NO_FILE && $upload_err !== UPLOAD_ERR_OK) {
                    $err_msg = [UPLOAD_ERR_INI_SIZE => 'Datei zu groß (upload_max_filesize)', UPLOAD_ERR_FORM_SIZE => 'Datei zu groß (post_max_size)', UPLOAD_ERR_PARTIAL => 'Upload unvollständig', UPLOAD_ERR_NO_TMP_DIR => 'Temporärer Ordner fehlt', UPLOAD_ERR_CANT_WRITE => 'Speichern fehlgeschlagen', UPLOAD_ERR_EXTENSION => 'Upload blockiert'];
                    $logo_upload_error = $err_msg[$upload_err] ?? 'Upload-Fehler (Code ' . $upload_err . ')';
                }
                if (empty($app['app_logo'])) {
                    $app['app_logo'] = $settings['app_logo'] ?? '';
                }
                if ($logo_upload_error !== '') {
                    $error = $logo_upload_error;
                }
                $printer = [
                    'printer_destination' => sanitize_input($_POST['printer_destination'] ?? ''),
                    'printer_cups_server' => trim(sanitize_input($_POST['printer_cups_server'] ?? '')),
                    'printer_cloud_url' => trim(sanitize_input($_POST['printer_cloud_url'] ?? '')),
                    'printer_cloud_url_raw' => isset($_POST['printer_cloud_url_raw']) && $_POST['printer_cloud_url_raw'] === '1' ? '1' : '0',
                ];
                $divera_access_key = trim($_POST['divera_access_key'] ?? '');
                $divera_key_clear = isset($_POST['divera_access_key_clear']) && $_POST['divera_access_key_clear'] === '1';
                if ($divera_key_clear) {
                    $divera_access_key = '';
                } elseif ($divera_access_key === '') {
                    $divera_access_key = trim((string) ($settings['divera_access_key'] ?? ''));
                }
                $divera_api_base_url = trim($_POST['divera_api_base_url'] ?? '') ?: 'https://app.divera247.com';
                $group_ids = $_POST['divera_group_id'] ?? [];
                $group_names = $_POST['divera_group_name'] ?? [];
                $groups = [];
                foreach ($group_ids as $i => $gid) {
                    $gid = trim((string) $gid);
                    $gidInt = $gid === '' ? 0 : (int) $gid;
                    $gname = trim((string) ($group_names[$i] ?? ''));
                    if ($gname !== '') {
                        $groups[] = ['id' => $gidInt, 'name' => $gname];
                    } elseif ($gidInt > 0) {
                        $groups[] = ['id' => $gidInt, 'name' => 'Gruppe ' . $gidInt];
                    }
                }
                $divera = [
                    'divera_access_key' => $divera_access_key,
                    'divera_api_base_url' => $divera_api_base_url,
                    'divera_reservation_groups' => json_encode($groups),
                ];
                $all = array_merge($smtp, $google, $app, $printer, $divera);
                save_settings_bulk_for_einheit($db, $save_einheit_id, $all);
            } else {
                // Global: App, Feedback-E-Mail, SMTP (für Feedback/Wünsche unabhängig von Einheiten)
                $all = [
                    'app_name' => sanitize_input($_POST['app_name'] ?? ''),
                    'app_url' => sanitize_input($_POST['app_url'] ?? ''),
                    'feedback_email' => trim(sanitize_input($_POST['feedback_email'] ?? '')),
                    'smtp_host' => sanitize_input($_POST['global_smtp_host'] ?? ''),
                    'smtp_port' => sanitize_input($_POST['global_smtp_port'] ?? ''),
                    'smtp_username' => sanitize_input($_POST['global_smtp_username'] ?? ''),
                    'smtp_encryption' => sanitize_input($_POST['global_smtp_encryption'] ?? ''),
                    'smtp_from_email' => sanitize_input($_POST['global_smtp_from_email'] ?? ''),
                    'smtp_from_name' => sanitize_input($_POST['global_smtp_from_name'] ?? ''),
                ];
                if (!empty(trim($_POST['global_smtp_password'] ?? ''))) {
                    $all['smtp_password'] = trim($_POST['global_smtp_password']);
                } else {
                    $all['smtp_password'] = $settings['smtp_password'] ?? '';
                }
                foreach ($all as $k => $v) {
                    $stmt = $db->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
                    $stmt->execute([$k, $v]);
                }
            }

            $message = $save_einheit_id > 0 ? 'Einstellungen gespeichert.' : 'Globale Einstellungen gespeichert.';
            $had_error = !empty($error);
            if ($had_error) {
                $message .= ' Hinweis: ' . $error;
                $error = '';
            }
            $settings = array_merge($settings, $all ?? []);
            // Redirect nach POST (verhindert erneutes Senden bei Aktualisierung, behebt ggf. HTTP 500)
            if (!$had_error) {
                $redirect_url = 'settings-global.php';
                if ($save_einheit_id > 0) {
                    $return_tab = preg_replace('/[^a-z0-9_-]/', '', $_POST['return_tab'] ?? 'smtp');
                    if ($return_tab === 'app') $return_tab = 'einheit';
                    if (!in_array($return_tab, $valid_tabs)) $return_tab = 'smtp';
                    $redirect_url .= '?einheit_id=' . $save_einheit_id . '&saved=1&tab=' . $return_tab;
                } else {
                    $return_tab = preg_replace('/[^a-z0-9_-]/', '', $_POST['return_tab'] ?? 'app');
                    if (!in_array($return_tab, $global_valid_tabs)) $return_tab = 'app';
                    $redirect_url .= '?saved=1&tab=' . $return_tab;
                }
                header('Location: ' . $redirect_url);
                exit;
            }
        } catch (Throwable $e) {
            error_log('settings-global save error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            $error = 'Fehler beim Speichern: ' . $e->getMessage();
        }
    }
}

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Globale Einstellungen</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
    <div class="container-fluid">
        <a class="navbar-brand" href="../index.php"><i class="fas fa-fire"></i> Feuerwehr App</a>
            <div class="d-flex ms-auto align-items-center">
                <?php $admin_menu_in_navbar = true; include __DIR__ . '/includes/admin-menu.inc.php'; ?>
            </div>
    </div>
    </nav>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h3 mb-0"><i class="fas fa-gear"></i> Globale Einstellungen<?php if ($einheit): ?> <span class="text-muted">(<?php echo htmlspecialchars($einheit['name']); ?>)</span><?php endif; ?></h1>
        <div class="d-flex gap-2">
            <?php if ($einheit_id <= 0): ?>
            <a href="settings-backup.php" class="btn btn-outline-primary"><i class="fas fa-shield-halved"></i> Sicherung & Wiederherstellung</a>
            <?php endif; ?>
            <a href="<?php echo $einheit_id > 0 ? 'settings-einheit.php?id=' . (int)$einheit_id : 'settings.php'; ?>" class="btn btn-outline-secondary"><i class="fas fa-arrow-left"></i> Zurück</a>
        </div>
    </div>
    <?php if ($message) echo show_success($message); ?>
    <?php if ($error) echo show_error($error); ?>

    <?php if ($einheit_id > 0): ?>
    <form id="einheitForm" method="POST" enctype="multipart/form-data" action="settings-global-einheit-save.php" class="d-none">
        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
        <input type="hidden" name="einheit_id" value="<?php echo (int)$einheit_id; ?>">
    </form>
    <?php endif; ?>
    <form method="POST" enctype="multipart/form-data" id="mainForm">
        <?php if ($einheit_id <= 0): ?>
        <!-- Globale Einstellungen – Tab-Navigation -->
        <ul class="nav nav-tabs mb-4" id="globalSettingsTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link <?php echo $active_tab === 'app' ? 'active' : ''; ?>" id="tab-global-app-btn" data-bs-toggle="tab" data-bs-target="#tab-global-app" type="button" role="tab"><i class="fas fa-cog me-1"></i> App</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link <?php echo $active_tab === 'feedback' ? 'active' : ''; ?>" id="tab-global-feedback-btn" data-bs-toggle="tab" data-bs-target="#tab-global-feedback" type="button" role="tab"><i class="fas fa-comment-dots me-1"></i> Feedback & Wünsche</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link <?php echo $active_tab === 'smtp' ? 'active' : ''; ?>" id="tab-global-smtp-btn" data-bs-toggle="tab" data-bs-target="#tab-global-smtp" type="button" role="tab"><i class="fas fa-envelope me-1"></i> SMTP</button>
            </li>
        </ul>
        <div class="tab-content" id="globalSettingsTabContent">
            <div class="tab-pane fade <?php echo $active_tab === 'app' ? 'show active' : ''; ?>" id="tab-global-app" role="tabpanel">
                <div class="card">
                    <div class="card-header"><i class="fas fa-cog"></i> App</div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label">App Name</label>
                            <input class="form-control" name="app_name" value="<?php echo htmlspecialchars($settings['app_name'] ?? ''); ?>" placeholder="z.B. Feuerwehr App">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">App URL</label>
                            <input class="form-control" name="app_url" value="<?php echo htmlspecialchars($settings['app_url'] ?? ''); ?>" placeholder="z.B. https://feuerwehr.example.de">
                            <small class="text-muted">Basis-URL der Anwendung (wird z.B. für E-Mails und Links verwendet).</small>
                        </div>
                    </div>
                </div>
            </div>
            <div class="tab-pane fade <?php echo $active_tab === 'feedback' ? 'show active' : ''; ?>" id="tab-global-feedback" role="tabpanel">
                <div class="card">
                    <div class="card-header"><i class="fas fa-comment-dots"></i> Feedback & Wünsche</div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label">E-Mail-Adresse für Feedback/Wünsche</label>
                            <input class="form-control" type="email" name="feedback_email" value="<?php echo htmlspecialchars($settings['feedback_email'] ?? ''); ?>" placeholder="z.B. feedback@feuerwehr.de">
                            <small class="text-muted">An diese Adresse werden Feedback und Funktionswünsche gesendet. Leer = E-Mail an alle Admins.</small>
                        </div>
                    </div>
                </div>
            </div>
            <div class="tab-pane fade <?php echo $active_tab === 'smtp' ? 'show active' : ''; ?>" id="tab-global-smtp" role="tabpanel">
                <div class="card">
                    <div class="card-header"><i class="fas fa-server"></i> Globale SMTP-Einstellungen</div>
                    <div class="card-body">
                        <p class="text-muted small mb-3">Für den Versand von Feedback-/Wunsch-Benachrichtigungen und anderer einheitsunabhängiger E-Mails.</p>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">SMTP Host</label>
                                <input class="form-control" name="global_smtp_host" value="<?php echo htmlspecialchars($settings['smtp_host'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">SMTP Port</label>
                                <input class="form-control" type="number" name="global_smtp_port" value="<?php echo htmlspecialchars($settings['smtp_port'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Benutzername</label>
                                <input class="form-control" name="global_smtp_username" value="<?php echo htmlspecialchars($settings['smtp_username'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Passwort</label>
                                <input class="form-control" type="password" name="global_smtp_password" placeholder="Leer lassen zum Beibehalten">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Verschlüsselung</label>
                                <select class="form-select" name="global_smtp_encryption">
                                    <option value="none" <?php echo (($settings['smtp_encryption'] ?? '')==='none')?'selected':''; ?>>Keine</option>
                                    <option value="tls" <?php echo (($settings['smtp_encryption'] ?? '')==='tls')?'selected':''; ?>>TLS</option>
                                    <option value="ssl" <?php echo (($settings['smtp_encryption'] ?? '')==='ssl')?'selected':''; ?>>SSL</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Absender E-Mail</label>
                                <input class="form-control" type="email" name="global_smtp_from_email" value="<?php echo htmlspecialchars($settings['smtp_from_email'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Absender Name</label>
                            <input class="form-control" name="global_smtp_from_name" value="<?php echo htmlspecialchars($settings['smtp_from_name'] ?? ''); ?>">
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php else: ?>
        <!-- Einheitenspezifische Einstellungen – Tab-Navigation -->
        <ul class="nav nav-tabs mb-4" id="settingsTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link <?php echo $active_tab === 'smtp' ? 'active' : ''; ?>" id="tab-smtp-btn" data-bs-toggle="tab" data-bs-target="#tab-smtp" type="button" role="tab"><i class="fas fa-envelope me-1"></i> SMTP</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link <?php echo $active_tab === 'google' ? 'active' : ''; ?>" id="tab-google-btn" data-bs-toggle="tab" data-bs-target="#tab-google" type="button" role="tab"><i class="fas fa-calendar me-1"></i> Google Kalender</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link <?php echo $active_tab === 'einheit' ? 'active' : ''; ?>" id="tab-einheit-btn" data-bs-toggle="tab" data-bs-target="#tab-einheit" type="button" role="tab"><i class="fas fa-building me-1"></i> Einheit</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link <?php echo $active_tab === 'drucker' ? 'active' : ''; ?>" id="tab-drucker-btn" data-bs-toggle="tab" data-bs-target="#tab-drucker" type="button" role="tab"><i class="fas fa-print me-1"></i> Drucker</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link <?php echo $active_tab === 'divera' ? 'active' : ''; ?>" id="tab-divera-btn" data-bs-toggle="tab" data-bs-target="#tab-divera" type="button" role="tab"><i class="fas fa-calendar-plus me-1"></i> Divera 24/7</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link <?php echo $active_tab === 'fahrzeuge' ? 'active' : ''; ?>" id="tab-fahrzeuge-btn" data-bs-toggle="tab" data-bs-target="#tab-fahrzeuge" type="button" role="tab"><i class="fas fa-truck me-1"></i> Fahrzeuge verwalten</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link <?php echo $active_tab === 'raeume' ? 'active' : ''; ?>" id="tab-raeume-btn" data-bs-toggle="tab" data-bs-target="#tab-raeume" type="button" role="tab"><i class="fas fa-door-open me-1"></i> Räume verwalten</button>
            </li>
        </ul>
        <div class="tab-content" id="settingsTabContent">
            <div class="tab-pane fade <?php echo $active_tab === 'smtp' ? 'show active' : ''; ?>" id="tab-smtp" role="tabpanel">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><i class="fas fa-envelope"></i> SMTP</span>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Globale SMTP-Einstellungen wirklich übernehmen? Die aktuellen Einheiten-Einstellungen werden überschrieben.');">
                            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                            <input type="hidden" name="action" value="use_global_smtp">
                            <input type="hidden" name="einheit_id" value="<?php echo (int)$einheit_id; ?>">
                            <button type="submit" class="btn btn-outline-primary btn-sm"><i class="fas fa-download me-1"></i> Globale SMTP übernehmen</button>
                        </form>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">SMTP Host</label>
                                <input class="form-control" name="smtp_host" value="<?php echo htmlspecialchars($settings['smtp_host'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">SMTP Port</label>
                                <input class="form-control" type="number" name="smtp_port" value="<?php echo htmlspecialchars($settings['smtp_port'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Benutzername</label>
                                <input class="form-control" name="smtp_username" value="<?php echo htmlspecialchars($settings['smtp_username'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Passwort</label>
                                <input class="form-control" type="password" name="smtp_password" placeholder="Leer lassen zum Beibehalten">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Verschlüsselung</label>
                                <select class="form-select" name="smtp_encryption">
                                    <option value="none" <?php echo (($settings['smtp_encryption'] ?? '')==='none')?'selected':''; ?>>Keine</option>
                                    <option value="tls" <?php echo (($settings['smtp_encryption'] ?? '')==='tls')?'selected':''; ?>>TLS</option>
                                    <option value="ssl" <?php echo (($settings['smtp_encryption'] ?? '')==='ssl')?'selected':''; ?>>SSL</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Absender E-Mail</label>
                                <input class="form-control" type="email" name="smtp_from_email" value="<?php echo htmlspecialchars($settings['smtp_from_email'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Absender Name</label>
                            <input class="form-control" name="smtp_from_name" value="<?php echo htmlspecialchars($settings['smtp_from_name'] ?? ''); ?>">
                        </div>
                    </div>
                </div>
            </div>
            <div class="tab-pane fade <?php echo $active_tab === 'google' ? 'show active' : ''; ?>" id="tab-google" role="tabpanel">
                <div class="card">
                    <div class="card-header"><i class="fas fa-calendar"></i> Google Kalender</div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label">Authentifizierung</label>
                            <select class="form-select" name="google_calendar_auth_type">
                                <option value="service_account" <?php echo (($settings['google_calendar_auth_type'] ?? 'service_account')==='service_account')?'selected':''; ?>>Service Account</option>
                                <option value="api_key" <?php echo (($settings['google_calendar_auth_type'] ?? '')==='api_key')?'selected':''; ?>>API Key</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Service Account JSON-Datei</label>
                            <input class="form-control" name="google_calendar_service_account_file" value="<?php echo htmlspecialchars($settings['google_calendar_service_account_file'] ?? ''); ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Service Account JSON-Inhalt</label>
                            <textarea class="form-control" rows="6" name="google_calendar_service_account_json"><?php echo htmlspecialchars($settings['google_calendar_service_account_json'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Kalender ID</label>
                            <input class="form-control" name="google_calendar_id" value="<?php echo htmlspecialchars($settings['google_calendar_id'] ?? ''); ?>">
                        </div>
                    </div>
                </div>
            </div>
            <div class="tab-pane fade <?php echo $active_tab === 'einheit' ? 'show active' : ''; ?>" id="tab-einheit" role="tabpanel">
                <div class="card">
                    <div class="card-header"><i class="fas fa-building"></i> Einheit bearbeiten</div>
                    <div class="card-body">
                        <p class="text-muted small mb-4">App-Optionen der Einheit anpassen. Name und Kurzbeschreibung können Sie als Superadmin unter <a href="settings-einheiten.php">Einheiten</a> im Modal „Einheit bearbeiten“ ändern.</p>
                        <?php if ($einheit_id > 0): ?>
                            <h6 class="mb-3">App-Optionen</h6>
                            <div class="mb-3">
                                <label class="form-label">Adresse Gerätehaus</label>
                                <input class="form-control" name="geraetehaus_adresse" form="einheitForm" placeholder="z.B. Musterstraße 1, 12345 Musterstadt" value="<?php echo htmlspecialchars($settings['geraetehaus_adresse'] ?? ''); ?>">
                                <small class="text-muted">Wird als Schnellauswahl neben dem Feld „Adresse / Einsatzstelle“ in der Anwesenheitsliste angezeigt.</small>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Logo für Formulare</label>
                                <input class="form-control" type="file" name="app_logo" form="einheitForm" accept="image/jpeg,image/png,image/gif,image/webp">
                            <small class="text-muted">Wird auf allen PDF-Formularen (Anwesenheitsliste etc.) oben angezeigt. Empfohlen: PNG oder JPG, max. 500 KB.</small>
                            <?php
                            $ud = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads';
                            $ud_ok = is_dir($ud) && is_writable($ud);
                            if (!$ud_ok): ?>
                            <div class="alert alert-warning mt-2 py-2 small mb-0">Ordner <code>uploads/</code> fehlt oder ist nicht beschreibbar. Logo-Upload funktioniert erst nach Behebung.</div>
                            <?php endif; ?>
                            <?php
                            $logo_full = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $settings['app_logo'] ?? '');
                            if (!empty($settings['app_logo']) && file_exists($logo_full)): ?>
                            <div class="mt-2">
                                <img src="../<?php echo htmlspecialchars(str_replace('\\', '/', $settings['app_logo'])); ?>?v=<?php echo filemtime($logo_full); ?>" alt="Logo" style="max-height: 60px; max-width: 200px;">
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="mt-3">
                            <button type="submit" form="einheitForm" class="btn btn-primary"><i class="fas fa-save"></i> Speichern</button>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="tab-pane fade <?php echo $active_tab === 'drucker' ? 'show active' : ''; ?>" id="tab-drucker" role="tabpanel">
                <div class="card">
                    <div class="card-header"><i class="fas fa-print"></i> Drucker</div>
                    <div class="card-body">
                        <p class="text-muted small mb-3">Der Drucker wird auf dem Host mit <code>lpadmin</code> angelegt. Hier tragen Sie den Druckernamen ein, unter dem er in CUPS registriert ist.</p>
                        <div class="mb-3">
                            <label class="form-label">Druckername</label>
                            <div class="input-group">
                                <input class="form-control" name="printer_destination" id="printer_destination" placeholder="z.B. workplacepure" value="<?php echo htmlspecialchars($settings['printer_destination'] ?? ''); ?>">
                                <button type="button" class="btn btn-outline-secondary" id="btn_list_printers" title="Verfügbare Drucker anzeigen"><i class="fas fa-list"></i> Drucker auflisten</button>
                            </div>
                            <small class="text-muted d-block mt-1">Der Name, den Sie beim <code>lpadmin -p NAME</code> Befehl verwenden.</small>
                            <div id="printers_list" class="mt-2 small" style="display:none;"></div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">CUPS-Server</label>
                            <input class="form-control" name="printer_cups_server" placeholder="host.docker.internal:631" value="<?php echo htmlspecialchars($settings['printer_cups_server'] ?? ''); ?>">
                            <small class="text-muted">Bei Docker <strong>unbedingt</strong> eintragen: <code>host.docker.internal:631</code> – sonst findet „Drucker auflisten“ keine Drucker und der Druck funktioniert nicht.</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Cloud-Drucker-URL <span class="text-muted">(Alternative zu CUPS)</span></label>
                            <input class="form-control" type="url" name="printer_cloud_url" id="printer_cloud_url" placeholder="https://..." value="<?php echo htmlspecialchars($settings['printer_cloud_url'] ?? ''); ?>">
                            <div class="form-check mt-2">
                                <input class="form-check-input" type="checkbox" name="printer_cloud_url_raw" id="printer_cloud_url_raw" value="1" <?php echo ($settings['printer_cloud_url_raw'] ?? '') === '1' ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="printer_cloud_url_raw">Als Raw-PDF senden (Content-Type: application/pdf) – falls die API multipart nicht akzeptiert</label>
                            </div>
                            <small class="text-muted d-block mt-1">Das PDF wird per HTTP POST an diese URL gesendet. Bei gesetzter URL hat diese Vorrang vor CUPS. Bei Verbindungsfehlern: cURL-Erweiterung prüfen (<code>php -m | grep curl</code>).</small>
                        </div>
                        <?php if ($einheit_id > 0): ?>
                        <div class="mb-0 mt-3">
                            <button type="button" class="btn btn-primary" id="btn_test_print" title="Testseite an den konfigurierten Drucker senden"><i class="fas fa-print me-1"></i> Testdruck</button>
                            <button type="button" class="btn btn-outline-secondary ms-2" id="btn_print_diagnose" title="CUPS-Warteschlange und Druckerstatus prüfen"><i class="fas fa-stethoscope me-1"></i> Diagnose</button>
                            <span id="test_print_result" class="ms-2 small"></span>
                        </div>
                        <div id="print_diagnose_output" class="mt-3 p-3 bg-light rounded small font-monospace" style="display:none;max-height:300px;overflow:auto;white-space:pre-wrap;font-size:11px;"></div>
                        <p class="text-muted small mt-2 mb-0">Falls „Druckauftrag gesendet“ erscheint, aber nichts gedruckt wird: CUPS hat den Auftrag angenommen. Prüfen Sie auf dem Host: <code>lpq -a</code> (Warteschlange), Drucker online? Bei Docker: CUPS-Server <code>host.docker.internal:631</code> – CUPS muss auf dem Host laufen.</p>
                        <p class="text-muted small mt-2 mb-0">Bei Cloud-Druckern (z.B. Princh): Fehler „file info is queued“ – Auftrag in der Cloud, aber Datei-Metadaten hängen. <strong>Lösung:</strong> Betroffene Jobs im Princh-Verwaltungspanel stornieren, Cloud-Connector neu starten, Drucker neu starten. Statische IP für den Drucker verwenden.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="tab-pane fade <?php echo $active_tab === 'divera' ? 'show active' : ''; ?>" id="tab-divera" role="tabpanel">
                <div class="card">
                    <div class="card-header"><i class="fas fa-calendar-plus"></i> Divera 24/7</div>
                    <div class="card-body">
                                <p class="text-muted small mb-3">Einstellungen für die automatische Übermittlung genehmigter Fahrzeugreservierungen und Dienstplan-Termine an Divera (einheitenspezifisch).</p>
                                <div class="mb-3">
                                    <label class="form-label">Access Key (Einheits-Key)</label>
                                    <div class="input-group">
                                        <input class="form-control" type="password" name="divera_access_key" id="divera_access_key" value="" placeholder="Leer lassen zum Beibehalten" autocomplete="off">
                                        <?php if ($einheit_id > 0): ?>
                                        <button type="button" class="btn btn-outline-danger" id="btn_divera_key_loeschen" title="Access Key für diese Einheit löschen (z.B. wenn fälschlich Key einer anderen Einheit gespeichert wurde)"><i class="fas fa-trash-alt"></i> Löschen</button>
                                        <?php endif; ?>
                                    </div>
                                    <small class="text-muted"><?php echo !empty($settings['divera_access_key']) ? 'Key ist hinterlegt. Neuen Key eintragen zum Überschreiben. Mit „Löschen“ den Key für diese Einheit entfernen.' : 'In Divera 24/7: Verwaltung → Konto (Kontakt- und Vertragsdaten).'; ?></small>
                                    <input type="hidden" name="divera_access_key_clear" id="divera_access_key_clear" value="0">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">API-Basis-URL</label>
                                    <input class="form-control" type="url" name="divera_api_base_url" value="<?php echo htmlspecialchars($settings['divera_api_base_url'] ?? 'https://app.divera247.com'); ?>" placeholder="https://app.divera247.com">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Empfänger-Gruppen (Fahrzeugreservierungen)</label>
                                    <p class="text-muted small">Definieren Sie Divera-Gruppen mit ID und Namen. ID leer = keine Gruppen-ID an Divera (alle des Standortes). Beim Genehmigen kann die Empfänger-Gruppe ausgewählt werden.</p>
                                    <div id="diveraGroupsContainer">
                                        <?php foreach ($divera_reservation_groups as $idx => $g): ?>
                                        <div class="input-group mb-2 divera-group-row">
                                            <input type="number" class="form-control" name="divera_group_id[]" placeholder="ID (leer = keine)" value="<?php echo (int)($g['id'] ?? 0) > 0 ? (int)$g['id'] : ''; ?>" min="0">
                                            <input type="text" class="form-control" name="divera_group_name[]" placeholder="Name der Gruppe" value="<?php echo htmlspecialchars($g['name'] ?? ''); ?>">
                                            <button type="button" class="btn btn-outline-danger btn-remove-group" title="Gruppe entfernen"><i class="fas fa-trash"></i></button>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" id="btnAddGroup"><i class="fas fa-plus me-1"></i>Gruppe hinzufügen</button>
                                </div>
                    </div>
                </div>
            </div>
            <div class="tab-pane fade <?php echo $active_tab === 'fahrzeuge' ? 'show active' : ''; ?>" id="tab-fahrzeuge" role="tabpanel">
        <!-- Fahrzeuge verwalten -->
        <div id="fahrzeuge">
            <div class="col-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><i class="fas fa-truck me-2"></i>Fahrzeuge verwalten</span>
                        <button type="button" class="btn btn-sm btn-primary" onclick="openVehicleModal()">
                            <i class="fas fa-plus"></i> Neues Fahrzeug
                        </button>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Beschreibung</th>
                                        <th>Sortierung</th>
                                        <th>Status</th>
                                        <th>Erstellt</th>
                                        <th>Aktionen</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($vehicles as $v): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($v['name']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($v['description'] ?? ''); ?></td>
                                        <td><span class="badge bg-info"><?php echo (int)($v['sort_order'] ?? 0); ?></span></td>
                                        <td>
                                            <?php if (!empty($v['is_active'])): ?>
                                                <span class="badge bg-success">Aktiv</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Inaktiv</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo !empty($v['created_at']) ? format_date($v['created_at']) : ''; ?></td>
                                        <td>
                                            <div class="btn-group" role="group">
                                                <a href="vehicles-geraete.php?vehicle_id=<?php echo (int)$v['id']; ?>" class="btn btn-outline-secondary btn-sm" title="Geräte verwalten">
                                                    <i class="fas fa-tools"></i>
                                                </a>
                                                <button type="button" class="btn btn-outline-primary btn-sm" data-vehicle-id="<?php echo (int)$v['id']; ?>" data-name="<?php echo htmlspecialchars($v['name'], ENT_QUOTES, 'UTF-8'); ?>" data-desc="<?php echo htmlspecialchars($v['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" data-sort="<?php echo (int)($v['sort_order'] ?? 0); ?>" data-active="<?php echo !empty($v['is_active']) ? 1 : 0; ?>" onclick="editVehicle(this)">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <a href="?einheit_id=<?php echo (int)$einheit_id; ?>&vehicle_delete=<?php echo (int)$v['id']; ?>" class="btn btn-outline-danger btn-sm" onclick="return confirm('Sind Sie sicher, dass Sie dieses Fahrzeug löschen möchten?')">
                                                    <i class="fas fa-trash"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($vehicles)): ?>
                                    <tr><td colspan="6" class="text-muted">Keine Fahrzeuge angelegt. Klicken Sie auf „Neues Fahrzeug“.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
        </div>
            </div>
        </div>
            <div class="tab-pane fade <?php echo $active_tab === 'raeume' ? 'show active' : ''; ?>" id="tab-raeume" role="tabpanel">
        <div id="raeume">
            <div class="col-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><i class="fas fa-door-open me-2"></i>Räume verwalten</span>
                        <button type="button" class="btn btn-sm btn-primary" onclick="openRoomModal()">
                            <i class="fas fa-plus"></i> Neuer Raum
                        </button>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Beschreibung</th>
                                        <th>Sortierung</th>
                                        <th>Status</th>
                                        <th>Erstellt</th>
                                        <th>Aktionen</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($rooms as $r): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($r['name']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($r['description'] ?? ''); ?></td>
                                        <td><span class="badge bg-info"><?php echo (int)($r['sort_order'] ?? 0); ?></span></td>
                                        <td>
                                            <?php if (!empty($r['is_active'])): ?>
                                                <span class="badge bg-success">Aktiv</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Inaktiv</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo !empty($r['created_at']) ? format_date($r['created_at']) : ''; ?></td>
                                        <td>
                                            <div class="btn-group" role="group">
                                                <button type="button" class="btn btn-outline-primary btn-sm" data-room-id="<?php echo (int)$r['id']; ?>" data-name="<?php echo htmlspecialchars($r['name'], ENT_QUOTES, 'UTF-8'); ?>" data-desc="<?php echo htmlspecialchars($r['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" data-sort="<?php echo (int)($r['sort_order'] ?? 0); ?>" data-active="<?php echo !empty($r['is_active']) ? 1 : 0; ?>" onclick="editRoom(this)">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <a href="?einheit_id=<?php echo (int)$einheit_id; ?>&tab=raeume&room_delete=<?php echo (int)$r['id']; ?>" class="btn btn-outline-danger btn-sm" onclick="return confirm('Sind Sie sicher, dass Sie diesen Raum löschen möchten?')">
                                                    <i class="fas fa-trash"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($rooms)): ?>
                                    <tr><td colspan="6" class="text-muted">Keine Räume angelegt. Klicken Sie auf „Neuer Raum“.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
            </div>
        </div>
        <?php endif; ?>
        <div class="d-flex justify-content-end mt-3" id="settingsSaveRow"<?php echo ($einheit_id > 0 && in_array($active_tab, ['fahrzeuge', 'raeume', 'einheit'])) ? ' style="display:none"' : ''; ?>>
            <button class="btn btn-primary" type="submit"><i class="fas fa-save"></i> Speichern</button>
        </div>
        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
        <?php if ($einheit_id > 0): ?><input type="hidden" name="einheit_id" value="<?php echo (int)$einheit_id; ?>"><?php endif; ?><input type="hidden" name="return_tab" id="return_tab" value="<?php echo htmlspecialchars($active_tab); ?>">
    </form>

    <?php if ($einheit_id > 0): ?>
    <!-- Fahrzeug Modal -->
    <div class="modal fade" id="vehicleModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" id="vehicleForm">
                    <div class="modal-header">
                        <h5 class="modal-title" id="vehicleModalTitle">Neues Fahrzeug</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="vehicle_name" class="form-label">Name *</label>
                            <input type="text" class="form-control" id="vehicle_name" name="name" required>
                        </div>
                        <div class="mb-3">
                            <label for="vehicle_description" class="form-label">Beschreibung</label>
                            <textarea class="form-control" id="vehicle_description" name="description" rows="3"></textarea>
                        </div>
                        <div class="mb-3">
                            <label for="vehicle_sort_order" class="form-label">Sortier-Reihenfolge</label>
                            <input type="number" class="form-control" id="vehicle_sort_order" name="sort_order" min="0" value="0">
                            <div class="form-text">Niedrigere Zahlen werden zuerst angezeigt. 0 = automatisch am Ende.</div>
                        </div>
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="vehicle_is_active" name="is_active" checked>
                                <label class="form-check-label" for="vehicle_is_active">Aktiv</label>
                            </div>
                        </div>
                        <input type="hidden" name="vehicle_id" id="vehicle_id">
                        <input type="hidden" name="action" id="vehicle_action" value="add">
                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                        <button type="submit" class="btn btn-primary" id="vehicleSubmitBtn">Speichern</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <!-- Raum Modal -->
    <div class="modal fade" id="roomModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" id="roomForm">
                    <div class="modal-header">
                        <h5 class="modal-title" id="roomModalTitle">Neuer Raum</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="room_name" class="form-label">Name *</label>
                            <input type="text" class="form-control" id="room_name" name="room_name" required>
                        </div>
                        <div class="mb-3">
                            <label for="room_description" class="form-label">Beschreibung</label>
                            <textarea class="form-control" id="room_description" name="room_description" rows="3"></textarea>
                        </div>
                        <div class="mb-3">
                            <label for="room_sort_order" class="form-label">Sortier-Reihenfolge</label>
                            <input type="number" class="form-control" id="room_sort_order" name="room_sort_order" min="0" value="0">
                            <div class="form-text">Niedrigere Zahlen werden zuerst angezeigt. 0 = automatisch am Ende.</div>
                        </div>
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="room_is_active" name="room_is_active" checked>
                                <label class="form-check-label" for="room_is_active">Aktiv</label>
                            </div>
                        </div>
                        <input type="hidden" name="room_id" id="room_id">
                        <input type="hidden" name="room_action" id="room_action" value="add">
                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                        <button type="submit" class="btn btn-primary" id="roomSubmitBtn">Speichern</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var returnTab = document.getElementById('return_tab');
    var tabBtns = document.querySelectorAll('#settingsTabs button[data-bs-toggle="tab"]');
    if (returnTab && tabBtns.length) {
        tabBtns.forEach(function(btn) {
            btn.addEventListener('shown.bs.tab', function(e) {
                var target = (e.target.getAttribute('data-bs-target') || '').replace('#tab-', '');
                if (target) {
                    returnTab.value = target;
                    if (history.replaceState) history.replaceState(null, '', '?einheit_id=<?php echo (int)$einheit_id; ?>&tab=' + target);
                    var saveRow = document.getElementById('settingsSaveRow');
                    if (saveRow) saveRow.style.display = (target === 'fahrzeuge' || target === 'raeume' || target === 'einheit') ? 'none' : 'flex';
                }
            });
        });
    }
    var globalTabBtns = document.querySelectorAll('#globalSettingsTabs button[data-bs-toggle="tab"]');
    if (returnTab && globalTabBtns.length) {
        globalTabBtns.forEach(function(btn) {
            btn.addEventListener('shown.bs.tab', function(e) {
                var target = (e.target.getAttribute('data-bs-target') || '').replace('#tab-global-', '');
                if (target) {
                    returnTab.value = target;
                    if (history.replaceState) history.replaceState(null, '', '?tab=' + target);
                }
            });
        });
    }
    var container = document.getElementById('diveraGroupsContainer');
    var btnAdd = document.getElementById('btnAddGroup');
    if (container && btnAdd) {
        var rowTpl = function() {
            var div = document.createElement('div');
            div.className = 'input-group mb-2 divera-group-row';
            div.innerHTML = '<input type="number" class="form-control" name="divera_group_id[]" placeholder="ID (leer = keine)" min="0">' +
                '<input type="text" class="form-control" name="divera_group_name[]" placeholder="Name der Gruppe">' +
                '<button type="button" class="btn btn-outline-danger btn-remove-group" title="Gruppe entfernen"><i class="fas fa-trash"></i></button>';
            return div;
        };
        btnAdd.addEventListener('click', function() { container.appendChild(rowTpl()); });
        container.addEventListener('click', function(e) {
            if (e.target.closest('.btn-remove-group')) e.target.closest('.divera-group-row').remove();
        });
    }
});
<?php if ($einheit_id > 0): ?>
function openVehicleModal() {
    document.getElementById('vehicleModalTitle').textContent = 'Neues Fahrzeug';
    document.getElementById('vehicle_id').value = '';
    document.getElementById('vehicle_name').value = '';
    document.getElementById('vehicle_description').value = '';
    document.getElementById('vehicle_sort_order').value = '0';
    document.getElementById('vehicle_is_active').checked = true;
    document.getElementById('vehicle_action').value = 'add';
    document.getElementById('vehicleSubmitBtn').textContent = 'Speichern';
    new bootstrap.Modal(document.getElementById('vehicleModal')).show();
}
function editVehicle(btn) {
    var d = btn.dataset;
    document.getElementById('vehicleModalTitle').textContent = 'Fahrzeug bearbeiten';
    document.getElementById('vehicle_id').value = d.vehicleId || '';
    document.getElementById('vehicle_name').value = d.name || '';
    document.getElementById('vehicle_description').value = d.desc || '';
    document.getElementById('vehicle_sort_order').value = d.sort || 0;
    document.getElementById('vehicle_is_active').checked = d.active == 1;
    document.getElementById('vehicle_action').value = 'edit';
    document.getElementById('vehicleSubmitBtn').textContent = 'Aktualisieren';
    new bootstrap.Modal(document.getElementById('vehicleModal')).show();
}
function openRoomModal() {
    document.getElementById('roomModalTitle').textContent = 'Neuer Raum';
    document.getElementById('room_id').value = '';
    document.getElementById('room_name').value = '';
    document.getElementById('room_description').value = '';
    document.getElementById('room_sort_order').value = '0';
    document.getElementById('room_is_active').checked = true;
    document.getElementById('room_action').value = 'add';
    document.getElementById('roomSubmitBtn').textContent = 'Speichern';
    new bootstrap.Modal(document.getElementById('roomModal')).show();
}
function editRoom(btn) {
    var d = btn.dataset;
    document.getElementById('roomModalTitle').textContent = 'Raum bearbeiten';
    document.getElementById('room_id').value = d.roomId || '';
    document.getElementById('room_name').value = d.name || '';
    document.getElementById('room_description').value = d.desc || '';
    document.getElementById('room_sort_order').value = d.sort || 0;
    document.getElementById('room_is_active').checked = d.active == 1;
    document.getElementById('room_action').value = 'edit';
    document.getElementById('roomSubmitBtn').textContent = 'Aktualisieren';
    new bootstrap.Modal(document.getElementById('roomModal')).show();
}
<?php endif; ?>
document.getElementById('btn_divera_key_loeschen')?.addEventListener('click', function() {
    if (confirm('Divera Access Key für diese Einheit wirklich löschen? Danach werden keine Einsätze mehr von Divera für diese Einheit angezeigt.')) {
        document.getElementById('divera_access_key_clear').value = '1';
        document.getElementById('divera_access_key').value = '';
        document.getElementById('mainForm').submit();
    }
});
document.getElementById('btn_list_printers')?.addEventListener('click', function() {
    var btn = this;
    var out = document.getElementById('printers_list');
    out.style.display = 'block';
    out.innerHTML = '<span class="text-muted"><i class="fas fa-spinner fa-spin"></i> Lade Drucker...</span>';
    btn.disabled = true;
    fetch('../api/list-printers.php?einheit_id=<?php echo (int)$einheit_id; ?>')
        .then(function(r) { return r.json(); })
        .then(function(data) {
            btn.disabled = false;
            if (data.success && data.printers && data.printers.length > 0) {
                var html = '<strong>Verfügbare Drucker:</strong> ';
                data.printers.forEach(function(p) {
                    var def = (data.default_printer === p.name) ? ' (Standard)' : '';
                    var safe = (p.name || '').replace(/"/g, '&quot;');
                    html += '<span class="badge bg-secondary me-1" style="cursor:pointer" data-name="' + safe + '" role="button">' + (p.name || '').replace(/</g, '&lt;') + def + '</span> ';
                });
                out.innerHTML = html;
                out.querySelectorAll('[data-name]').forEach(function(el) {
                    el.addEventListener('click', function() {
                        document.getElementById('printer_destination').value = this.getAttribute('data-name').replace(/&quot;/g, '"');
                    });
                });
            } else {
                var html = '<span class="text-warning">' + (data.message || 'Keine Drucker gefunden.') + '</span>';
                if (data.configured_printer) {
                    html += '<div class="mt-2 text-muted">Aktuell eingetragen: <code>' + (data.configured_printer || '').replace(/</g, '&lt;') + '</code></div>';
                }
                out.innerHTML = html;
            }
        })
        .catch(function() {
            btn.disabled = false;
            out.innerHTML = '<span class="text-danger">Fehler beim Laden. CUPS-Server erreichbar?</span>';
        });
});
document.getElementById('btn_test_print')?.addEventListener('click', function() {
    var btn = this;
    var out = document.getElementById('test_print_result');
    if (!out) return;
    btn.disabled = true;
    out.innerHTML = '<span class="text-muted"><i class="fas fa-spinner fa-spin"></i> Sende Testdruck...</span>';
    fetch('../api/print-test.php?einheit_id=<?php echo (int)$einheit_id; ?>')
        .then(function(r) { return r.json(); })
        .then(function(data) {
            btn.disabled = false;
            if (data.success) {
                var jobInfo = (data.debug && data.debug.lp_output) ? ' <span class="text-muted">(' + String(data.debug.lp_output).replace(/</g, '&lt;').trim() + ')</span>' : '';
                out.innerHTML = '<span class="text-success"><i class="fas fa-check me-1"></i>' + (data.message || 'Testdruck gesendet.') + jobInfo + '</span>';
            } else {
                var msg = (data.message || 'Fehler');
                if (data.debug && data.debug.output) {
                    msg += ' Ausgabe: ' + String(data.debug.output).replace(/</g, '&lt;').substring(0, 200);
                    if (data.debug.output.length > 200) msg += '…';
                } else if (data.debug) {
                    if (data.debug.curl_error) {
                        msg += ' [cURL: ' + String(data.debug.curl_error).replace(/</g, '&lt;').substring(0, 80) + ']';
                    } else if (data.debug.http_code) {
                        msg += ' (HTTP ' + data.debug.http_code + (data.debug.response ? ': ' + String(data.debug.response).replace(/</g, '&lt;').substring(0, 80) : '') + ')';
                    } else {
                        msg += ' (Drucker: ' + (data.debug.printer || '') + ', CUPS: ' + (data.debug.cups_server || '') + ')';
                    }
                }
                out.innerHTML = '<span class="text-danger" title="' + (data.debug && (data.debug.command || data.debug.curl_error) ? String(data.debug.command || data.debug.curl_error || '').replace(/"/g, '&quot;') : '') + '"><i class="fas fa-exclamation-triangle me-1"></i>' + msg + '</span>';
            }
        })
        .catch(function() {
            btn.disabled = false;
            out.innerHTML = '<span class="text-danger"><i class="fas fa-exclamation-triangle me-1"></i>Verbindungsfehler</span>';
        });
});
document.getElementById('btn_print_diagnose')?.addEventListener('click', function() {
    var btn = this;
    var out = document.getElementById('print_diagnose_output');
    if (!out) return;
    btn.disabled = true;
    out.style.display = 'block';
    out.textContent = 'Lade Diagnose...';
    fetch('../api/print-diagnose.php?einheit_id=<?php echo (int)$einheit_id; ?>')
        .then(function(r) { return r.json(); })
        .then(function(data) {
            btn.disabled = false;
            if (data.success) {
                var d = data.diagnose || {};
                var txt = '=== Drucker: ' + (data.printer || '') + ' | CUPS: ' + (data.cups_server || '') + ' ===\n\n';
                txt += '--- lpstat -v (Drucker) ---\n' + (d.lpstat_v || '(leer)') + '\n\n';
                txt += '--- lpstat -t (Status) ---\n' + (d.lpstat_t || '(leer)') + '\n\n';
                txt += '--- lpq -a (Warteschlange) ---\n' + (d.lpq || '(leer)');
                out.textContent = txt;
            } else {
                out.textContent = 'Fehler: ' + (data.message || 'Unbekannt');
            }
        })
        .catch(function() {
            btn.disabled = false;
            out.textContent = 'Verbindungsfehler beim Laden der Diagnose.';
        });
});
</script>
</body>
</html>


