<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once __DIR__ . '/../includes/einheiten-setup.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header("Location: ../login.php");
    exit;
}
if (!hasAdminPermission()) {
    header("Location: ../login.php?error=access_denied");
    exit;
}

$einheit_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$einheit_id) {
    header("Location: settings-einheiten.php");
    exit;
}

if (!user_has_einheit_access($_SESSION['user_id'], $einheit_id)) {
    header("Location: settings-einheiten.php?error=access_denied");
    exit;
}

$message = '';
$error = '';

// Einheit laden
$einheit = null;
try {
    $stmt = $db->prepare("SELECT * FROM einheiten WHERE id = ?");
    $stmt->execute([$einheit_id]);
    $einheit = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {}
if (!$einheit) {
    header("Location: settings-einheiten.php?error=not_found");
    exit;
}

// Gruppen-Tabellen sicherstellen
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS member_groups (
            id INT AUTO_INCREMENT PRIMARY KEY,
            einheit_id INT NOT NULL,
            group_name VARCHAR(120) NOT NULL,
            description TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_group_per_einheit (einheit_id, group_name),
            INDEX idx_member_groups_einheit (einheit_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $db->exec("
        CREATE TABLE IF NOT EXISTS member_group_members (
            group_id INT NOT NULL,
            member_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (group_id, member_id),
            INDEX idx_mgm_member (member_id),
            CONSTRAINT fk_mgm_group FOREIGN KEY (group_id) REFERENCES member_groups(id) ON DELETE CASCADE,
            CONSTRAINT fk_mgm_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) {
    // ignore
}

// Neuer Benutzer
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_user') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Ungültiger Sicherheitstoken.";
    } else {
        $username = sanitize_input($_POST['username'] ?? '');
        $email = sanitize_input($_POST['email'] ?? '');
        $first_name = sanitize_input($_POST['first_name'] ?? '');
        $last_name = sanitize_input($_POST['last_name'] ?? '');
        $password = $_POST['password'] ?? '';
        $can_reservations = isset($_POST['can_reservations']) ? 1 : 0;
        $can_atemschutz = isset($_POST['can_atemschutz']) ? 1 : 0;
        $can_members = isset($_POST['can_members']) ? 1 : 0;
        $can_ric = isset($_POST['can_ric']) ? 1 : 0;
        $can_courses = isset($_POST['can_courses']) ? 1 : 0;
        $can_forms = isset($_POST['can_forms']) ? 1 : 0;
        $can_forms_fill = isset($_POST['can_forms_fill']) ? 1 : 0;
        $can_auswertung = isset($_POST['can_auswertung']) ? 1 : 0;
        $can_reservations_readonly = isset($_POST['can_reservations_readonly']) ? 1 : 0;
        $can_atemschutz_readonly = isset($_POST['can_atemschutz_readonly']) ? 1 : 0;
        $can_members_readonly = isset($_POST['can_members_readonly']) ? 1 : 0;
        $can_ric_readonly = isset($_POST['can_ric_readonly']) ? 1 : 0;
        $can_courses_readonly = isset($_POST['can_courses_readonly']) ? 1 : 0;
        $can_forms_readonly = isset($_POST['can_forms_readonly']) ? 1 : 0;
        if (empty($username) || empty($email) || empty($first_name) || empty($last_name) || empty($password)) {
            $error = "Alle Pflichtfelder sind erforderlich.";
        } elseif (!validate_email($email)) {
            $error = "Bitte geben Sie eine gültige E-Mail-Adresse ein.";
        } else {
            try {
                $stmt_check = $db->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
                $stmt_check->execute([$username, $email]);
                if ($stmt_check->fetch()) {
                    $error = "Benutzername oder E-Mail existiert bereits.";
                } else {
                    $password_hash = hash_password($password);
                    try { $db->exec("ALTER TABLE users ADD COLUMN can_forms_fill TINYINT(1) DEFAULT 0"); } catch (Exception $e) {}
                    try { $db->exec("ALTER TABLE users ADD COLUMN can_auswertung TINYINT(1) DEFAULT 0"); } catch (Exception $e) {}
                    foreach (['can_reservations_readonly','can_atemschutz_readonly','can_members_readonly','can_ric_readonly','can_courses_readonly','can_forms_readonly'] as $c) { try { $db->exec("ALTER TABLE users ADD COLUMN $c TINYINT(1) DEFAULT 0"); } catch (Exception $e) {} }
                    $stmt = $db->prepare("INSERT INTO users (username, email, password_hash, first_name, last_name, user_role, user_type, einheit_id, is_active, can_reservations, can_atemschutz, can_members, can_ric, can_courses, can_forms, can_forms_fill, can_auswertung, can_reservations_readonly, can_atemschutz_readonly, can_members_readonly, can_ric_readonly, can_courses_readonly, can_forms_readonly, can_users, can_settings, can_vehicles, email_notifications) VALUES (?, ?, ?, ?, ?, 'user', 'user', ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 0, 0)");
                    $stmt->execute([$username, $email, $password_hash, $first_name, $last_name, $einheit_id, $can_reservations, $can_atemschutz, $can_members, $can_ric, $can_courses, $can_forms, $can_forms_fill, $can_auswertung, $can_reservations_readonly, $can_atemschutz_readonly, $can_members_readonly, $can_ric_readonly, $can_courses_readonly, $can_forms_readonly]);
                    $new_id = $db->lastInsertId();
                    try {
                        $stmt_m = $db->prepare("INSERT INTO members (user_id, first_name, last_name, email, einheit_id) VALUES (?, ?, ?, ?, ?)");
                        $stmt_m->execute([$new_id, $first_name, $last_name, $email, $einheit_id]);
                    } catch (Exception $e) {}
                    log_activity($_SESSION['user_id'], 'user_added', "Benutzer '$username' für Einheit {$einheit['name']} angelegt");
                    header("Location: settings-einheit-users.php?id=" . $einheit_id . "&success=user_added");
                    exit;
                }
            } catch (Exception $e) {
                $error = "Fehler: " . $e->getMessage();
            }
        }
    }
}

// Gruppe anlegen/bearbeiten/löschen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['add_group', 'edit_group', 'delete_group'], true)) {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Ungültiger Sicherheitstoken.";
    } else {
        $action = $_POST['action'];
        $group_name = trim((string)($_POST['group_name'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $member_ids = $_POST['member_ids'] ?? [];
        if (!is_array($member_ids)) {
            $member_ids = [];
        }
        $member_ids = array_values(array_unique(array_filter(array_map('intval', $member_ids), function($id) {
            return $id > 0;
        })));

        try {
            if ($action === 'delete_group') {
                $group_id = (int)($_POST['group_id'] ?? 0);
                if ($group_id <= 0) {
                    $error = "Ungültige Gruppe.";
                } else {
                    $stmt = $db->prepare("DELETE FROM member_groups WHERE id = ? AND einheit_id = ?");
                    $stmt->execute([$group_id, $einheit_id]);
                    header("Location: settings-einheit-users.php?id=" . $einheit_id . "&success=group_deleted");
                    exit;
                }
            } else {
                if ($group_name === '') {
                    $error = "Bitte einen Gruppennamen angeben.";
                } else {
                    $db->beginTransaction();
                    $group_id = (int)($_POST['group_id'] ?? 0);
                    if ($action === 'add_group') {
                        $stmt = $db->prepare("INSERT INTO member_groups (einheit_id, group_name, description) VALUES (?, ?, ?)");
                        $stmt->execute([$einheit_id, $group_name, $description !== '' ? $description : null]);
                        $group_id = (int)$db->lastInsertId();
                    } else {
                        $stmt = $db->prepare("UPDATE member_groups SET group_name = ?, description = ? WHERE id = ? AND einheit_id = ?");
                        $stmt->execute([$group_name, $description !== '' ? $description : null, $group_id, $einheit_id]);
                    }

                    $stmt_del = $db->prepare("DELETE FROM member_group_members WHERE group_id = ?");
                    $stmt_del->execute([$group_id]);

                    if (!empty($member_ids)) {
                        $placeholders = implode(',', array_fill(0, count($member_ids), '?'));
                        $params = array_merge([$einheit_id], $member_ids);
                        $stmt_valid = $db->prepare("SELECT id FROM members WHERE einheit_id = ? AND id IN ($placeholders)");
                        $stmt_valid->execute($params);
                        $valid_member_ids = array_map('intval', $stmt_valid->fetchAll(PDO::FETCH_COLUMN));
                        $stmt_ins = $db->prepare("INSERT INTO member_group_members (group_id, member_id) VALUES (?, ?)");
                        foreach ($valid_member_ids as $member_id) {
                            $stmt_ins->execute([$group_id, $member_id]);
                        }
                    }

                    $db->commit();
                    header("Location: settings-einheit-users.php?id=" . $einheit_id . "&success=" . ($action === 'add_group' ? 'group_added' : 'group_updated'));
                    exit;
                }
            }
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $error = "Fehler: " . $e->getMessage();
        }
    }
}

// Benutzer bearbeiten (Berechtigungen)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_user') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Ungültiger Sicherheitstoken.";
    } else {
        $user_id = (int)($_POST['user_id'] ?? 0);
        $username = sanitize_input($_POST['username'] ?? '');
        $email = sanitize_input($_POST['email'] ?? '');
        $first_name = sanitize_input($_POST['first_name'] ?? '');
        $last_name = sanitize_input($_POST['last_name'] ?? '');
        if (!$user_id || !user_has_einheit_access($_SESSION['user_id'], $einheit_id)) {
            $error = "Ungültige Anfrage.";
        } elseif (empty($username) || empty($email) || empty($first_name) || empty($last_name)) {
            $error = "Name, Benutzername und E-Mail sind erforderlich.";
        } elseif (!validate_email($email)) {
            $error = "Bitte geben Sie eine gültige E-Mail-Adresse ein.";
        } else {
            $can_reservations = isset($_POST['can_reservations']) ? 1 : 0;
            $can_atemschutz = isset($_POST['can_atemschutz']) ? 1 : 0;
            $can_members = isset($_POST['can_members']) ? 1 : 0;
            $can_ric = isset($_POST['can_ric']) ? 1 : 0;
            $can_courses = isset($_POST['can_courses']) ? 1 : 0;
            $can_forms = isset($_POST['can_forms']) ? 1 : 0;
            $can_forms_fill = isset($_POST['can_forms_fill']) ? 1 : 0;
            $can_auswertung = isset($_POST['can_auswertung']) ? 1 : 0;
            $can_reservations_readonly = isset($_POST['can_reservations_readonly']) ? 1 : 0;
            $can_atemschutz_readonly = isset($_POST['can_atemschutz_readonly']) ? 1 : 0;
            $can_members_readonly = isset($_POST['can_members_readonly']) ? 1 : 0;
            $can_ric_readonly = isset($_POST['can_ric_readonly']) ? 1 : 0;
            $can_courses_readonly = isset($_POST['can_courses_readonly']) ? 1 : 0;
            $can_forms_readonly = isset($_POST['can_forms_readonly']) ? 1 : 0;
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            $password = $_POST['password'] ?? '';
            try {
                $stmt_check = $db->prepare("SELECT id FROM users WHERE (einheit_id = ? OR id IN (SELECT user_id FROM user_einheiten WHERE einheit_id = ?)) AND id = ?");
                $stmt_check->execute([$einheit_id, $einheit_id, $user_id]);
                if (!$stmt_check->fetch()) {
                    $error = "Benutzer gehört nicht zu dieser Einheit.";
                } else {
                    $stmt_dup = $db->prepare("SELECT id FROM users WHERE (username = ? OR email = ?) AND id != ?");
                    $stmt_dup->execute([$username, $email, $user_id]);
                    if ($stmt_dup->fetch()) {
                        $error = "Benutzername oder E-Mail existiert bereits.";
                    } else {
                    try { $db->exec("ALTER TABLE users ADD COLUMN can_forms_fill TINYINT(1) DEFAULT 0"); } catch (Exception $e) {}
                    try { $db->exec("ALTER TABLE users ADD COLUMN can_auswertung TINYINT(1) DEFAULT 0"); } catch (Exception $e) {}
                    foreach (['can_reservations_readonly','can_atemschutz_readonly','can_members_readonly','can_ric_readonly','can_courses_readonly','can_forms_readonly'] as $c) { try { $db->exec("ALTER TABLE users ADD COLUMN $c TINYINT(1) DEFAULT 0"); } catch (Exception $e) {} }
                    $divera_key_input = trim((string) ($_POST['divera_access_key'] ?? ''));
                    $divera_key_clear = isset($_POST['divera_access_key_clear']) && $_POST['divera_access_key_clear'] === '1';
                    $divera_key_to_save = null; // null = nicht ändern
                    if ($divera_key_clear) {
                        $divera_key_to_save = '';
                    } elseif ($divera_key_input !== '') {
                        $divera_key_to_save = $divera_key_input;
                    }
                    $stmt_cur = $db->prepare("SELECT divera_access_key FROM users WHERE id = ?");
                    $stmt_cur->execute([$user_id]);
                    $cur = $stmt_cur->fetch(PDO::FETCH_ASSOC);
                    $divera_key_final = ($divera_key_to_save !== null) ? $divera_key_to_save : trim((string) ($cur['divera_access_key'] ?? ''));
                    if (!empty($password)) {
                        $pw_hash = hash_password($password);
                        $stmt = $db->prepare("UPDATE users SET username=?, email=?, first_name=?, last_name=?, can_reservations=?, can_atemschutz=?, can_members=?, can_ric=?, can_courses=?, can_forms=?, can_forms_fill=?, can_auswertung=?, can_reservations_readonly=?, can_atemschutz_readonly=?, can_members_readonly=?, can_ric_readonly=?, can_courses_readonly=?, can_forms_readonly=?, is_active=?, password_hash=?, divera_access_key=? WHERE id=?");
                        $stmt->execute([$username, $email, $first_name, $last_name, $can_reservations, $can_atemschutz, $can_members, $can_ric, $can_courses, $can_forms, $can_forms_fill, $can_auswertung, $can_reservations_readonly, $can_atemschutz_readonly, $can_members_readonly, $can_ric_readonly, $can_courses_readonly, $can_forms_readonly, $is_active, $pw_hash, $divera_key_final ?: null, $user_id]);
                    } else {
                        $stmt = $db->prepare("UPDATE users SET username=?, email=?, first_name=?, last_name=?, can_reservations=?, can_atemschutz=?, can_members=?, can_ric=?, can_courses=?, can_forms=?, can_forms_fill=?, can_auswertung=?, can_reservations_readonly=?, can_atemschutz_readonly=?, can_members_readonly=?, can_ric_readonly=?, can_courses_readonly=?, can_forms_readonly=?, is_active=?, divera_access_key=? WHERE id=?");
                        $stmt->execute([$username, $email, $first_name, $last_name, $can_reservations, $can_atemschutz, $can_members, $can_ric, $can_courses, $can_forms, $can_forms_fill, $can_auswertung, $can_reservations_readonly, $can_atemschutz_readonly, $can_members_readonly, $can_ric_readonly, $can_courses_readonly, $can_forms_readonly, $is_active, $divera_key_final ?: null, $user_id]);
                    }
                    try {
                        $stmt_m = $db->prepare("UPDATE members SET first_name=?, last_name=?, email=? WHERE user_id=? AND einheit_id=?");
                        $stmt_m->execute([$first_name, $last_name, $email, $user_id, $einheit_id]);
                    } catch (Exception $e) {}
                    log_activity($_SESSION['user_id'], 'user_updated', "Benutzer '$username' (ID $user_id) für Einheit {$einheit['name']} aktualisiert");
                    header("Location: settings-einheit-users.php?id=" . $einheit_id . "&success=updated");
                    exit;
                    }
                }
            } catch (Exception $e) {
                $error = "Fehler: " . $e->getMessage();
            }
        }
    }
}

// Systembenutzer anlegen (nur Formulare, Autologin-Link)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_system_user') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Ungültiger Sicherheitstoken.";
    } else {
        $username = sanitize_input($_POST['username'] ?? '');
        $first_name = sanitize_input($_POST['first_name'] ?? '');
        $last_name = sanitize_input($_POST['last_name'] ?? '');
        if (empty($username)) {
            $error = "Benutzername ist erforderlich.";
        } else {
            try {
                try { $db->exec("ALTER TABLE users ADD COLUMN is_system_user TINYINT(1) DEFAULT 0"); } catch (Exception $e) {}
                try { $db->exec("ALTER TABLE users ADD COLUMN autologin_token VARCHAR(64) NULL"); } catch (Exception $e) {}
                try { $db->exec("ALTER TABLE users ADD COLUMN autologin_expires DATETIME NULL"); } catch (Exception $e) {}
                try { $db->exec("ALTER TABLE users MODIFY COLUMN email VARCHAR(255) NULL"); } catch (Exception $e) {}
                try { $db->exec("ALTER TABLE users MODIFY COLUMN password_hash VARCHAR(255) NULL"); } catch (Exception $e) {}
                $stmt_check = $db->prepare("SELECT id FROM users WHERE username = ?");
                $stmt_check->execute([$username]);
                if ($stmt_check->fetch()) {
                    $error = "Dieser Benutzername existiert bereits.";
                } else {
                    $autologin_token = bin2hex(random_bytes(32));
                    $validity = $_POST['autologin_validity'] ?? '90';
                    $autologin_expires = null;
                    if ($validity !== 'unlimited') {
                        $days = (int)$validity;
                        $autologin_expires = $days > 0 ? date('Y-m-d H:i:s', strtotime("+{$days} days")) : null;
                    }
                    try { $db->exec("ALTER TABLE users ADD COLUMN can_forms_fill TINYINT(1) DEFAULT 0"); } catch (Exception $e) {}
                    $can_reservations = isset($_POST['can_reservations']) ? 1 : 0;
                    $can_atemschutz = isset($_POST['can_atemschutz']) ? 1 : 0;
                    $can_forms_fill = isset($_POST['can_forms_fill']) ? 1 : 0;
                    $stmt = $db->prepare("INSERT INTO users (username, email, password_hash, first_name, last_name, user_role, is_active, is_admin, is_system_user, can_reservations, can_atemschutz, can_members, can_ric, can_courses, can_forms, can_forms_fill, can_users, can_settings, can_vehicles, email_notifications, autologin_token, autologin_expires, einheit_id) VALUES (?, NULL, NULL, ?, ?, 'user', 1, 0, 1, ?, ?, 0, 0, 0, 0, ?, 0, 0, 0, 0, ?, ?, ?)");
                    $stmt->execute([$username, $first_name ?: $username, $last_name, $can_reservations, $can_atemschutz, $can_forms_fill, $autologin_token, $autologin_expires, $einheit_id]);
                    log_activity($_SESSION['user_id'], 'user_added', "Systembenutzer '$username' für Einheit {$einheit['name']} angelegt");
                    header("Location: settings-einheit-users.php?id=" . $einheit_id . "&success=system_added");
                    exit;
                }
            } catch (Exception $e) {
                $error = "Fehler: " . $e->getMessage();
            }
        }
    }
}

// Systembenutzer bearbeiten
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_system_user') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Ungültiger Sicherheitstoken.";
    } else {
        $user_id = (int)($_POST['user_id'] ?? 0);
        $username = sanitize_input($_POST['username'] ?? '');
        $first_name = sanitize_input($_POST['first_name'] ?? '');
        $last_name = sanitize_input($_POST['last_name'] ?? '');
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $can_reservations = isset($_POST['can_reservations']) ? 1 : 0;
        $can_atemschutz = isset($_POST['can_atemschutz']) ? 1 : 0;
        $can_forms_fill = isset($_POST['can_forms_fill']) ? 1 : 0;
        if (!$user_id || empty($username)) {
            $error = "Ungültige Anfrage.";
        } else {
            try {
                $stmt = $db->prepare("SELECT id, username FROM users WHERE id = ? AND is_system_user = 1 AND einheit_id = ?");
                $stmt->execute([$user_id, $einheit_id]);
                $u = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$u) {
                    $error = "Systembenutzer gehört nicht zu dieser Einheit.";
                } else {
                    $stmt_check = $db->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
                    $stmt_check->execute([$username, $user_id]);
                    if ($stmt_check->fetch()) {
                        $error = "Dieser Benutzername existiert bereits.";
                    } else {
                        $stmt_up = $db->prepare("UPDATE users SET username = ?, first_name = ?, last_name = ?, is_active = ?, can_reservations = ?, can_atemschutz = ?, can_forms_fill = ? WHERE id = ?");
                        $stmt_up->execute([$username, $first_name, $last_name, $is_active, $can_reservations, $can_atemschutz, $can_forms_fill, $user_id]);
                        log_activity($_SESSION['user_id'], 'user_updated', "Systembenutzer '$username' bearbeitet");
                        header("Location: settings-einheit-users.php?id=" . $einheit_id . "&success=system_updated");
                        exit;
                    }
                }
            } catch (Exception $e) {
                $error = "Fehler: " . $e->getMessage();
            }
        }
    }
}

// Benutzer löschen (reguläre Benutzer, keine Systembenutzer)
if (isset($_GET['delete_user'])) {
    $user_id = (int)$_GET['delete_user'];
    if ($user_id > 0 && $user_id !== $_SESSION['user_id']) {
        try {
            $stmt = $db->prepare("SELECT id, username, user_type, is_admin, user_role FROM users WHERE id = ? AND is_system_user = 0 AND (einheit_id = ? OR id IN (SELECT user_id FROM user_einheiten WHERE einheit_id = ?))");
            $stmt->execute([$user_id, $einheit_id, $einheit_id]);
            $u = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($u) {
                $is_superadmin = function_exists('user_has_superadmin_rights') && user_has_superadmin_rights($u);
                if ($is_superadmin && count_superadmins() <= 1) {
                    $error = "Der letzte Superadmin kann nicht gelöscht werden. Es muss immer mindestens ein Superadmin existieren.";
                } else {
                    $del_error = '';
                    if (delete_user_safe($user_id, $del_error)) {
                        log_activity($_SESSION['user_id'], 'user_deleted', "Benutzer '{$u['username']}' gelöscht");
                        header("Location: settings-einheit-users.php?id=" . $einheit_id . "&success=user_deleted");
                        exit;
                    } else {
                        $error = "Fehler beim Löschen: " . ($del_error ?: "Unbekannter Fehler");
                    }
                }
            }
        } catch (Exception $e) {
            $error = "Fehler: " . $e->getMessage();
        }
    }
}

// Systembenutzer löschen
if (isset($_GET['delete_system_user'])) {
    $user_id = (int)$_GET['delete_system_user'];
    if ($user_id > 0) {
        try {
            $stmt = $db->prepare("SELECT id, username FROM users WHERE id = ? AND is_system_user = 1 AND einheit_id = ?");
            $stmt->execute([$user_id, $einheit_id]);
            $u = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($u) {
                $stmt_del = $db->prepare("DELETE FROM users WHERE id = ?");
                $stmt_del->execute([$user_id]);
                log_activity($_SESSION['user_id'], 'user_deleted', "Systembenutzer '{$u['username']}' gelöscht");
                header("Location: settings-einheit-users.php?id=" . $einheit_id . "&success=system_deleted");
                exit;
            }
        } catch (Exception $e) {
            $error = "Fehler: " . $e->getMessage();
        }
    }
}

// Autologin-Link neu generieren (Systembenutzer)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['regenerate_token'])) {
    $user_id = (int)$_POST['regenerate_token'];
    if (validate_csrf_token($_POST['csrf_token'] ?? '')) {
        try {
            $stmt = $db->prepare("SELECT id, username FROM users WHERE id = ? AND is_system_user = 1 AND einheit_id = ?");
            $stmt->execute([$user_id, $einheit_id]);
            $u = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($u) {
                $validity = $_POST['autologin_validity'] ?? '90';
                $autologin_token = bin2hex(random_bytes(32));
                $autologin_expires = null;
                if ($validity !== 'unlimited') {
                    $days = (int)$validity;
                    $autologin_expires = $days > 0 ? date('Y-m-d H:i:s', strtotime("+{$days} days")) : null;
                }
                $stmt_up = $db->prepare("UPDATE users SET autologin_token = ?, autologin_expires = ? WHERE id = ?");
                $stmt_up->execute([$autologin_token, $autologin_expires, $user_id]);
                log_activity($_SESSION['user_id'], 'user_updated', "Autologin-Link für Systembenutzer '{$u['username']}' neu generiert");
                header("Location: settings-einheit-users.php?id=" . $einheit_id . "&success=system_regenerated");
                exit;
            }
        } catch (Exception $e) {
            $error = "Fehler: " . $e->getMessage();
        }
    }
}

if (isset($_GET['success'])) {
    if ($_GET['success'] === 'updated') $message = "Benutzer wurde erfolgreich aktualisiert.";
    if ($_GET['success'] === 'user_added') $message = "Benutzer wurde erfolgreich angelegt.";
    if ($_GET['success'] === 'system_added') $message = "Systembenutzer wurde angelegt. Klicken Sie auf „Link anzeigen“ um den Autologin-Link zu sehen.";
    if ($_GET['success'] === 'system_regenerated') $message = "Neuer Autologin-Link wurde generiert.";
    if ($_GET['success'] === 'system_updated') $message = "Systembenutzer wurde aktualisiert.";
    if ($_GET['success'] === 'user_deleted') $message = "Benutzer wurde gelöscht.";
    if ($_GET['success'] === 'system_deleted') $message = "Systembenutzer wurde gelöscht.";
    if ($_GET['success'] === 'group_added') $message = "Gruppe wurde angelegt.";
    if ($_GET['success'] === 'group_updated') $message = "Gruppe wurde aktualisiert.";
    if ($_GET['success'] === 'group_deleted') $message = "Gruppe wurde gelöscht.";
}

// Sicherstellen, dass alle Berechtigungsspalten existieren
foreach (['can_forms_fill', 'can_auswertung', 'can_reservations_readonly', 'can_atemschutz_readonly', 'can_members_readonly', 'can_ric_readonly', 'can_courses_readonly', 'can_forms_readonly'] as $col) {
    try { $db->exec("ALTER TABLE users ADD COLUMN $col TINYINT(1) DEFAULT 0"); } catch (Exception $e) {}
}

// Sicherstellen, dass divera_access_key-Spalte existiert
try { $db->exec("ALTER TABLE users ADD COLUMN divera_access_key VARCHAR(512) NULL DEFAULT NULL"); } catch (Exception $e) {}

// Benutzer dieser Einheit laden (mit Berechtigungen und Divera-Key-Status)
$unit_users = [];
try {
    $stmt = $db->prepare("SELECT u.id, u.username, u.email, u.first_name, u.last_name, u.user_type, u.is_active, u.created_at,
        u.can_reservations, u.can_atemschutz, u.can_members, u.can_ric, u.can_courses, u.can_forms, u.can_forms_fill, u.can_auswertung,
        u.can_reservations_readonly, u.can_atemschutz_readonly, u.can_members_readonly, u.can_ric_readonly, u.can_courses_readonly, u.can_forms_readonly,
        (u.divera_access_key IS NOT NULL AND TRIM(u.divera_access_key) != '') AS has_divera_key
        FROM users u 
        WHERE (u.einheit_id = ? OR u.id IN (SELECT user_id FROM user_einheiten WHERE einheit_id = ?))
        AND u.is_system_user = 0
        ORDER BY u.last_name, u.first_name");
    $stmt->execute([$einheit_id, $einheit_id]);
    $unit_users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Systembenutzer dieser Einheit laden
$system_users = [];
try {
    $stmt = $db->prepare("SELECT id, username, first_name, last_name, is_active, autologin_token, autologin_expires, created_at, can_reservations, can_atemschutz, can_forms_fill FROM users WHERE einheit_id = ? AND is_system_user = 1 ORDER BY username");
    $stmt->execute([$einheit_id]);
    $system_users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$einheit_members_for_groups = [];
try {
    $stmt = $db->prepare("SELECT id, first_name, last_name FROM members WHERE einheit_id = ? ORDER BY last_name, first_name");
    $stmt->execute([$einheit_id]);
    $einheit_members_for_groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$member_groups = [];
try {
    $stmt = $db->prepare("
        SELECT 
            g.id,
            g.group_name,
            g.description,
            g.created_at,
            COUNT(gm.member_id) AS member_count,
            GROUP_CONCAT(gm.member_id ORDER BY gm.member_id SEPARATOR ',') AS member_ids,
            GROUP_CONCAT(CONCAT(m.last_name, ', ', m.first_name) ORDER BY m.last_name, m.first_name SEPARATOR ' | ') AS member_names
        FROM member_groups g
        LEFT JOIN member_group_members gm ON gm.group_id = g.id
        LEFT JOIN members m ON m.id = gm.member_id
        WHERE g.einheit_id = ?
        GROUP BY g.id, g.group_name, g.description, g.created_at
        ORDER BY g.group_name
    ");
    $stmt->execute([$einheit_id]);
    $member_groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Benutzerverwaltung – <?php echo htmlspecialchars($einheit['name']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container-fluid">
            <a class="navbar-brand" href="../index.php">
                <i class="fas fa-fire"></i> Feuerwehr App
            </a>
            <div class="d-flex ms-auto align-items-center">
                <?php $admin_menu_in_navbar = true; include __DIR__ . '/includes/admin-menu.inc.php'; ?>
            </div>
        </div>
    </nav>

    <div class="container-fluid mt-4">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="settings.php">Einstellungen</a></li>
                <li class="breadcrumb-item"><a href="settings-einheiten.php">Einheiten</a></li>
                <li class="breadcrumb-item"><a href="settings-einheit.php?id=<?php echo $einheit_id; ?>"><?php echo htmlspecialchars($einheit['name']); ?></a></li>
                <li class="breadcrumb-item active">Benutzerverwaltung</li>
            </ol>
        </nav>

        <?php if ($message): ?>
            <?php echo show_success($message); ?>
        <?php endif; ?>
        <?php if ($error): ?>
            <?php echo show_error($error); ?>
        <?php endif; ?>

        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
            <h1 class="h3 mb-0">
                <i class="fas fa-users-cog text-success me-2"></i>Benutzerverwaltung – <?php echo htmlspecialchars($einheit['name']); ?>
            </h1>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addUserModal">
                    <i class="fas fa-plus"></i> Neuer Benutzer
                </button>
                <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#addSystemUserModal">
                    <i class="fas fa-robot"></i> Neuer Systembenutzer
                </button>
                <a href="settings-einheit.php?id=<?php echo $einheit_id; ?>" class="btn btn-outline-secondary">
                    <i class="fas fa-arrow-left"></i> Zurück zur Einheit
                </a>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Benutzername</th>
                                <th>E-Mail</th>
                                <th>Berechtigungen</th>
                                <th>Divera</th>
                                <th>Status</th>
                                <th>Aktionen</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($unit_users as $u): ?>
                            <tr>
                                <td><?php echo htmlspecialchars(trim($u['first_name'] . ' ' . $u['last_name'])); ?></td>
                                <td><?php echo htmlspecialchars($u['username']); ?></td>
                                <td><?php echo htmlspecialchars($u['email'] ?? ''); ?></td>
                                <td>
                                    <div class="d-flex flex-wrap gap-1">
                                        <?php if (!empty($u['can_reservations'])): ?><span class="badge bg-primary">Reservierungen</span><?php endif; ?>
                                        <?php if (!empty($u['can_forms_fill'])): ?><span class="badge bg-secondary">Formulare ausfüllen</span><?php endif; ?>
                                        <?php if (!empty($u['can_atemschutz'])): ?><span class="badge bg-success">Atemschutz</span><?php endif; ?>
                                        <?php if (!empty($u['can_members'])): ?><span class="badge bg-info">Mitglieder</span><?php endif; ?>
                                        <?php if (!empty($u['can_auswertung'])): ?><span class="badge bg-info">Auswertung</span><?php endif; ?>
                                        <?php if (!empty($u['can_ric'])): ?><span class="badge bg-warning text-dark">RIC</span><?php endif; ?>
                                        <?php if (!empty($u['can_courses'])): ?><span class="badge bg-purple">Lehrgänge</span><?php endif; ?>
                                        <?php if (!empty($u['can_forms'])): ?><span class="badge bg-secondary">Formularcenter</span><?php endif; ?>
                                        <?php if (($u['user_type'] ?? '') === 'superadmin'): ?><span class="badge bg-danger">Superadmin</span><?php endif; ?>
                                        <?php if (($u['user_type'] ?? '') === 'einheitsadmin'): ?><span class="badge bg-warning text-dark">Einheitsadmin</span><?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <?php if (!empty($u['has_divera_key'])): ?>
                                        <span class="text-success" title="Divera Access Key hinterlegt"><i class="fas fa-check-circle"></i></span>
                                    <?php else: ?>
                                        <span class="text-muted" title="Kein Divera Access Key"><i class="fas fa-minus-circle"></i></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($u['is_active']): ?>
                                        <span class="badge bg-success">Aktiv</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Inaktiv</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php 
                                    $is_superadmin_row = (function_exists('user_has_superadmin_rights') && user_has_superadmin_rights($u));
                                    $can_delete_superadmin = $is_superadmin_row && ($u['id'] != $_SESSION['user_id']) && (function_exists('count_superadmins') && count_superadmins() > 1);
                                    if (!$is_superadmin_row): ?>
                                    <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#editUserModal"
                                        data-user-id="<?php echo (int)$u['id']; ?>"
                                        data-username="<?php echo htmlspecialchars($u['username'], ENT_QUOTES); ?>"
                                        data-first-name="<?php echo htmlspecialchars($u['first_name'] ?? '', ENT_QUOTES); ?>"
                                        data-last-name="<?php echo htmlspecialchars($u['last_name'] ?? '', ENT_QUOTES); ?>"
                                        data-email="<?php echo htmlspecialchars($u['email'] ?? '', ENT_QUOTES); ?>"
                                        data-can-reservations="<?php echo (int)($u['can_reservations'] ?? 0); ?>"
                                        data-can-atemschutz="<?php echo (int)($u['can_atemschutz'] ?? 0); ?>"
                                        data-can-members="<?php echo (int)($u['can_members'] ?? 0); ?>"
                                        data-can-ric="<?php echo (int)($u['can_ric'] ?? 0); ?>"
                                        data-can-courses="<?php echo (int)($u['can_courses'] ?? 0); ?>"
                                        data-can-forms-fill="<?php echo (int)($u['can_forms_fill'] ?? 0); ?>"
                                        data-can-forms="<?php echo (int)($u['can_forms'] ?? 0); ?>"
                                        data-can-auswertung="<?php echo (int)($u['can_auswertung'] ?? 0); ?>"
                                        data-can-reservations-readonly="<?php echo (int)($u['can_reservations_readonly'] ?? 0); ?>"
                                        data-can-atemschutz-readonly="<?php echo (int)($u['can_atemschutz_readonly'] ?? 0); ?>"
                                        data-can-members-readonly="<?php echo (int)($u['can_members_readonly'] ?? 0); ?>"
                                        data-can-ric-readonly="<?php echo (int)($u['can_ric_readonly'] ?? 0); ?>"
                                        data-can-courses-readonly="<?php echo (int)($u['can_courses_readonly'] ?? 0); ?>"
                                        data-can-forms-readonly="<?php echo (int)($u['can_forms_readonly'] ?? 0); ?>"
                                        data-is-active="<?php echo (int)$u['is_active']; ?>"
                                        data-divera-has-key="<?php echo !empty($u['has_divera_key']) ? '1' : '0'; ?>">
                                        <i class="fas fa-edit"></i> Bearbeiten
                                    </button>
                                    <a href="settings-einheit-users.php?id=<?php echo $einheit_id; ?>&delete_user=<?php echo (int)$u['id']; ?>" class="btn btn-outline-danger btn-sm" title="Löschen" onclick="return confirm('Benutzer wirklich löschen? Diese Aktion kann nicht rückgängig gemacht werden.');">
                                        <i class="fas fa-trash"></i> Löschen
                                    </a>
                                    <?php elseif ($can_delete_superadmin): ?>
                                    <a href="users.php?delete=<?php echo (int)$u['id']; ?>" class="btn btn-outline-danger btn-sm" title="Superadmin löschen" onclick="return confirm('Superadmin wirklich löschen? Diese Aktion kann nicht rückgängig gemacht werden.');">
                                        <i class="fas fa-trash"></i> Löschen
                                    </a>
                                    <?php else: ?>
                                    <span class="text-muted small">(nur in Benutzerverwaltung)</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (empty($unit_users)): ?>
                <p class="text-muted mb-0">Noch keine Benutzer in dieser Einheit.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="card mt-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-robot text-info"></i> Systembenutzer (Autologin)</h5>
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">Systembenutzer haben keinen Login. Sie erhalten einen Autologin-Link – z.B. für Tablets am Gerätehaus. Berechtigungen können beim Anlegen und Bearbeiten gesetzt werden.</p>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Benutzername</th>
                                <th>Berechtigungen</th>
                                <th>Status</th>
                                <th>Gültigkeit</th>
                                <th>Aktionen</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($system_users as $su): ?>
                            <tr>
                                <td><?php echo htmlspecialchars(trim(($su['first_name'] ?? '') . ' ' . ($su['last_name'] ?? '')) ?: '-'); ?></td>
                                <td><?php echo htmlspecialchars($su['username']); ?></td>
                                <td>
                                    <div class="d-flex flex-wrap gap-1">
                                        <?php if (!empty($su['can_reservations'])): ?><span class="badge bg-primary">Reservierungen</span><?php endif; ?>
                                        <?php if (!empty($su['can_atemschutz'])): ?><span class="badge bg-success">Atemschutz</span><?php endif; ?>
                                        <?php if (!empty($su['can_forms_fill'])): ?><span class="badge bg-secondary">Formulare</span><?php endif; ?>
                                        <?php if (empty($su['can_reservations']) && empty($su['can_atemschutz']) && empty($su['can_forms_fill'])): ?><span class="text-muted small">—</span><?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($su['is_active']): ?><span class="badge bg-success">Aktiv</span><?php else: ?><span class="badge bg-secondary">Inaktiv</span><?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    if (empty($su['autologin_token'])) {
                                        echo '<span class="text-warning">Kein Link</span>';
                                    } elseif (!empty($su['autologin_expires']) && strtotime($su['autologin_expires']) < time()) {
                                        echo '<span class="text-danger">Abgelaufen</span>';
                                    } elseif (!empty($su['autologin_expires'])) {
                                        echo htmlspecialchars(date('d.m.Y', strtotime($su['autologin_expires'])));
                                    } else {
                                        echo '<span class="text-success">Unbegrenzt</span>';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#editSystemUserModal"
                                        data-user-id="<?php echo (int)$su['id']; ?>"
                                        data-username="<?php echo htmlspecialchars($su['username'], ENT_QUOTES); ?>"
                                        data-first-name="<?php echo htmlspecialchars($su['first_name'] ?? '', ENT_QUOTES); ?>"
                                        data-last-name="<?php echo htmlspecialchars($su['last_name'] ?? '', ENT_QUOTES); ?>"
                                        data-is-active="<?php echo (int)$su['is_active']; ?>"
                                        data-can-reservations="<?php echo (int)($su['can_reservations'] ?? 0); ?>"
                                        data-can-atemschutz="<?php echo (int)($su['can_atemschutz'] ?? 0); ?>"
                                        data-can-forms-fill="<?php echo (int)($su['can_forms_fill'] ?? 0); ?>"
                                        title="Bearbeiten">
                                        <i class="fas fa-edit"></i> Bearbeiten
                                    </button>
                                    <button type="button" class="btn btn-outline-primary btn-sm btn-show-autologin" data-user-id="<?php echo (int)$su['id']; ?>" title="Link anzeigen">
                                        <i class="fas fa-link"></i> Link anzeigen
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#regenerateLinkModal" data-user-id="<?php echo (int)$su['id']; ?>" title="Neuen Link generieren">
                                        <i class="fas fa-sync-alt"></i> Neuer Link
                                    </button>
                                    <a href="settings-einheit-users.php?id=<?php echo $einheit_id; ?>&delete_system_user=<?php echo (int)$su['id']; ?>" class="btn btn-outline-danger btn-sm" title="Löschen" onclick="return confirm('Systembenutzer wirklich löschen? Der Autologin-Link funktioniert danach nicht mehr.');">
                                        <i class="fas fa-trash"></i> Löschen
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (empty($system_users)): ?>
                <p class="text-muted mb-0">Noch keine Systembenutzer. Klicken Sie auf „Neuer Systembenutzer“ um einen Autologin-Link für Tablets o.ä. anzulegen.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="card mt-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-layer-group text-primary"></i> Gruppen für Mitglieder</h5>
                <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addGroupModal">
                    <i class="fas fa-plus"></i> Gruppe anlegen
                </button>
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">Hier legen Sie Gruppen für die Mitgliederverwaltung an und können Mitglieder direkt zuordnen.</p>
                <?php if (empty($member_groups)): ?>
                    <p class="text-muted mb-0">Noch keine Gruppen vorhanden.</p>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Gruppe</th>
                                <th>Beschreibung</th>
                                <th>Mitglieder</th>
                                <th>Aktionen</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($member_groups as $group): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($group['group_name']); ?></strong></td>
                                <td><?php echo htmlspecialchars($group['description'] ?? '-'); ?></td>
                                <td>
                                    <span class="badge bg-secondary"><?php echo (int)$group['member_count']; ?></span>
                                    <?php if (!empty($group['member_names'])): ?>
                                        <div class="small text-muted mt-1"><?php echo htmlspecialchars(str_replace(' | ', ', ', $group['member_names'])); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="d-flex gap-2">
                                    <button type="button"
                                            class="btn btn-outline-primary btn-sm"
                                            data-bs-toggle="modal"
                                            data-bs-target="#editGroupModal"
                                            data-group-id="<?php echo (int)$group['id']; ?>"
                                            data-group-name="<?php echo htmlspecialchars($group['group_name'], ENT_QUOTES); ?>"
                                            data-group-description="<?php echo htmlspecialchars($group['description'] ?? '', ENT_QUOTES); ?>"
                                            data-member-ids="<?php echo htmlspecialchars($group['member_ids'] ?? '', ENT_QUOTES); ?>">
                                        <i class="fas fa-edit"></i> Bearbeiten
                                    </button>
                                    <form method="POST" onsubmit="return confirm('Gruppe wirklich löschen?');">
                                        <input type="hidden" name="action" value="delete_group">
                                        <input type="hidden" name="group_id" value="<?php echo (int)$group['id']; ?>">
                                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                        <button type="submit" class="btn btn-outline-danger btn-sm">
                                            <i class="fas fa-trash"></i> Löschen
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="modal fade" id="addGroupModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="add_group">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="fas fa-layer-group"></i> Gruppe anlegen</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label" for="group_name_add">Gruppenname *</label>
                            <input type="text" class="form-control" name="group_name" id="group_name_add" required maxlength="120">
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="group_description_add">Beschreibung</label>
                            <textarea class="form-control" name="description" id="group_description_add" rows="2"></textarea>
                        </div>
                        <div class="mb-0">
                            <label class="form-label" for="group_members_add">Mitglieder zuordnen</label>
                            <select class="form-select" name="member_ids[]" id="group_members_add" multiple size="8">
                                <?php foreach ($einheit_members_for_groups as $member): ?>
                                <option value="<?php echo (int)$member['id']; ?>"><?php echo htmlspecialchars(trim(($member['last_name'] ?? '') . ', ' . ($member['first_name'] ?? ''))); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Mehrfachauswahl mit Strg bzw. Umschalt-Taste.</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Speichern</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="editGroupModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="edit_group">
                    <input type="hidden" name="group_id" id="edit_group_id">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="fas fa-edit"></i> Gruppe bearbeiten</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label" for="edit_group_name">Gruppenname *</label>
                            <input type="text" class="form-control" name="group_name" id="edit_group_name" required maxlength="120">
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="edit_group_description">Beschreibung</label>
                            <textarea class="form-control" name="description" id="edit_group_description" rows="2"></textarea>
                        </div>
                        <div class="mb-0">
                            <label class="form-label" for="edit_group_members">Mitglieder zuordnen</label>
                            <select class="form-select" name="member_ids[]" id="edit_group_members" multiple size="8">
                                <?php foreach ($einheit_members_for_groups as $member): ?>
                                <option value="<?php echo (int)$member['id']; ?>"><?php echo htmlspecialchars(trim(($member['last_name'] ?? '') . ', ' . ($member['first_name'] ?? ''))); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Mehrfachauswahl mit Strg bzw. Umschalt-Taste.</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Speichern</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: Systembenutzer anlegen -->
    <div class="modal fade" id="addSystemUserModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="add_system_user">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="fas fa-robot"></i> Systembenutzer anlegen</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-muted small mb-3">Systembenutzer erhalten einen Autologin-Link. Wählen Sie die Berechtigungen – ideal für Tablets am Gerätehaus.</p>
                        <div class="mb-3">
                            <label for="sys_username" class="form-label">Benutzername *</label>
                            <input type="text" class="form-control" id="sys_username" name="username" required placeholder="z.B. tablet-eingang">
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="sys_first_name" class="form-label">Vorname (optional)</label>
                                <input type="text" class="form-control" id="sys_first_name" name="first_name" placeholder="Anzeigename">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="sys_last_name" class="form-label">Nachname (optional)</label>
                                <input type="text" class="form-control" id="sys_last_name" name="last_name">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Berechtigungen</label>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="can_reservations" id="sys_can_reservations">
                                <label class="form-check-label" for="sys_can_reservations">Reservierungen tätigen</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="can_atemschutz" id="sys_can_atemschutz">
                                <label class="form-check-label" for="sys_can_atemschutz">Atemschutzeinträge erstellen</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="can_forms_fill" id="sys_can_forms_fill" checked>
                                <label class="form-check-label" for="sys_can_forms_fill">Formulare ausfüllen</label>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="sys_autologin_validity" class="form-label">Gültigkeit des Autologin-Links</label>
                            <select class="form-select" id="sys_autologin_validity" name="autologin_validity">
                                <option value="7">7 Tage</option>
                                <option value="30">30 Tage</option>
                                <option value="90" selected>90 Tage</option>
                                <option value="365">1 Jahr</option>
                                <option value="unlimited">Unbegrenzt</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Systembenutzer anlegen</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: Systembenutzer bearbeiten -->
    <div class="modal fade" id="editSystemUserModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="edit_system_user">
                    <input type="hidden" name="user_id" id="edit_sys_user_id" value="">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="fas fa-robot"></i> Systembenutzer bearbeiten</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="edit_sys_username" class="form-label">Benutzername *</label>
                            <input type="text" class="form-control" id="edit_sys_username" name="username" required>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="edit_sys_first_name" class="form-label">Vorname (optional)</label>
                                <input type="text" class="form-control" id="edit_sys_first_name" name="first_name">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="edit_sys_last_name" class="form-label">Nachname (optional)</label>
                                <input type="text" class="form-control" id="edit_sys_last_name" name="last_name">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Berechtigungen</label>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="can_reservations" id="edit_sys_can_reservations">
                                <label class="form-check-label" for="edit_sys_can_reservations">Reservierungen tätigen</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="can_atemschutz" id="edit_sys_can_atemschutz">
                                <label class="form-check-label" for="edit_sys_can_atemschutz">Atemschutzeinträge erstellen</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="can_forms_fill" id="edit_sys_can_forms_fill">
                                <label class="form-check-label" for="edit_sys_can_forms_fill">Formulare ausfüllen</label>
                            </div>
                        </div>
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_active" id="edit_sys_is_active" checked>
                                <label class="form-check-label" for="edit_sys_is_active">Aktiv</label>
                            </div>
                            <small class="text-muted">Inaktive Systembenutzer können sich nicht per Autologin anmelden.</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Speichern</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: Autologin-Link anzeigen -->
    <div class="modal fade" id="autologinLinkModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-link"></i> Autologin-Link</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-2" id="autologin-username"></p>
                    <div class="input-group">
                        <input type="text" class="form-control" id="autologin-url" readonly>
                        <button type="button" class="btn btn-outline-primary" id="autologin-copy-btn" title="In Zwischenablage kopieren">
                            <i class="fas fa-copy"></i> Kopieren
                        </button>
                    </div>
                    <p class="text-muted small mt-2 mb-0" id="autologin-validity-hint"></p>
                    <p class="text-muted small mt-2 mb-0">Falls der Link nicht funktioniert (z. B. 502 Bad Gateway): Prüfen Sie die <strong>App URL</strong> in den globalen Einstellungen – sie muss zur tatsächlichen Adresse der Anwendung passen.</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal: Neuer Autologin-Link (Regenerieren) -->
    <div class="modal fade" id="regenerateLinkModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="regenerate_token" id="regenerate_user_id" value="">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="fas fa-sync-alt"></i> Neuen Autologin-Link generieren</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p class="mb-3">Der alte Link funktioniert danach nicht mehr.</p>
                        <div class="mb-0">
                            <label for="regenerate_autologin_validity" class="form-label">Gültigkeit</label>
                            <select class="form-select" id="regenerate_autologin_validity" name="autologin_validity">
                                <option value="7">7 Tage</option>
                                <option value="30">30 Tage</option>
                                <option value="90" selected>90 Tage</option>
                                <option value="365">1 Jahr</option>
                                <option value="unlimited">Unbegrenzt</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-sync-alt"></i> Neuen Link generieren</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: Benutzer bearbeiten -->
    <div class="modal fade" id="editUserModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="edit_user">
                    <input type="hidden" name="user_id" id="edit_user_id" value="">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="fas fa-user-edit"></i> Benutzer bearbeiten</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="edit_first_name" class="form-label">Vorname *</label>
                                <input type="text" class="form-control" name="first_name" id="edit_first_name" required>
                            </div>
                            <div class="col-md-6">
                                <label for="edit_last_name" class="form-label">Nachname *</label>
                                <input type="text" class="form-control" name="last_name" id="edit_last_name" required>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="edit_username" class="form-label">Benutzername *</label>
                                <input type="text" class="form-control" name="username" id="edit_username" required>
                            </div>
                            <div class="col-md-6">
                                <label for="edit_email" class="form-label">E-Mail *</label>
                                <input type="email" class="form-control" name="email" id="edit_email" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Berechtigungen</label>
                            <p class="text-muted small mb-2">Aktivieren Sie „Nur Leserechte“, wenn der Benutzer nur ansehen, aber nicht bearbeiten darf.</p>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="can_reservations" id="edit_can_reservations">
                                <label class="form-check-label" for="edit_can_reservations">Reservierungen</label>
                                <div class="form-check form-check-inline ms-3">
                                    <input class="form-check-input" type="checkbox" name="can_reservations_readonly" id="edit_can_reservations_readonly">
                                    <label class="form-check-label" for="edit_can_reservations_readonly">Nur Leserechte</label>
                                </div>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="can_atemschutz" id="edit_can_atemschutz">
                                <label class="form-check-label" for="edit_can_atemschutz">Atemschutz</label>
                                <div class="form-check form-check-inline ms-3">
                                    <input class="form-check-input" type="checkbox" name="can_atemschutz_readonly" id="edit_can_atemschutz_readonly">
                                    <label class="form-check-label" for="edit_can_atemschutz_readonly">Nur Leserechte</label>
                                </div>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="can_members" id="edit_can_members">
                                <label class="form-check-label" for="edit_can_members">Mitgliederverwaltung</label>
                                <div class="form-check form-check-inline ms-3">
                                    <input class="form-check-input" type="checkbox" name="can_members_readonly" id="edit_can_members_readonly">
                                    <label class="form-check-label" for="edit_can_members_readonly">Nur Leserechte</label>
                                </div>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="can_ric" id="edit_can_ric">
                                <label class="form-check-label" for="edit_can_ric"><span class="text-muted small">↳</span> RIC Verwaltung <small class="text-muted">(Teil von Mitgliederverwaltung)</small></label>
                                <div class="form-check form-check-inline ms-3">
                                    <input class="form-check-input" type="checkbox" name="can_ric_readonly" id="edit_can_ric_readonly">
                                    <label class="form-check-label" for="edit_can_ric_readonly">Nur Leserechte</label>
                                </div>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="can_courses" id="edit_can_courses">
                                <label class="form-check-label" for="edit_can_courses"><span class="text-muted small">↳</span> Lehrgangsverwaltung <small class="text-muted">(Teil von Mitgliederverwaltung)</small></label>
                                <div class="form-check form-check-inline ms-3">
                                    <input class="form-check-input" type="checkbox" name="can_courses_readonly" id="edit_can_courses_readonly">
                                    <label class="form-check-label" for="edit_can_courses_readonly">Nur Leserechte</label>
                                </div>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="can_auswertung" id="edit_can_auswertung">
                                <label class="form-check-label" for="edit_can_auswertung"><span class="text-muted small">↳</span> Auswertung <small class="text-muted">(Teil von Mitgliederverwaltung)</small></label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="can_forms_fill" id="edit_can_forms_fill">
                                <label class="form-check-label" for="edit_can_forms_fill">Formulare ausfüllen</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="can_forms" id="edit_can_forms">
                                <label class="form-check-label" for="edit_can_forms">Formularcenter</label>
                                <div class="form-check form-check-inline ms-3">
                                    <input class="form-check-input" type="checkbox" name="can_forms_readonly" id="edit_can_forms_readonly">
                                    <label class="form-check-label" for="edit_can_forms_readonly">Nur Leserechte</label>
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_active" id="edit_is_active" checked>
                                <label class="form-check-label" for="edit_is_active">Aktiv</label>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="edit_password" class="form-label">Neues Passwort (optional)</label>
                            <input type="password" class="form-control" name="password" id="edit_password" placeholder="Leer lassen = unverändert">
                        </div>
                        <div class="mb-3">
                            <label for="edit_divera_access_key" class="form-label">Divera Access Key (optional)</label>
                            <input type="password" class="form-control" name="divera_access_key" id="edit_divera_access_key" placeholder="" autocomplete="off">
                            <small class="text-muted">Gleicher Key wie im Benutzerprofil. Leer lassen = unverändert. Neuen Key eintragen zum Überschreiben.</small>
                            <div class="form-check mt-2">
                                <input class="form-check-input" type="checkbox" name="divera_access_key_clear" id="edit_divera_key_clear" value="1">
                                <label class="form-check-label" for="edit_divera_key_clear">Divera Key löschen</label>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Speichern</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: Neuer Benutzer -->
    <div class="modal fade" id="addUserModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="add_user">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="fas fa-user-plus"></i> Neuer Benutzer für <?php echo htmlspecialchars($einheit['name']); ?></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="add_username" class="form-label">Benutzername *</label>
                                <input type="text" class="form-control" name="username" id="add_username" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="add_email" class="form-label">E-Mail *</label>
                                <input type="email" class="form-control" name="email" id="add_email" required>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="add_first_name" class="form-label">Vorname *</label>
                                <input type="text" class="form-control" name="first_name" id="add_first_name" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="add_last_name" class="form-label">Nachname *</label>
                                <input type="text" class="form-control" name="last_name" id="add_last_name" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="add_password" class="form-label">Passwort *</label>
                            <input type="password" class="form-control" name="password" id="add_password" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Berechtigungen</label>
                            <p class="text-muted small mb-2">Aktivieren Sie „Nur Leserechte“, wenn der Benutzer nur ansehen, aber nicht bearbeiten darf.</p>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="can_reservations" id="add_can_reservations">
                                <label class="form-check-label" for="add_can_reservations">Reservierungen</label>
                                <div class="form-check form-check-inline ms-3">
                                    <input class="form-check-input" type="checkbox" name="can_reservations_readonly" id="add_can_reservations_readonly">
                                    <label class="form-check-label" for="add_can_reservations_readonly">Nur Leserechte</label>
                                </div>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="can_atemschutz" id="add_can_atemschutz">
                                <label class="form-check-label" for="add_can_atemschutz">Atemschutz</label>
                                <div class="form-check form-check-inline ms-3">
                                    <input class="form-check-input" type="checkbox" name="can_atemschutz_readonly" id="add_can_atemschutz_readonly">
                                    <label class="form-check-label" for="add_can_atemschutz_readonly">Nur Leserechte</label>
                                </div>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="can_members" id="add_can_members">
                                <label class="form-check-label" for="add_can_members">Mitgliederverwaltung</label>
                                <div class="form-check form-check-inline ms-3">
                                    <input class="form-check-input" type="checkbox" name="can_members_readonly" id="add_can_members_readonly">
                                    <label class="form-check-label" for="add_can_members_readonly">Nur Leserechte</label>
                                </div>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="can_ric" id="add_can_ric">
                                <label class="form-check-label" for="add_can_ric"><span class="text-muted small">↳</span> RIC Verwaltung <small class="text-muted">(Teil von Mitgliederverwaltung)</small></label>
                                <div class="form-check form-check-inline ms-3">
                                    <input class="form-check-input" type="checkbox" name="can_ric_readonly" id="add_can_ric_readonly">
                                    <label class="form-check-label" for="add_can_ric_readonly">Nur Leserechte</label>
                                </div>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="can_courses" id="add_can_courses">
                                <label class="form-check-label" for="add_can_courses"><span class="text-muted small">↳</span> Lehrgangsverwaltung <small class="text-muted">(Teil von Mitgliederverwaltung)</small></label>
                                <div class="form-check form-check-inline ms-3">
                                    <input class="form-check-input" type="checkbox" name="can_courses_readonly" id="add_can_courses_readonly">
                                    <label class="form-check-label" for="add_can_courses_readonly">Nur Leserechte</label>
                                </div>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="can_auswertung" id="add_can_auswertung">
                                <label class="form-check-label" for="add_can_auswertung"><span class="text-muted small">↳</span> Auswertung <small class="text-muted">(Teil von Mitgliederverwaltung)</small></label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="can_forms_fill" id="add_can_forms_fill">
                                <label class="form-check-label" for="add_can_forms_fill">Formulare ausfüllen</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="can_forms" id="add_can_forms">
                                <label class="form-check-label" for="add_can_forms">Formularcenter</label>
                                <div class="form-check form-check-inline ms-3">
                                    <input class="form-check-input" type="checkbox" name="can_forms_readonly" id="add_can_forms_readonly">
                                    <label class="form-check-label" for="add_can_forms_readonly">Nur Leserechte</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Anlegen</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.getElementById('editUserModal').addEventListener('show.bs.modal', function(e) {
            var btn = e.relatedTarget;
            if (!btn) return;
            document.getElementById('edit_user_id').value = btn.dataset.userId || '';
            document.getElementById('edit_first_name').value = btn.dataset.firstName || '';
            document.getElementById('edit_last_name').value = btn.dataset.lastName || '';
            document.getElementById('edit_username').value = btn.dataset.username || '';
            document.getElementById('edit_email').value = btn.dataset.email || '';
            document.getElementById('edit_can_reservations').checked = btn.dataset.canReservations == '1';
            document.getElementById('edit_can_atemschutz').checked = btn.dataset.canAtemschutz == '1';
            document.getElementById('edit_can_members').checked = btn.dataset.canMembers == '1';
            document.getElementById('edit_can_ric').checked = btn.dataset.canRic == '1';
            document.getElementById('edit_can_courses').checked = btn.dataset.canCourses == '1';
            document.getElementById('edit_can_forms_fill').checked = btn.dataset.canFormsFill == '1';
            document.getElementById('edit_can_forms').checked = btn.dataset.canForms == '1';
            document.getElementById('edit_can_auswertung').checked = btn.dataset.canAuswertung == '1';
            document.getElementById('edit_can_reservations_readonly').checked = btn.dataset.canReservationsReadonly == '1';
            document.getElementById('edit_can_atemschutz_readonly').checked = btn.dataset.canAtemschutzReadonly == '1';
            document.getElementById('edit_can_members_readonly').checked = btn.dataset.canMembersReadonly == '1';
            document.getElementById('edit_can_ric_readonly').checked = btn.dataset.canRicReadonly == '1';
            document.getElementById('edit_can_courses_readonly').checked = btn.dataset.canCoursesReadonly == '1';
            document.getElementById('edit_can_forms_readonly').checked = btn.dataset.canFormsReadonly == '1';
            document.getElementById('edit_is_active').checked = btn.dataset.isActive == '1';
            document.getElementById('edit_password').value = '';
            document.getElementById('edit_divera_access_key').value = '';
            document.getElementById('edit_divera_access_key').placeholder = btn.dataset.diveraHasKey == '1' ? 'Leer lassen zum Beibehalten' : 'Key eintragen';
            document.getElementById('edit_divera_key_clear').checked = false;
        });
        document.getElementById('regenerateLinkModal').addEventListener('show.bs.modal', function(e) {
            var btn = e.relatedTarget;
            if (btn && btn.dataset.userId) document.getElementById('regenerate_user_id').value = btn.dataset.userId;
        });
        document.getElementById('editSystemUserModal').addEventListener('show.bs.modal', function(e) {
            var btn = e.relatedTarget;
            if (!btn) return;
            document.getElementById('edit_sys_user_id').value = btn.dataset.userId || '';
            document.getElementById('edit_sys_username').value = btn.dataset.username || '';
            document.getElementById('edit_sys_first_name').value = btn.dataset.firstName || '';
            document.getElementById('edit_sys_last_name').value = btn.dataset.lastName || '';
            document.getElementById('edit_sys_is_active').checked = btn.dataset.isActive == '1';
            document.getElementById('edit_sys_can_reservations').checked = btn.dataset.canReservations == '1';
            document.getElementById('edit_sys_can_atemschutz').checked = btn.dataset.canAtemschutz == '1';
            document.getElementById('edit_sys_can_forms_fill').checked = btn.dataset.canFormsFill == '1';
        });
        var editGroupModalEl = document.getElementById('editGroupModal');
        if (editGroupModalEl) {
            editGroupModalEl.addEventListener('show.bs.modal', function(e) {
                var btn = e.relatedTarget;
                if (!btn) return;
                document.getElementById('edit_group_id').value = btn.dataset.groupId || '';
                document.getElementById('edit_group_name').value = btn.dataset.groupName || '';
                document.getElementById('edit_group_description').value = btn.dataset.groupDescription || '';
                var selectedIds = (btn.dataset.memberIds || '').split(',').map(function(v) { return v.trim(); }).filter(function(v) { return v !== ''; });
                var select = document.getElementById('edit_group_members');
                if (select) {
                    Array.prototype.forEach.call(select.options, function(opt) {
                        opt.selected = selectedIds.indexOf(opt.value) !== -1;
                    });
                }
            });
        }
        document.querySelectorAll('.btn-show-autologin').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var userId = this.dataset.userId;
                if (!userId) return;
                fetch('get-autologin-url.php?user_id=' + encodeURIComponent(userId))
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (data.success) {
                            document.getElementById('autologin-url').value = data.url;
                            document.getElementById('autologin-username').textContent = data.username;
                            document.getElementById('autologin-validity-hint').textContent = data.validity_hint || '';
                            new bootstrap.Modal(document.getElementById('autologinLinkModal')).show();
                        } else {
                            alert(data.error || 'Fehler beim Laden des Links.');
                        }
                    })
                    .catch(function() { alert('Fehler beim Laden.'); });
            });
        });
        document.getElementById('autologin-copy-btn').addEventListener('click', function() {
            var urlEl = document.getElementById('autologin-url');
            var copyBtn = document.getElementById('autologin-copy-btn');
            var text = urlEl && urlEl.value ? urlEl.value : '';
            if (!text) return;
            var showSuccess = function() {
                var orig = copyBtn.innerHTML;
                copyBtn.innerHTML = '<i class="fas fa-check"></i> Kopiert!';
                setTimeout(function() { copyBtn.innerHTML = orig; }, 1500);
            };
            var ok = false;
            if (urlEl && document.execCommand) {
                urlEl.removeAttribute('readonly');
                urlEl.focus();
                urlEl.select();
                urlEl.setSelectionRange(0, text.length);
                try { ok = document.execCommand('copy'); } catch (e) {}
                urlEl.setAttribute('readonly', 'readonly');
            }
            if (ok) {
                showSuccess();
            } else if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(showSuccess).catch(function() { copyViaTextarea(text, showSuccess); });
            } else {
                copyViaTextarea(text, showSuccess);
            }
        });
        function copyViaTextarea(text, onSuccess) {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.style.cssText = 'position:fixed;top:0;left:0;width:2px;height:2px;padding:0;border:0;outline:none;opacity:0.01;';
            document.body.appendChild(ta);
            ta.focus();
            ta.select();
            var ok = false;
            try { ok = document.execCommand('copy'); } catch (e) {}
            document.body.removeChild(ta);
            if (ok) onSuccess();
            else alert('Kopieren fehlgeschlagen. Bitte den Link manuell markieren (Strg+A) und kopieren (Strg+C).');
        }
    </script>
</body>
</html>
