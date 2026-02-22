<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once __DIR__ . '/../includes/einheit-settings-helper.php';

// Prüfe ob Benutzer eingeloggt ist
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header("Location: ../login.php");
    exit;
}

// Systembenutzer haben keinen Zugriff auf Admin-Seiten
if (is_system_user()) {
    header("Location: ../formulare.php");
    exit;
}

// Prüfe ob Benutzer Admin-Berechtigung hat
if (!hasAdminPermission()) {
    header("Location: ../login.php?error=access_denied");
    exit;
}

$message = '';
$error = '';

$einheit_id = isset($_GET['einheit_id']) ? (int)$_GET['einheit_id'] : 0;
$einheit = null;
if ($einheit_id > 0) {
    try {
        $stmt = $db->prepare("SELECT id, name FROM einheiten WHERE id = ?");
        $stmt->execute([$einheit_id]);
        $einheit = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

// Erfolgsmeldungen von GET-Parameter
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'users';
if (!in_array($active_tab, ['users', 'system'])) $active_tab = 'users';
if (isset($_GET['success'])) {
    if ($_GET['success'] == 'added') {
        $message = "Benutzer wurde erfolgreich hinzugefügt.";
    } elseif ($_GET['success'] == 'updated') {
        $message = "Benutzer wurde erfolgreich aktualisiert.";
    } elseif ($_GET['success'] == 'system_added') {
        $message = "Systembenutzer wurde erfolgreich angelegt. Klicken Sie auf „Link anzeigen“ beim Benutzer, um den Autologin-Link zu sehen.";
    }
}

// Benutzer hinzufügen/bearbeiten
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';
    $user_id = (int)($_POST['user_id'] ?? 0);
    
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Ungültiger Sicherheitstoken.";
    } else {
        $username = sanitize_input($_POST['username'] ?? '');
        $email = sanitize_input($_POST['email'] ?? '');
        $first_name = sanitize_input($_POST['first_name'] ?? '');
        $last_name = sanitize_input($_POST['last_name'] ?? '');
        $user_role = 'user';
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $password = $_POST['password'] ?? '';
        
        // Granular permissions
        $is_admin = isset($_POST['is_admin']) ? 1 : 0;
        $can_reservations = isset($_POST['can_reservations']) ? 1 : 0;
        $can_atemschutz = isset($_POST['can_atemschutz']) ? 1 : 0;
        $can_members = isset($_POST['can_members']) ? 1 : 0;
        $can_ric = isset($_POST['can_ric']) ? 1 : 0;
        $can_courses = isset($_POST['can_courses']) ? 1 : 0;
        $can_forms = isset($_POST['can_forms']) ? 1 : 0;
        // Benutzerverwaltung/Einstellungen werden durch Administrator gesetzt
        $can_users = $is_admin ? 1 : 0;
        $can_settings = $is_admin ? 1 : 0;
        // Fahrzeugverwaltung: nur Administratoren erhalten Zugriff (kein eigener Schalter)
        $can_vehicles = $is_admin ? 1 : 0;
        
        if ($action == 'add_system_user') {
            // Systembenutzer: nur Benutzername, keine E-Mail, kein Passwort
            // Berechtigung: nur Formulare ausfüllen (wie nicht eingeloggter User + Formulare)
            $username = sanitize_input($_POST['username'] ?? '');
            $first_name = sanitize_input($_POST['first_name'] ?? '');
            $last_name = sanitize_input($_POST['last_name'] ?? '');
            if (empty($username)) {
                $error = "Benutzername ist erforderlich.";
            } else {
                try {
                    // Spalten sicherstellen
                    try { $db->exec("ALTER TABLE users ADD COLUMN is_system_user TINYINT(1) DEFAULT 0"); } catch (Exception $e) {}
                    try { $db->exec("ALTER TABLE users ADD COLUMN autologin_token VARCHAR(64) NULL"); } catch (Exception $e) {}
                    try { $db->exec("ALTER TABLE users ADD COLUMN autologin_expires DATETIME NULL"); } catch (Exception $e) {}
                    try { $db->exec("ALTER TABLE users MODIFY COLUMN email VARCHAR(255) NULL"); } catch (Exception $e) {}
                    try { $db->exec("ALTER TABLE users MODIFY COLUMN password_hash VARCHAR(255) NULL"); } catch (Exception $e) {}
                    // Prüfen ob Benutzername bereits existiert
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
                        $save_einheit_id = (int)($_POST['einheit_id'] ?? $einheit_id);
                        $stmt = $db->prepare("INSERT INTO users (username, email, password_hash, first_name, last_name, user_role, is_active, is_admin, is_system_user, can_reservations, can_atemschutz, can_members, can_ric, can_courses, can_forms, can_users, can_settings, can_vehicles, email_notifications, autologin_token, autologin_expires, einheit_id) VALUES (?, NULL, NULL, ?, ?, 'user', 1, 0, 1, 0, 0, 0, 0, 0, 1, 0, 0, 0, 0, ?, ?, ?)");
                        $stmt->execute([$username, $first_name ?: $username, $last_name, $autologin_token, $autologin_expires, $save_einheit_id > 0 ? $save_einheit_id : null]);
                        log_activity($_SESSION['user_id'], 'user_added', "Systembenutzer '$username' hinzugefügt");
                        $redir = "Location: users.php?tab=system&success=system_added";
                        if ($save_einheit_id > 0) $redir .= "&einheit_id=" . $save_einheit_id;
                        header($redir);
                        exit;
                    }
                } catch (Exception $e) {
                    $error = "Fehler: " . $e->getMessage();
                }
            }
        } elseif (empty($username) || empty($email) || empty($first_name) || empty($last_name)) {
            $error = "Alle Felder sind erforderlich.";
        } elseif (!validate_email($email)) {
            $error = "Bitte geben Sie eine gültige E-Mail-Adresse ein.";
        } else {
            try {
                if ($action == 'add') {
                    if (empty($password)) {
                        $error = "Passwort ist erforderlich.";
                    } else {
                        $password_hash = hash_password($password);
                        // E-Mail-Benachrichtigungen nur aktivieren, wenn Admin oder Reservierungsberechtigung
                        $email_notifications = ($is_admin || $can_reservations) ? 1 : 0;
                        // can_members und can_ric Spalten sicherstellen
                        try {
                            $db->exec("ALTER TABLE users ADD COLUMN can_members TINYINT(1) DEFAULT 0");
                        } catch (Exception $e) {
                            // Spalte existiert bereits, ignoriere Fehler
                        }
                        try {
                            $db->exec("ALTER TABLE users ADD COLUMN can_ric TINYINT(1) DEFAULT 0");
                        } catch (Exception $e) {
                            // Spalte existiert bereits, ignoriere Fehler
                        }
                        try {
                            $db->exec("ALTER TABLE users ADD COLUMN can_courses TINYINT(1) DEFAULT 0");
                        } catch (Exception $e) {
                            // Spalte existiert bereits, ignoriere Fehler
                        }
                        try {
                            $db->exec("ALTER TABLE users ADD COLUMN can_forms TINYINT(1) DEFAULT 0");
                        } catch (Exception $e) {
                            // Spalte existiert bereits, ignoriere Fehler
                        }
                        
                        $stmt = $db->prepare("INSERT INTO users (username, email, password_hash, first_name, last_name, user_role, is_active, is_admin, can_reservations, can_atemschutz, can_members, can_ric, can_courses, can_forms, can_users, can_settings, can_vehicles, email_notifications) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$username, $email, $password_hash, $first_name, $last_name, 'user', $is_active, $is_admin, $can_reservations, $can_atemschutz, $can_members, $can_ric, $can_courses, $can_forms, $can_users, $can_settings, $can_vehicles, $email_notifications]);
                        $new_user_id = $db->lastInsertId();
                        
                        // Mitglied automatisch erstellen/verknüpfen
                        try {
                            // Prüfe ob bereits ein Mitglied mit dieser user_id existiert
                            $stmt_check = $db->prepare("SELECT id FROM members WHERE user_id = ?");
                            $stmt_check->execute([$new_user_id]);
                            if (!$stmt_check->fetch()) {
                                // Erstelle Mitglied für diesen Benutzer
                                $stmt_member = $db->prepare("INSERT INTO members (user_id, first_name, last_name, email) VALUES (?, ?, ?, ?)");
                                $stmt_member->execute([$new_user_id, $first_name, $last_name, $email]);
                            }
                        } catch (Exception $e) {
                            // Fehler beim Erstellen des Mitglieds ignorieren (Tabelle könnte noch nicht existieren)
                            error_log("Fehler beim Erstellen des Mitglieds für Benutzer $new_user_id: " . $e->getMessage());
                        }
                        
                        $message = "Benutzer wurde erfolgreich hinzugefügt.";
                        log_activity($_SESSION['user_id'], 'user_added', "Benutzer '$username' hinzugefügt");
                        
                        // Weiterleitung um POST-Problem zu vermeiden
                        $redir = "Location: users.php?success=added";
                        if ($save_einheit_id > 0) $redir .= "&einheit_id=" . $save_einheit_id;
                        header($redir);
                        exit();
                    }
                } elseif ($action == 'edit') {
                    // can_members und can_ric Spalten sicherstellen
                    try {
                        $db->exec("ALTER TABLE users ADD COLUMN can_members TINYINT(1) DEFAULT 0");
                    } catch (Exception $e) {
                        // Spalte existiert bereits, ignoriere Fehler
                    }
                    try {
                        $db->exec("ALTER TABLE users ADD COLUMN can_ric TINYINT(1) DEFAULT 0");
                    } catch (Exception $e) {
                        // Spalte existiert bereits, ignoriere Fehler
                    }
                    try {
                        $db->exec("ALTER TABLE users ADD COLUMN can_courses TINYINT(1) DEFAULT 0");
                    } catch (Exception $e) {
                        // Spalte existiert bereits, ignoriere Fehler
                    }
                    try {
                        $db->exec("ALTER TABLE users ADD COLUMN can_forms TINYINT(1) DEFAULT 0");
                    } catch (Exception $e) {
                        // Spalte existiert bereits, ignoriere Fehler
                    }
                    
                    if (!empty($password)) {
                        $password_hash = hash_password($password);
                        $stmt = $db->prepare("UPDATE users SET username = ?, email = ?, password_hash = ?, first_name = ?, last_name = ?, user_role = ?, is_active = ?, is_admin = ?, can_reservations = ?, can_atemschutz = ?, can_members = ?, can_ric = ?, can_courses = ?, can_forms = ?, can_users = ?, can_settings = ?, can_vehicles = ? WHERE id = ?");
                        $stmt->execute([$username, $email, $password_hash, $first_name, $last_name, 'user', $is_active, $is_admin, $can_reservations, $can_atemschutz, $can_members, $can_ric, $can_courses, $can_forms, $can_users, $can_settings, $can_vehicles, $user_id]);
                    } else {
                        $stmt = $db->prepare("UPDATE users SET username = ?, email = ?, first_name = ?, last_name = ?, user_role = ?, is_active = ?, is_admin = ?, can_reservations = ?, can_atemschutz = ?, can_members = ?, can_ric = ?, can_courses = ?, can_forms = ?, can_users = ?, can_settings = ?, can_vehicles = ? WHERE id = ?");
                        $stmt->execute([$username, $email, $first_name, $last_name, 'user', $is_active, $is_admin, $can_reservations, $can_atemschutz, $can_members, $can_ric, $can_courses, $can_forms, $can_users, $can_settings, $can_vehicles, $user_id]);
                    }
                    
                    // Mitglied aktualisieren falls vorhanden
                    try {
                        $stmt_check = $db->prepare("SELECT id FROM members WHERE user_id = ?");
                        $stmt_check->execute([$user_id]);
                        if ($stmt_check->fetch()) {
                            // Mitglied existiert, aktualisiere es
                            $stmt_member = $db->prepare("UPDATE members SET first_name = ?, last_name = ?, email = ? WHERE user_id = ?");
                            $stmt_member->execute([$first_name, $last_name, $email, $user_id]);
                        } else {
                            // Mitglied existiert nicht, erstelle es
                            $stmt_member = $db->prepare("INSERT INTO members (user_id, first_name, last_name, email) VALUES (?, ?, ?, ?)");
                            $stmt_member->execute([$user_id, $first_name, $last_name, $email]);
                        }
                    } catch (Exception $e) {
                        // Fehler ignorieren
                        error_log("Fehler beim Aktualisieren des Mitglieds für Benutzer $user_id: " . $e->getMessage());
                    }
                    
                    $message = "Benutzer wurde erfolgreich aktualisiert.";
                    log_activity($_SESSION['user_id'], 'user_updated', "Benutzer '$username' aktualisiert");
                    
                    // Weiterleitung um POST-Problem zu vermeiden
                    header("Location: users.php?success=updated");
                    exit();
                }
            } catch(PDOException $e) {
                $error = "Fehler beim Speichern des Benutzers: " . $e->getMessage();
            }
        }
    }
}

// Passwort zurücksetzen
if (isset($_GET['reset_password'])) {
    $user_id = (int)$_GET['reset_password'];
    
    try {
        // Benutzerdaten laden
        $stmt = $db->prepare("SELECT id, username, email, first_name, last_name FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            $error = "Benutzer nicht gefunden.";
        } elseif (empty($user['email'])) {
            $error = "Benutzer hat keine E-Mail-Adresse. Passwort kann nicht zurückgesetzt werden.";
        } else {
            // Neues Passwort generieren (4 Zufallszahlen)
            $new_password = str_pad(rand(0, 9999), 4, '0', STR_PAD_LEFT);
            $password_hash = hash_password($new_password);
            
            // Passwort in Datenbank aktualisieren
            $stmt = $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
            $stmt->execute([$password_hash, $user_id]);
            
            // E-Mail senden
            $email_subject = 'Ihr Passwort wurde zurückgesetzt';
            $email_body = '
            <html>
            <head>
                <meta charset="UTF-8">
                <style>
                    body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .header { background-color: #dc3545; color: white; padding: 20px; text-align: center; }
                    .content { background-color: #f9f9f9; padding: 20px; border: 1px solid #ddd; }
                    .credentials { background-color: #fff; padding: 15px; margin: 20px 0; border-left: 4px solid #dc3545; }
                    .credentials strong { color: #dc3545; }
                    .footer { text-align: center; margin-top: 20px; color: #666; font-size: 12px; }
                </style>
            </head>
            <body>
                <div class="container">
                    <div class="header">
                        <h1>Passwort zurückgesetzt</h1>
                    </div>
                    <div class="content">
                        <p>Hallo ' . htmlspecialchars($user['first_name']) . ',</p>
                        <p>Ihr Passwort wurde zurückgesetzt. Sie können sich nun mit folgenden Zugangsdaten anmelden:</p>
                        <div class="credentials">
                            <p><strong>Benutzername:</strong> ' . htmlspecialchars($user['username']) . '</p>
                            <p><strong>Neues Passwort:</strong> ' . htmlspecialchars($new_password) . '</p>
                        </div>
                        <p style="text-align: center; margin: 30px 0;">
                            <a href="https://feuerwehr.boede89.selfhost.co/" style="display: inline-block; background-color: #dc3545; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; font-weight: bold;">Zur Startseite</a>
                        </p>
                        <p>Bitte ändern Sie Ihr Passwort nach dem Login für mehr Sicherheit.</p>
                        <p>Bei Fragen wenden Sie sich bitte an den Administrator.</p>
                    </div>
                    <div class="footer">
                        <p>Diese E-Mail wurde automatisch generiert. Bitte antworten Sie nicht auf diese E-Mail.</p>
                    </div>
                </div>
            </body>
            </html>';
            
            if (send_email($user['email'], $email_subject, $email_body, '', true)) {
                $message = "Passwort wurde zurückgesetzt und eine E-Mail mit dem neuen Passwort wurde an " . htmlspecialchars($user['email']) . " gesendet. Bitte prüfen Sie auch Ihren Spam-Ordner, falls die E-Mail nicht im Posteingang ankommt.";
                log_activity($_SESSION['user_id'], 'password_reset', "Passwort für Benutzer ID $user_id zurückgesetzt");
            } else {
                $error = "Passwort wurde zurückgesetzt, aber die E-Mail konnte nicht gesendet werden. Bitte kontaktieren Sie den Benutzer direkt.";
                log_activity($_SESSION['user_id'], 'password_reset', "Passwort für Benutzer ID $user_id zurückgesetzt (E-Mail fehlgeschlagen)");
            }
        }
    } catch(PDOException $e) {
        $error = "Fehler beim Zurücksetzen des Passworts: " . $e->getMessage();
        error_log("Fehler beim Passwort-Reset: " . $e->getMessage());
    }
}

// Autologin-Link neu generieren (Systembenutzer)
if (isset($_POST['regenerate_token']) || isset($_GET['regenerate_token'])) {
    $user_id = (int)($_POST['regenerate_token'] ?? $_GET['regenerate_token'] ?? 0);
    $validity = $_POST['autologin_validity'] ?? $_GET['autologin_validity'] ?? '90';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Ungültiger Sicherheitstoken.";
    } else {
    try {
        $stmt = $db->prepare("SELECT id, username FROM users WHERE id = ? AND is_system_user = 1");
        $stmt->execute([$user_id]);
        $u = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($u) {
            $autologin_token = bin2hex(random_bytes(32));
            $autologin_expires = null;
            if ($validity !== 'unlimited') {
                $days = (int)$validity;
                $autologin_expires = $days > 0 ? date('Y-m-d H:i:s', strtotime("+{$days} days")) : null;
            }
            $stmt_up = $db->prepare("UPDATE users SET autologin_token = ?, autologin_expires = ? WHERE id = ?");
            $stmt_up->execute([$autologin_token, $autologin_expires, $user_id]);
            log_activity($_SESSION['user_id'], 'user_updated', "Autologin-Link für Systembenutzer '{$u['username']}' neu generiert");
            header("Location: users.php?tab=system&success=system_added");
            exit;
        }
    } catch (Exception $e) {
        $error = "Fehler: " . $e->getMessage();
    }
    }
}

// Benutzer löschen
if (isset($_GET['delete'])) {
    $user_id = (int)$_GET['delete'];
    
    try {
        // Nicht sich selbst löschen
        if ($user_id == $_SESSION['user_id']) {
            $error = "Sie können sich nicht selbst löschen.";
        } else {
            $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $message = "Benutzer wurde erfolgreich gelöscht.";
            log_activity($_SESSION['user_id'], 'user_deleted', "Benutzer ID $user_id gelöscht");
        }
    } catch(PDOException $e) {
        $error = "Fehler beim Löschen des Benutzers: " . $e->getMessage();
    }
}

// Benutzer laden
try {
    // can_members und can_ric Spalten sicherstellen
    try {
        $db->exec("ALTER TABLE users ADD COLUMN can_members TINYINT(1) DEFAULT 0");
    } catch (Exception $e) {
        // Spalte existiert bereits, ignoriere Fehler
    }
    try {
        $db->exec("ALTER TABLE users ADD COLUMN can_ric TINYINT(1) DEFAULT 0");
    } catch (Exception $e) {
        // Spalte existiert bereits, ignoriere Fehler
    }
    try {
        $db->exec("ALTER TABLE users ADD COLUMN can_courses TINYINT(1) DEFAULT 0");
    } catch (Exception $e) {
        // Spalte existiert bereits, ignoriere Fehler
    }
    try {
        $db->exec("ALTER TABLE users ADD COLUMN can_forms TINYINT(1) DEFAULT 0");
    } catch (Exception $e) {
        // Spalte existiert bereits, ignoriere Fehler
    }
    try {
        $db->exec("ALTER TABLE users ADD COLUMN divera_access_key VARCHAR(512) NULL DEFAULT NULL");
    } catch (Exception $e) {
        // Spalte existiert bereits, ignoriere Fehler
    }
    
    try { $db->exec("ALTER TABLE users ADD COLUMN is_system_user TINYINT(1) DEFAULT 0"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE users ADD COLUMN einheit_id INT NULL"); } catch (Exception $e) {}
    $amern_id = get_einheit_amern_id($db);
    if ($amern_id > 0) {
        try { $db->exec("UPDATE users SET einheit_id = $amern_id WHERE einheit_id IS NULL"); } catch (Exception $e) {}
    }
    $where = "1=1";
    $params = [];
    if ($einheit_id > 0) {
        if ($amern_id > 0 && $amern_id === $einheit_id) {
            $where = "(einheit_id = ? OR einheit_id IS NULL)";
        } else {
            $where = "einheit_id = ?";
        }
        $params[] = $einheit_id;
    }
    $stmt = $db->prepare("SELECT id, username, email, first_name, last_name, user_role, is_active, created_at, is_admin, is_system_user, can_reservations, can_atemschutz, can_members, can_ric, can_courses, can_forms, can_users, can_settings, can_vehicles FROM users WHERE $where ORDER BY created_at DESC");
    $stmt->execute($params);
    $all_users = $stmt->fetchAll();
    $users = array_filter($all_users, fn($u) => empty($u['is_system_user']));
    $system_users = array_filter($all_users, fn($u) => !empty($u['is_system_user']));
} catch(PDOException $e) {
    $error = "Fehler beim Laden der Benutzer: " . $e->getMessage();
    $users = [];
    $system_users = [];
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Benutzerverwaltung - Feuerwehr App</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <style>
        /* Lehrgangsverwaltung-Badge: farbiger Hintergrund wie andere Berechtigungen */
        .badge.bg-purple { background: #6f42c1 !important; background-color: #6f42c1 !important; color: #fff !important; }
    </style>
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
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
            <h1 class="h3 mb-0">
                <i class="fas fa-users"></i> Benutzerverwaltung<?php if ($einheit): ?> <span class="text-muted">(<?php echo htmlspecialchars($einheit['name']); ?>)</span><?php endif; ?>
            </h1>
            <a href="<?php echo $einheit_id > 0 ? 'settings-einheit.php?id=' . (int)$einheit_id : 'settings.php'; ?>" class="btn btn-outline-secondary"><i class="fas fa-arrow-left"></i> Zurück</a>
        </div>

        <?php if ($message): ?>
            <?php echo show_success($message); ?>
        <?php endif; ?>
        <?php if ($error): ?>
            <?php echo show_error($error); ?>
        <?php endif; ?>

        <div class="row">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
                    <div class="d-flex gap-2">
                        <?php if ($active_tab === 'users'): ?>
                        <button type="button" class="btn btn-primary" onclick="openUserModal()">
                            <i class="fas fa-plus"></i> Neuer Benutzer
                        </button>
                        <?php else: ?>
                        <button type="button" class="btn btn-primary" onclick="openSystemUserModal()">
                            <i class="fas fa-robot"></i> Systembenutzer anlegen
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                
                <?php $tab_base = $einheit_id > 0 ? '?einheit_id=' . (int)$einheit_id . '&tab=' : '?tab='; ?>
                <ul class="nav nav-tabs mb-3">
                    <li class="nav-item">
                        <a class="nav-link <?php echo $active_tab === 'users' ? 'active' : ''; ?>" href="<?php echo $tab_base; ?>users">
                            <i class="fas fa-user"></i> Benutzer (<?php echo count($users); ?>)
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $active_tab === 'system' ? 'active' : ''; ?>" href="<?php echo $tab_base; ?>system">
                            <i class="fas fa-robot"></i> Systembenutzer (<?php echo count($system_users); ?>)
                        </a>
                    </li>
                </ul>
            </div>
        </div>

        <!-- Benutzer Tabelle -->
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <?php if ($active_tab === 'users'): ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Benutzername</th>
                                        <th>E-Mail</th>
                                        <th>Name</th>
                                        <th>Berechtigungen</th>
                                        <th>Status</th>
                                        <th>Erstellt</th>
                                        <th>Aktionen</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($users as $user): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($user['username']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($user['email'] ?? ''); ?></td>
                                            <td><?php echo htmlspecialchars(trim($user['first_name'] . ' ' . $user['last_name']) ?: $user['username']); ?></td>
                                            <!-- Rolle-Spalte entfernt -->
                                            <td>
                                                <div class="d-flex flex-wrap gap-1">
                                                    <?php if (!empty($user['is_admin'])): ?>
                                                        <span class="badge bg-danger">Administrator</span>
                                                    <?php endif; ?>
                                                    <?php if (!empty($user['can_reservations'])): ?>
                                                        <span class="badge bg-primary">Fahrzeugreservierungen</span>
                                                    <?php endif; ?>
                                                    <?php if (!empty($user['can_atemschutz'])): ?>
                                                        <span class="badge bg-success">Atemschutz</span>
                                                    <?php endif; ?>
                                                    <?php if (!empty($user['can_members'])): ?>
                                                        <span class="badge bg-info">Mitgliederverwaltung</span>
                                                    <?php endif; ?>
                                                    <?php if (!empty($user['can_ric'])): ?>
                                                        <span class="badge bg-warning">RIC Verwaltung</span>
                                                    <?php endif; ?>
                                                    <?php if (!empty($user['can_courses'])): ?>
                                                        <span class="badge bg-purple">Lehrgangsverwaltung</span>
                                                    <?php endif; ?>
                                                    <?php if (!empty($user['can_forms'])): ?>
                                                        <span class="badge bg-secondary">Formularcenter</span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <?php if ($user['is_active']): ?>
                                                    <span class="badge bg-success">Aktiv</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">Inaktiv</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo format_date($user['created_at']); ?></td>
                                            <td>
                                                <button type="button" class="btn btn-outline-primary btn-sm" id="editBtn<?php echo (int)$user['id']; ?>" data-bs-toggle="modal" data-bs-target="#userModal"
                                                    data-user-id="<?php echo (int)$user['id']; ?>"
                                                    data-username="<?php echo htmlspecialchars($user['username'], ENT_QUOTES); ?>"
                                                    data-email="<?php echo htmlspecialchars($user['email'] ?? '', ENT_QUOTES); ?>"
                                                    data-first-name="<?php echo htmlspecialchars($user['first_name'], ENT_QUOTES); ?>"
                                                    data-last-name="<?php echo htmlspecialchars($user['last_name'], ENT_QUOTES); ?>"
                                                    data-is-active="<?php echo (int)$user['is_active']; ?>"
                                                    data-is-admin="<?php echo (int)$user['is_admin']; ?>"
                                                    data-can-reservations="<?php echo (int)$user['can_reservations']; ?>"
                                                    data-can-atemschutz="<?php echo (int)$user['can_atemschutz']; ?>"
                                                    data-can-members="<?php echo (int)($user['can_members'] ?? 0); ?>"
                                                    data-can-ric="<?php echo (int)($user['can_ric'] ?? 0); ?>"
                                                    data-can-courses="<?php echo (int)($user['can_courses'] ?? 0); ?>"
                                                    data-can-forms="<?php echo (int)($user['can_forms'] ?? 0); ?>"
                                                    data-can-vehicles="<?php echo (int)($user['can_vehicles'] ?? 0); ?>">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <?php if (!empty($user['email'])): ?>
                                                    <a href="?reset_password=<?php echo $user['id']; ?>" class="btn btn-outline-warning btn-sm" 
                                                       onclick="return confirm('Möchten Sie das Passwort für <?php echo htmlspecialchars($user['username']); ?> zurücksetzen? Eine E-Mail mit dem neuen Passwort wird gesendet.')"
                                                       title="Passwort zurücksetzen">
                                                        <i class="fas fa-key"></i>
                                                    </a>
                                                <?php endif; ?>
                                                <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                                    <a href="?delete=<?php echo $user['id']; ?>" class="btn btn-outline-danger btn-sm" 
                                                       onclick="return confirm('Sind Sie sicher, dass Sie diesen Benutzer löschen möchten?')">
                                                        <i class="fas fa-trash"></i>
                                                    </a>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Benutzername</th>
                                        <th>Name</th>
                                        <th>Status</th>
                                        <th>Erstellt</th>
                                        <th>Aktionen</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($system_users as $user): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($user['username']); ?></strong></td>
                                            <td><?php echo htmlspecialchars(trim($user['first_name'] . ' ' . $user['last_name']) ?: $user['username']); ?></td>
                                            <td>
                                                <?php if ($user['is_active']): ?>
                                                    <span class="badge bg-success">Aktiv</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">Inaktiv</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo format_date($user['created_at']); ?></td>
                                            <td>
                                                <button type="button" class="btn btn-outline-info btn-sm btn-show-autologin" data-user-id="<?php echo (int)$user['id']; ?>" data-username="<?php echo htmlspecialchars($user['username'], ENT_QUOTES); ?>" title="Autologin-Link anzeigen">
                                                    <i class="fas fa-link"></i> Link anzeigen
                                                </button>
                                                <button type="button" class="btn btn-outline-warning btn-sm btn-regenerate-link" data-user-id="<?php echo (int)$user['id']; ?>" title="Neuen Link generieren">
                                                    <i class="fas fa-sync-alt"></i>
                                                </button>
                                                <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                                    <a href="?delete=<?php echo $user['id']; ?>&tab=system" class="btn btn-outline-danger btn-sm" 
                                                       onclick="return confirm('Sind Sie sicher, dass Sie diesen Systembenutzer löschen möchten?')">
                                                        <i class="fas fa-trash"></i>
                                                    </a>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if (empty($system_users)): ?>
                        <p class="text-muted mb-0">Noch keine Systembenutzer. Klicken Sie auf „Systembenutzer anlegen“.</p>
                        <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Benutzer Modal -->
    <div class="modal fade" id="userModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" id="userForm">
                    <div class="modal-header">
                        <h5 class="modal-title" id="userModalTitle">Neuer Benutzer</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="username" class="form-label">Benutzername *</label>
                                <input type="text" class="form-control" id="username" name="username" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="email" class="form-label">E-Mail *</label>
                                <input type="email" class="form-control" id="email" name="email" required>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="first_name" class="form-label">Vorname *</label>
                                <input type="text" class="form-control" id="first_name" name="first_name" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="last_name" class="form-label">Nachname *</label>
                                <input type="text" class="form-control" id="last_name" name="last_name" required>
                            </div>
                        </div>
                        
                        <!-- Rollen-Auswahl entfernt: Berechtigungen werden granular unten gesetzt -->
                        
                        <div class="mb-3">
                            <label class="form-label">Berechtigungen</label>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="is_admin" name="is_admin" onchange="toggleAdminPermissions(this.checked)">
                                        <label class="form-check-label" for="is_admin">
                                            <strong>Administrator</strong>
                                        </label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="can_reservations" name="can_reservations">
                                        <label class="form-check-label" for="can_reservations">
                                            Fahrzeugreservierungen
                                        </label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="can_atemschutz" name="can_atemschutz">
                                        <label class="form-check-label" for="can_atemschutz">
                                            Atemschutz
                                        </label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="can_members" name="can_members">
                                        <label class="form-check-label" for="can_members">
                                            Mitgliederverwaltung
                                        </label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="can_ric" name="can_ric">
                                        <label class="form-check-label" for="can_ric">
                                            RIC Verwaltung
                                        </label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="can_courses" name="can_courses">
                                        <label class="form-check-label" for="can_courses">
                                            Lehrgangsverwaltung
                                        </label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="can_forms" name="can_forms">
                                        <label class="form-check-label" for="can_forms">
                                            Formularcenter
                                        </label>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <!-- Benutzerverwaltung/Einstellungen werden automatisch durch Administrator gesetzt -->
                                    <!-- Fahrzeugverwaltung wird automatisch über Administrator gesetzt -->
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="password" class="form-label">Passwort <span id="password-required">*</span></label>
                            <input type="password" class="form-control" id="password" name="password">
                            <div class="form-text" id="password-help" style="display: none;">
                                Leer lassen, um das aktuelle Passwort beizubehalten.
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="is_active" name="is_active" checked>
                                    <label class="form-check-label" for="is_active">
                                        Aktiv
                                    </label>
                                </div>
                            </div>
                        </div>
                        
                        <input type="hidden" name="user_id" id="user_id">
                        <input type="hidden" name="action" id="action" value="add">
                        <?php if ($einheit_id > 0): ?><input type="hidden" name="einheit_id" value="<?php echo (int)$einheit_id; ?>"><?php endif; ?>
                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                        <button type="submit" class="btn btn-primary" id="submitButton">Hinzufügen</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Systembenutzer Modal -->
    <div class="modal fade" id="systemUserModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="add_system_user">
                    <?php if ($einheit_id > 0): ?><input type="hidden" name="einheit_id" value="<?php echo (int)$einheit_id; ?>"><?php endif; ?>
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="fas fa-robot"></i> Systembenutzer anlegen</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-muted small">Systembenutzer haben keinen Mitglieder-Eintrag, keine E-Mail und kein Passwort. Sie erhalten einen Autologin-Link und können nur die zugewiesenen Bereiche nutzen.</p>
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
                                <input class="form-check-input" type="checkbox" name="can_forms" id="sys_can_forms" checked>
                                <label class="form-check-label" for="sys_can_forms">Formulare ausfüllen</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="can_reservations" id="sys_can_reservations">
                                <label class="form-check-label" for="sys_can_reservations">Fahrzeugreservierungen</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="can_atemschutz" id="sys_can_atemschutz">
                                <label class="form-check-label" for="sys_can_atemschutz">Atemschutz</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="can_members" id="sys_can_members">
                                <label class="form-check-label" for="sys_can_members">Mitgliederverwaltung</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="can_ric" id="sys_can_ric">
                                <label class="form-check-label" for="sys_can_ric">RIC Verwaltung</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="can_courses" id="sys_can_courses">
                                <label class="form-check-label" for="sys_can_courses">Lehrgangsverwaltung</label>
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
                    <p class="text-muted small mt-2 mb-0" id="autologin-validity-hint">Bei „Neuer Link“ wird ein neuer Link erzeugt und der alte funktioniert nicht mehr.</p>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Einfache Funktionen ohne Bootstrap-Event-Listener
        function openSystemUserModal() {
            const modal = document.getElementById('systemUserModal');
            if (modal) {
                new bootstrap.Modal(modal).show();
            }
        }
        function openUserModal() {
            // Modal anzeigen
            const modal = document.getElementById('userModal');
            if (modal) {
                // Neuer Benutzer vorbereiten
                document.getElementById('userModalTitle').textContent = 'Neuer Benutzer';
                document.getElementById('user_id').value = '';
                document.getElementById('username').value = '';
                document.getElementById('email').value = '';
                document.getElementById('first_name').value = '';
                document.getElementById('last_name').value = '';
                // Rolle entfällt, standardmäßig 'user'
                document.getElementById('is_active').checked = true;
                document.getElementById('action').value = 'add';
                document.getElementById('submitButton').textContent = 'Hinzufügen';
                document.getElementById('password-required').textContent = '*';
                document.getElementById('password-help').style.display = 'none';
                
                // Modal zuverlässig über Bootstrap-API öffnen
                if (window.bootstrap && window.bootstrap.Modal) {
                    const bsModal = new bootstrap.Modal(modal, { backdrop: 'static' });
                    bsModal.show();
                } else {
                    // Fallback, falls Bootstrap-API nicht verfügbar ist
                    modal.style.display = 'block';
                    modal.classList.add('show');
                    document.body.classList.add('modal-open');
                }
            }
        }
        
        function closeUserModal() {
            const modal = document.getElementById('userModal');
            if (modal) {
                modal.style.display = 'none';
                modal.classList.remove('show');
                document.body.classList.remove('modal-open');
            }
        }
        
        function editUser(userId, username, email, firstName, lastName, userRole, emailNotifications, isActive, isAdmin, canReservations, canAtemschutz, canMembers, canRic, canCourses, canForms, canUsers, canSettings, canVehicles) {
            // Modal anzeigen
            const modal = document.getElementById('userModal');
            if (modal) {
                // Bearbeitung vorbereiten
                document.getElementById('userModalTitle').textContent = 'Benutzer bearbeiten';
                document.getElementById('user_id').value = userId;
                document.getElementById('username').value = username;
                document.getElementById('email').value = email;
                document.getElementById('first_name').value = firstName;
                document.getElementById('last_name').value = lastName;
                // Rolle entfällt
                document.getElementById('is_active').checked = isActive == 1;
                
                // Berechtigungen setzen
                document.getElementById('is_admin').checked = isAdmin == 1;
                document.getElementById('can_reservations').checked = canReservations == 1;
                if (document.getElementById('can_atemschutz')) {
                    document.getElementById('can_atemschutz').checked = canAtemschutz == 1;
                }
                if (document.getElementById('can_members')) {
                    document.getElementById('can_members').checked = canMembers == 1;
                }
                if (document.getElementById('can_ric')) {
                    document.getElementById('can_ric').checked = canRic == 1;
                }
                if (document.getElementById('can_courses')) {
                    document.getElementById('can_courses').checked = canCourses == 1;
                }
                if (document.getElementById('can_forms')) {
                    document.getElementById('can_forms').checked = canForms == 1;
                }
                // Benutzerverwaltung/Einstellungen werden von Admin-Checkbox bestimmt
                document.getElementById('can_vehicles').checked = canVehicles == 1;
                toggleAdminPermissions(isAdmin == 1);
                
                document.getElementById('action').value = 'edit';
                document.getElementById('submitButton').textContent = 'Aktualisieren';
                document.getElementById('password-required').textContent = '';
                document.getElementById('password-help').style.display = 'block';
                
                // Modal anzeigen
                modal.style.display = 'block';
                modal.classList.add('show');
                document.body.classList.add('modal-open');
            }
        }
        
        // Event Listener für Modal-Schließung & Edit-Buttons hinzufügen
        document.addEventListener('DOMContentLoaded', function() {
            // Click-Delegation: Falls einzelne Buttons vom Overlay überdeckt würden
            document.querySelectorAll('button[id^="editBtn"]').forEach(function(btn){
                btn.addEventListener('click', function(e){
                    // Daten aus data-Attributen ins Formular füllen
                    const getBool = (v) => (String(v) === '1');
                    document.getElementById('userModalTitle').textContent = 'Benutzer bearbeiten';
                    document.getElementById('user_id').value = this.dataset.userId || '';
                    document.getElementById('username').value = this.dataset.username || '';
                    document.getElementById('email').value = this.dataset.email || '';
                    document.getElementById('first_name').value = this.dataset.firstName || '';
                    document.getElementById('last_name').value = this.dataset.lastName || '';
                    document.getElementById('is_active').checked = getBool(this.dataset.isActive);
                    document.getElementById('is_admin').checked = getBool(this.dataset.isAdmin);
                    const canRes = getBool(this.dataset.canReservations);
                    const canAtm = getBool(this.dataset.canAtemschutz);
                    const canMem = getBool(this.dataset.canMembers);
                    const canRic = getBool(this.dataset.canRic);
                    const canCourses = getBool(this.dataset.canCourses);
                    const canForms = getBool(this.dataset.canForms);
                    document.getElementById('can_reservations').checked = canRes;
                    const atmEl = document.getElementById('can_atemschutz');
                    if (atmEl) atmEl.checked = canAtm;
                    const memEl = document.getElementById('can_members');
                    if (memEl) memEl.checked = canMem;
                    const ricEl = document.getElementById('can_ric');
                    if (ricEl) ricEl.checked = canRic;
                    const coursesEl = document.getElementById('can_courses');
                    if (coursesEl) coursesEl.checked = canCourses;
                    const formsEl = document.getElementById('can_forms');
                    if (formsEl) formsEl.checked = canForms;
                    const canVeh = getBool(this.dataset.canVehicles);
                    const vehiclesEl = document.getElementById('can_vehicles');
                    if (vehiclesEl) vehiclesEl.checked = canVeh;
                    toggleAdminPermissions(getBool(this.dataset.isAdmin));
                    document.getElementById('action').value = 'edit';
                    document.getElementById('submitButton').textContent = 'Aktualisieren';
                    document.getElementById('password-required').textContent = '';
                    const help = document.getElementById('password-help'); if (help) help.style.display = 'block';
                });
            });
            // Admin-Checkbox initial toggeln
            const adminCheckbox = document.getElementById('is_admin');
            if (adminCheckbox) {
                toggleAdminPermissions(adminCheckbox.checked);
                adminCheckbox.addEventListener('change', function(){
                    toggleAdminPermissions(this.checked);
                });
            }
                // Abbrechen Button
            const cancelButton = document.querySelector('#userModal .btn-secondary');
            if (cancelButton) {
                cancelButton.addEventListener('click', function() {
                    closeUserModal();
                });
            }
            
            // X Button (Schließen)
            const closeButton = document.querySelector('#userModal .btn-close');
            if (closeButton) {
                closeButton.addEventListener('click', function() {
                    closeUserModal();
                });
            }
            
            // Modal-Hintergrund klicken zum Schließen
            const modal = document.getElementById('userModal');
            if (modal) {
                modal.addEventListener('click', function(e) {
                    if (e.target === modal) {
                        closeUserModal();
                    }
                });
            }

            // Neuer Link (Regenerieren) – Modal öffnen
            document.querySelectorAll('.btn-regenerate-link').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    const userId = this.dataset.userId;
                    const hid = document.getElementById('regenerate_user_id');
                    if (hid && userId) {
                        hid.value = userId;
                        new bootstrap.Modal(document.getElementById('regenerateLinkModal')).show();
                    }
                });
            });

            // Link anzeigen (Systembenutzer)
            document.querySelectorAll('.btn-show-autologin').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    const userId = this.dataset.userId;
                    const username = this.dataset.username || '';
                    const urlEl = document.getElementById('autologin-url');
                    const usernameEl = document.getElementById('autologin-username');
                    const copyBtn = document.getElementById('autologin-copy-btn');
                    if (!urlEl || !userId) return;
                    usernameEl.textContent = 'Autologin-Link für: ' + (username ? username : 'Systembenutzer');
                    urlEl.value = '';
                    copyBtn.disabled = true;
                    copyBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Laden…';
                    fetch('get-autologin-url.php?user_id=' + encodeURIComponent(userId))
                        .then(function(r) { return r.json(); })
                        .then(function(data) {
                            if (data.success) {
                                urlEl.value = data.url;
                                copyBtn.disabled = false;
                                copyBtn.innerHTML = '<i class="fas fa-copy"></i> Kopieren';
                                const hintEl = document.getElementById('autologin-validity-hint');
                                if (hintEl && data.validity_hint) hintEl.textContent = data.validity_hint + ' Bei „Neuer Link“ wird ein neuer Link erzeugt und der alte funktioniert nicht mehr.';
                                new bootstrap.Modal(document.getElementById('autologinLinkModal')).show();
                            } else {
                                alert(data.error || 'Fehler beim Laden des Links.');
                                copyBtn.disabled = false;
                                copyBtn.innerHTML = '<i class="fas fa-copy"></i> Kopieren';
                            }
                        })
                        .catch(function() {
                            alert('Fehler beim Laden des Links.');
                            copyBtn.disabled = false;
                            copyBtn.innerHTML = '<i class="fas fa-copy"></i> Kopieren';
                        });
                });
            });

            // Kopieren-Button
            const autologinCopyBtn = document.getElementById('autologin-copy-btn');
            if (autologinCopyBtn) {
                autologinCopyBtn.addEventListener('click', function() {
                    const urlEl = document.getElementById('autologin-url');
                    if (urlEl && urlEl.value) {
                        navigator.clipboard.writeText(urlEl.value).then(function() {
                            const orig = autologinCopyBtn.innerHTML;
                            autologinCopyBtn.innerHTML = '<i class="fas fa-check"></i> Kopiert!';
                            setTimeout(function() { autologinCopyBtn.innerHTML = orig; }, 1500);
                        });
                    }
                });
            }
        });
    </script>
        <script>
        function toggleAdminPermissions(isAdmin) {
            const permIds = ['can_reservations', 'can_atemschutz', 'can_users', 'can_settings', 'can_vehicles'];
            permIds.forEach(id => {
                const el = document.getElementById(id);
                if (!el) return;
                if (isAdmin) {
                    el.checked = true;
                    el.disabled = true;
                } else {
                    el.disabled = false;
                }
            });
        }
        </script>
        <script>
        // Client-Validierung: Passwort beim Anlegen erforderlich, Modal bleibt offen
        document.addEventListener('DOMContentLoaded', function(){
            const form = document.getElementById('userForm');
            if (!form) return;
            form.addEventListener('submit', function(e){
                const action = document.getElementById('action').value;
                const pwd = document.getElementById('password').value;
                if (action === 'add' && (!pwd || pwd.trim() === '')) {
                    e.preventDefault();
                    const help = document.getElementById('password-help');
                    const req = document.getElementById('password-required');
                    if (help) help.style.display = 'block';
                    if (req) req.textContent = '* (erforderlich)';
                    alert('Bitte ein Passwort setzen.');
                    return false;
                }
            });
        });
        </script>
</body>
</html>
