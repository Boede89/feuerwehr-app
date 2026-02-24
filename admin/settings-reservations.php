<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once __DIR__ . '/../includes/einheiten-setup.php';
require_once '../includes/einheit-settings-helper.php';

if (!$db) {
    die('Datenbankverbindung fehlgeschlagen.');
}
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('Location: ../login.php');
    exit;
}
if (!hasAdminPermission()) {
    header('Location: ../login.php?error=access_denied');
    exit;
}

$message = '';
$error = '';
$einheit_id = isset($_GET['einheit_id']) ? (int)$_GET['einheit_id'] : (isset($_POST['einheit_id']) ? (int)$_POST['einheit_id'] : 0);
$einheit = null;
if ($einheit_id > 0) {
    try {
        $stmt = $db->prepare("SELECT id, name FROM einheiten WHERE id = ?");
        $stmt->execute([$einheit_id]);
        $einheit = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'fahrzeug';
if (!in_array($active_tab, ['fahrzeug', 'raum'])) {
    $active_tab = 'fahrzeug';
}

// Sicherstellen, dass email_notifications Spalte existiert
try {
    $db->exec("ALTER TABLE users ADD COLUMN email_notifications TINYINT(1) DEFAULT 0");
} catch (Exception $e) {
    // Spalte existiert bereits, ignoriere Fehler
}

$settings = load_settings_for_einheit($db, $einheit_id > 0 ? $einheit_id : null);

// Kein Fallback auf globale Divera-Einstellungen – jede Einheit hat eigene Konfiguration.

// Benutzer für E-Mail-Benachrichtigungen laden (nur Benutzer der aktuellen Einheit)
$users = [];
try {
    ensure_einheit_id_in_tables($db);
    $amern_id = get_einheit_amern_id($db);
    if ($amern_id > 0) {
        try { $db->exec("UPDATE users SET einheit_id = " . (int)$amern_id . " WHERE einheit_id IS NULL"); } catch (Exception $e) {}
    }
    $where = "is_active = 1";
    $params = [];
    if ($einheit_id > 0) {
        if ($amern_id > 0 && $amern_id === $einheit_id) {
            $where .= " AND (einheit_id = ? OR einheit_id IS NULL)";
        } else {
            $where .= " AND einheit_id = ?";
        }
        $params[] = $einheit_id;
    }
    $cols = "id, first_name, last_name, email, is_admin, email_notifications";
    try {
        $stmt = $db->prepare("SELECT id, first_name, last_name, email, user_role, is_admin, email_notifications FROM users WHERE $where ORDER BY first_name, last_name");
        $stmt->execute($params);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $stmt = $db->prepare("SELECT $cols FROM users WHERE $where ORDER BY first_name, last_name");
        $stmt->execute($params);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($users as &$u) { $u['user_role'] = $u['is_admin'] ? 'admin' : 'user'; }
    }
} catch (Exception $e) {
    $error = "Fehler beim Laden der Benutzer: " . $e->getMessage();
}

// Aktuelle E-Mail-Benachrichtigungseinstellungen laden (Fahrzeug vs. Raum)
$notification_users = [];
$room_notification_users = [];
if ($einheit_id > 0) {
    $json = $settings['reservation_notification_user_ids'] ?? '';
    if ($json !== '') {
        $dec = json_decode($json, true);
        $notification_users = is_array($dec) ? array_map('intval', $dec) : [];
    }
    $json_room = $settings['room_reservation_notification_user_ids'] ?? '';
    if ($json_room !== '') {
        $dec = json_decode($json_room, true);
        $room_notification_users = is_array($dec) ? array_map('intval', $dec) : [];
    } else {
        $room_notification_users = $notification_users; // Fallback: gleiche wie Fahrzeug
    }
} else {
    try {
        $stmt = $db->query("SELECT id FROM users WHERE email_notifications = 1 AND is_active = 1");
        $notification_users = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $json_room = $settings['room_reservation_notification_user_ids'] ?? '';
        if ($json_room !== '') {
            $dec = json_decode($json_room, true);
            $room_notification_users = is_array($dec) ? array_map('intval', $dec) : $notification_users;
        } else {
            $room_notification_users = $notification_users;
        }
    } catch (Exception $e) {}
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($active_tab === 'fahrzeug' || isset($_POST['tab']) && $_POST['tab'] === 'fahrzeug')) {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Ungültiger Sicherheitstoken.';
    } else {
        try {
            // Keine Transaktion: DDL (CREATE/ALTER TABLE) in save_setting_for_einheit führt zu implizitem Commit in MySQL
            $veh = [
                'vehicle_sort_mode' => sanitize_input($_POST['vehicle_sort_mode'] ?? 'manual'),
                'divera_reservation_enabled' => isset($_POST['divera_reservation_enabled']) ? '1' : '0',
                'google_calendar_reservation_enabled' => isset($_POST['google_calendar_reservation_enabled']) ? '1' : '0',
                'divera_reservation_default_group_id' => trim((string)($_POST['divera_reservation_default_group_id'] ?? '')),
            ];

            if ($einheit_id > 0) {
                save_settings_bulk_for_einheit($db, $einheit_id, $veh);
            } else {
                $stmtUpsert = $db->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
                foreach ($veh as $k => $v) {
                    $stmtUpsert->execute([$k, $v]);
                }
            }

            $notification_users_post = $_POST['notification_users'] ?? [];
            if ($einheit_id > 0) {
                save_setting_for_einheit($db, $einheit_id, 'reservation_notification_user_ids', json_encode(array_values(array_map('intval', $notification_users_post))));
                $notification_users = $notification_users_post;
            } else {
                $stmt = $db->prepare("UPDATE users SET email_notifications = 0");
                $stmt->execute();
                if (!empty($notification_users_post)) {
                    $placeholders = str_repeat('?,', count($notification_users_post) - 1) . '?';
                    $stmt = $db->prepare("UPDATE users SET email_notifications = 1 WHERE id IN ($placeholders)");
                    $stmt->execute($notification_users_post);
                }
                $stmt = $db->query("SELECT id FROM users WHERE email_notifications = 1 AND is_active = 1");
                $notification_users = $stmt->fetchAll(PDO::FETCH_COLUMN);
            }

            $message = 'Fahrzeugreservierungs-Einstellungen gespeichert.';

            $settings = load_settings_for_einheit($db, $einheit_id > 0 ? $einheit_id : null);
            if ($einheit_id > 0) {
                $json = $settings['reservation_notification_user_ids'] ?? '';
                $notification_users = ($json !== '' && ($dec = json_decode($json, true)) && is_array($dec)) ? array_map('intval', $dec) : [];
            }
        } catch (Exception $e) {
            $error = 'Fehler beim Speichern: ' . $e->getMessage();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($active_tab === 'raum' || (isset($_POST['tab']) && $_POST['tab'] === 'raum'))) {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Ungültiger Sicherheitstoken.';
    } else {
        try {
            // Keine Transaktion: DDL in save_setting_for_einheit führt zu implizitem Commit in MySQL
            $room_settings = [
                'room_sort_mode' => sanitize_input($_POST['room_sort_mode'] ?? 'manual'),
                'room_divera_reservation_enabled' => isset($_POST['room_divera_reservation_enabled']) ? '1' : '0',
                'room_google_calendar_reservation_enabled' => isset($_POST['room_google_calendar_reservation_enabled']) ? '1' : '0',
                'room_divera_reservation_default_group_id' => trim((string)($_POST['room_divera_reservation_default_group_id'] ?? '')),
            ];

            if ($einheit_id > 0) {
                save_settings_bulk_for_einheit($db, $einheit_id, $room_settings);
                $room_notification_post = $_POST['room_notification_users'] ?? [];
                save_setting_for_einheit($db, $einheit_id, 'room_reservation_notification_user_ids', json_encode(array_values(array_map('intval', $room_notification_post))));
                $room_notification_users = $room_notification_post;
            } else {
                $stmtUpsert = $db->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
                foreach ($room_settings as $k => $v) {
                    $stmtUpsert->execute([$k, $v]);
                }
                $room_notification_post = $_POST['room_notification_users'] ?? [];
                $stmtUpsert->execute(['room_reservation_notification_user_ids', json_encode(array_values(array_map('intval', $room_notification_post)))]);
                $room_notification_users = $room_notification_post;
            }

            $message = 'Raumreservierungs-Einstellungen gespeichert.';

            $settings = load_settings_for_einheit($db, $einheit_id > 0 ? $einheit_id : null);
            $json_room = $settings['room_reservation_notification_user_ids'] ?? '';
            if ($json_room !== '' && ($dec = json_decode($json_room, true)) && is_array($dec)) {
                $room_notification_users = array_map('intval', $dec);
            }
        } catch (Exception $e) {
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
    <title>Einstellungen – Reservierungen</title>
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
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0"><i class="fas fa-calendar-check"></i> Einstellungen – Reservierungen</h1>
        <a href="<?php echo $einheit_id > 0 ? 'settings-einheit.php?id=' . (int)$einheit_id : 'settings.php'; ?>" class="btn btn-outline-secondary"><i class="fas fa-arrow-left"></i> Zurück</a>
    </div>
    <?php if ($message) echo show_success($message); ?>
    <?php if ($error) echo show_error($error); ?>

    <ul class="nav nav-tabs mb-4">
        <li class="nav-item">
            <a class="nav-link <?php echo $active_tab === 'fahrzeug' ? 'active' : ''; ?>" href="?tab=fahrzeug<?php if ($einheit_id > 0): ?>&einheit_id=<?php echo (int)$einheit_id; ?><?php endif; ?>">
                <i class="fas fa-truck"></i> Fahrzeugreservierung
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $active_tab === 'raum' ? 'active' : ''; ?>" href="?tab=raum<?php if ($einheit_id > 0): ?>&einheit_id=<?php echo (int)$einheit_id; ?><?php endif; ?>">
                <i class="fas fa-door-open"></i> Raumreservierung
            </a>
        </li>
    </ul>

    <?php if ($active_tab === 'fahrzeug'): ?>
    <form method="POST" action="?tab=fahrzeug<?php if ($einheit_id > 0): ?>&einheit_id=<?php echo (int)$einheit_id; ?><?php endif; ?>">
        <input type="hidden" name="tab" value="fahrzeug">
        <?php if ($einheit_id > 0): ?><input type="hidden" name="einheit_id" value="<?php echo (int)$einheit_id; ?>"><?php endif; ?>
        <div class="card mb-4">
            <div class="card-header"><i class="fas fa-list-ol"></i> Anzeige und Sortierung</div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label">Sortier-Modus</label>
                    <select class="form-select" name="vehicle_sort_mode">
                        <option value="manual" <?php echo (($settings['vehicle_sort_mode'] ?? 'manual')==='manual')?'selected':''; ?>>Manuelle Reihenfolge</option>
                        <option value="name" <?php echo (($settings['vehicle_sort_mode'] ?? '')==='name')?'selected':''; ?>>Alphabetisch nach Name</option>
                        <option value="created" <?php echo (($settings['vehicle_sort_mode'] ?? '')==='created')?'selected':''; ?>>Nach Erstellungsdatum</option>
                    </select>
                </div>
                <div class="form-text">
                    Reihenfolge kann in der <a href="<?php echo $einheit_id > 0 ? 'settings-global.php?einheit_id=' . (int)$einheit_id . '&tab=fahrzeuge' : 'vehicles.php'; ?>" target="_blank">Fahrzeugverwaltung</a> angepasst werden.
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header"><i class="fas fa-calendar-plus"></i> Terminübergabe bei Genehmigung und Löschung</div>
            <div class="card-body">
                <p class="text-muted small mb-3">Wählen Sie, welche Kalender-Systeme bei Genehmigung und Löschung von <strong>Fahrzeug</strong>reservierungen verwendet werden sollen. (Unabhängig von den Raum-Einstellungen.)</p>
                <div class="mb-3">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="divera_reservation_enabled" id="divera_reservation_enabled" value="1" <?php echo (($settings['divera_reservation_enabled'] ?? '0') === '1') ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="divera_reservation_enabled">
                            <strong>Divera 24/7</strong> – Termine an Divera senden und beim Löschen dort entfernen
                        </label>
                    </div>
                </div>
                <div class="mb-3">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="google_calendar_reservation_enabled" id="google_calendar_reservation_enabled" value="1" <?php echo (($settings['google_calendar_reservation_enabled'] ?? '0') === '1') ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="google_calendar_reservation_enabled">
                            <strong>Google Kalender</strong> – Termine im Google Kalender anlegen und beim Löschen dort entfernen
                        </label>
                    </div>
                </div>
                <div class="form-text">Beide Optionen können aktiviert sein. Bei Genehmigung werden Termine an die aktivierten Systeme gesendet; beim Löschen werden sie dort entfernt.</div>
                <?php
                $divera_groups = [];
                if (!empty($settings['divera_reservation_groups'])) {
                    $dec = json_decode($settings['divera_reservation_groups'], true);
                    $divera_groups = is_array($dec) ? $dec : [];
                }
                $default_group_id = trim((string)($settings['divera_reservation_default_group_id'] ?? ''));
                ?>
                <div class="mb-3 mt-3">
                    <label class="form-label">Standard-Empfänger-Gruppe (Divera)</label>
                    <?php if (!empty($divera_groups)): ?>
                    <select class="form-select" name="divera_reservation_default_group_id">
                        <option value="">– Keine Vorauswahl –</option>
                        <?php foreach ($divera_groups as $g):
                            $gid = (int)($g['id'] ?? 0);
                            $gval = $gid > 0 ? (string)$gid : '0';
                            $gname = htmlspecialchars($g['name'] ?? ($gid > 0 ? 'Gruppe ' . $gid : 'Alle des Standortes'));
                            $glabel = $gid > 0 ? $gname . ' (ID: ' . $gid . ')' : $gname . ' (keine Gruppen-ID)';
                        ?>
                        <option value="<?php echo $gval; ?>" <?php echo $default_group_id === $gval ? 'selected' : ''; ?>>
                            <?php echo $glabel; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Diese Gruppe wird beim Genehmigen standardmäßig ausgewählt. Kann beim Genehmigen geändert werden.</div>
                    <?php else: ?>
                    <p class="text-muted small mb-0">Bitte zuerst in den <a href="settings-global.php?einheit_id=<?php echo (int)$einheit_id; ?>&tab=divera">Einstellungen (Divera-Tab)</a> die Divera-Gruppen für diese Einheit konfigurieren.</p>
                    <input type="hidden" name="divera_reservation_default_group_id" value="">
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header bg-info text-white">
                <i class="fas fa-envelope"></i> E-Mail-Benachrichtigungen
            </div>
            <div class="card-body">
                <p class="text-muted mb-3">
                    Wählen Sie die Benutzer aus, die per E-Mail über neue Fahrzeugreservierungen benachrichtigt werden sollen.
                </p>

                <div class="row">
                    <?php foreach ($users as $user): ?>
                        <div class="col-md-6 col-lg-4 mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox"
                                       name="notification_users[]"
                                       value="<?php echo htmlspecialchars($user['id']); ?>"
                                       id="user_<?php echo htmlspecialchars($user['id']); ?>"
                                       <?php echo in_array($user['id'], $notification_users) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="user_<?php echo htmlspecialchars($user['id']); ?>">
                                    <strong><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></strong>
                                    <br>
                                    <small class="text-muted">
                                        <?php echo htmlspecialchars($user['email']); ?>
                                        <span class="badge bg-<?php echo ($user['is_admin'] || $user['user_role'] === 'admin') ? 'danger' : 'primary'; ?> ms-1">
                                            <?php echo ($user['is_admin'] || $user['user_role'] === 'admin') ? 'Admin' : 'User'; ?>
                                        </span>
                                    </small>
                                </label>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if (empty($users)): ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>Keine Benutzer gefunden.
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="d-flex justify-content-end">
            <button class="btn btn-primary" type="submit"><i class="fas fa-save"></i> Speichern</button>
        </div>
        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
    </form>
    <?php elseif ($active_tab === 'raum'): ?>
    <form method="POST" action="?tab=raum<?php if ($einheit_id > 0): ?>&einheit_id=<?php echo (int)$einheit_id; ?><?php endif; ?>">
        <input type="hidden" name="tab" value="raum">
        <?php if ($einheit_id > 0): ?><input type="hidden" name="einheit_id" value="<?php echo (int)$einheit_id; ?>"><?php endif; ?>
        <div class="card mb-4">
            <div class="card-header"><i class="fas fa-list-ol"></i> Anzeige und Sortierung</div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label">Sortier-Modus</label>
                    <select class="form-select" name="room_sort_mode">
                        <option value="manual" <?php echo (($settings['room_sort_mode'] ?? 'manual')==='manual')?'selected':''; ?>>Manuelle Reihenfolge</option>
                        <option value="name" <?php echo (($settings['room_sort_mode'] ?? '')==='name')?'selected':''; ?>>Alphabetisch nach Name</option>
                        <option value="created" <?php echo (($settings['room_sort_mode'] ?? '')==='created')?'selected':''; ?>>Nach Erstellungsdatum</option>
                    </select>
                </div>
                <div class="form-text">
                    Reihenfolge kann in der <a href="<?php echo $einheit_id > 0 ? 'settings-global.php?einheit_id=' . (int)$einheit_id . '&tab=raeume' : '#'; ?>" target="_blank">Räume-Verwaltung</a> angepasst werden.
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header"><i class="fas fa-calendar-plus"></i> Terminübergabe bei Genehmigung und Löschung</div>
            <div class="card-body">
                <p class="text-muted small mb-3">Wählen Sie, welche Kalender-Systeme bei Genehmigung und Löschung von <strong>Raum</strong>reservierungen verwendet werden sollen. (Unabhängig von den Fahrzeug-Einstellungen.)</p>
                <div class="mb-3">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="room_divera_reservation_enabled" id="room_divera_reservation_enabled" value="1" <?php echo (($settings['room_divera_reservation_enabled'] ?? '0') === '1') ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="room_divera_reservation_enabled">
                            <strong>Divera 24/7</strong> – Termine an Divera senden und beim Löschen dort entfernen
                        </label>
                    </div>
                </div>
                <div class="mb-3">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="room_google_calendar_reservation_enabled" id="room_google_calendar_reservation_enabled" value="1" <?php echo (($settings['room_google_calendar_reservation_enabled'] ?? '0') === '1') ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="room_google_calendar_reservation_enabled">
                            <strong>Google Kalender</strong> – Termine im Google Kalender anlegen und beim Löschen dort entfernen
                        </label>
                    </div>
                </div>
                <div class="form-text">Beide Optionen können aktiviert sein. Bei Genehmigung werden Termine an die aktivierten Systeme gesendet; beim Löschen werden sie dort entfernt.</div>
                <?php
                $divera_groups_room = [];
                if (!empty($settings['divera_reservation_groups'])) {
                    $dec = json_decode($settings['divera_reservation_groups'], true);
                    $divera_groups_room = is_array($dec) ? $dec : [];
                }
                $default_group_id_room = trim((string)($settings['room_divera_reservation_default_group_id'] ?? ''));
                ?>
                <div class="mb-3 mt-3">
                    <label class="form-label">Standard-Empfänger-Gruppe (Divera)</label>
                    <?php if (!empty($divera_groups_room)): ?>
                    <select class="form-select" name="room_divera_reservation_default_group_id">
                        <option value="">– Keine Vorauswahl –</option>
                        <?php foreach ($divera_groups_room as $g):
                            $gid = (int)($g['id'] ?? 0);
                            $gval = $gid > 0 ? (string)$gid : '0';
                            $gname = htmlspecialchars($g['name'] ?? ($gid > 0 ? 'Gruppe ' . $gid : 'Alle des Standortes'));
                            $glabel = $gid > 0 ? $gname . ' (ID: ' . $gid . ')' : $gname . ' (keine Gruppen-ID)';
                        ?>
                        <option value="<?php echo $gval; ?>" <?php echo $default_group_id_room === $gval ? 'selected' : ''; ?>>
                            <?php echo $glabel; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Diese Gruppe wird beim Genehmigen standardmäßig ausgewählt. Kann beim Genehmigen geändert werden.</div>
                    <?php else: ?>
                    <p class="text-muted small mb-0">Bitte zuerst in den <a href="settings-global.php?einheit_id=<?php echo (int)$einheit_id; ?>&tab=divera">Einstellungen (Divera-Tab)</a> die Divera-Gruppen für diese Einheit konfigurieren.</p>
                    <input type="hidden" name="room_divera_reservation_default_group_id" value="">
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header bg-info text-white">
                <i class="fas fa-envelope"></i> E-Mail-Benachrichtigungen
            </div>
            <div class="card-body">
                <p class="text-muted mb-3">
                    Wählen Sie die Benutzer aus, die per E-Mail über neue Raumreservierungen benachrichtigt werden sollen.
                </p>

                <div class="row">
                    <?php foreach ($users as $user): ?>
                        <div class="col-md-6 col-lg-4 mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox"
                                       name="room_notification_users[]"
                                       value="<?php echo htmlspecialchars($user['id']); ?>"
                                       id="room_user_<?php echo htmlspecialchars($user['id']); ?>"
                                       <?php echo in_array($user['id'], $room_notification_users) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="room_user_<?php echo htmlspecialchars($user['id']); ?>">
                                    <strong><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></strong>
                                    <br>
                                    <small class="text-muted">
                                        <?php echo htmlspecialchars($user['email']); ?>
                                        <span class="badge bg-<?php echo ($user['is_admin'] || ($user['user_role'] ?? '') === 'admin') ? 'danger' : 'primary'; ?> ms-1">
                                            <?php echo ($user['is_admin'] || ($user['user_role'] ?? '') === 'admin') ? 'Admin' : 'User'; ?>
                                        </span>
                                    </small>
                                </label>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if (empty($users)): ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>Keine Benutzer gefunden.
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="d-flex justify-content-end">
            <button class="btn btn-primary" type="submit"><i class="fas fa-save"></i> Speichern</button>
        </div>
        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
    </form>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
