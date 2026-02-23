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
                    $stmt = $db->prepare("INSERT INTO users (username, email, password_hash, first_name, last_name, user_role, user_type, einheit_id, is_active, can_reservations, can_atemschutz, can_members, can_ric, can_courses, can_forms, can_users, can_settings, can_vehicles, email_notifications) VALUES (?, ?, ?, ?, ?, 'user', 'user', ?, 1, ?, ?, ?, ?, ?, ?, 0, 0, 0, 0)");
                    $stmt->execute([$username, $email, $password_hash, $first_name, $last_name, $einheit_id, $can_reservations, $can_atemschutz, $can_members, $can_ric, $can_courses, $can_forms]);
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

// Benutzer bearbeiten (Berechtigungen)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_user') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Ungültiger Sicherheitstoken.";
    } else {
        $user_id = (int)($_POST['user_id'] ?? 0);
        if (!$user_id || !user_has_einheit_access($_SESSION['user_id'], $einheit_id)) {
            $error = "Ungültige Anfrage.";
        } else {
            $can_reservations = isset($_POST['can_reservations']) ? 1 : 0;
            $can_atemschutz = isset($_POST['can_atemschutz']) ? 1 : 0;
            $can_members = isset($_POST['can_members']) ? 1 : 0;
            $can_ric = isset($_POST['can_ric']) ? 1 : 0;
            $can_courses = isset($_POST['can_courses']) ? 1 : 0;
            $can_forms = isset($_POST['can_forms']) ? 1 : 0;
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            $password = $_POST['password'] ?? '';
            try {
                $stmt_check = $db->prepare("SELECT id FROM users WHERE (einheit_id = ? OR id IN (SELECT user_id FROM user_einheiten WHERE einheit_id = ?)) AND id = ?");
                $stmt_check->execute([$einheit_id, $einheit_id, $user_id]);
                if (!$stmt_check->fetch()) {
                    $error = "Benutzer gehört nicht zu dieser Einheit.";
                } else {
                    if (!empty($password)) {
                        $pw_hash = hash_password($password);
                        $stmt = $db->prepare("UPDATE users SET can_reservations=?, can_atemschutz=?, can_members=?, can_ric=?, can_courses=?, can_forms=?, is_active=?, password_hash=? WHERE id=?");
                        $stmt->execute([$can_reservations, $can_atemschutz, $can_members, $can_ric, $can_courses, $can_forms, $is_active, $pw_hash, $user_id]);
                    } else {
                        $stmt = $db->prepare("UPDATE users SET can_reservations=?, can_atemschutz=?, can_members=?, can_ric=?, can_courses=?, can_forms=?, is_active=? WHERE id=?");
                        $stmt->execute([$can_reservations, $can_atemschutz, $can_members, $can_ric, $can_courses, $can_forms, $is_active, $user_id]);
                    }
                    log_activity($_SESSION['user_id'], 'user_updated', "Berechtigungen für Benutzer ID $user_id (Einheit {$einheit['name']}) aktualisiert");
                    header("Location: settings-einheit-users.php?id=" . $einheit_id . "&success=updated");
                    exit;
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
                    $stmt = $db->prepare("INSERT INTO users (username, email, password_hash, first_name, last_name, user_role, is_active, is_admin, is_system_user, can_reservations, can_atemschutz, can_members, can_ric, can_courses, can_forms, can_users, can_settings, can_vehicles, email_notifications, autologin_token, autologin_expires, einheit_id) VALUES (?, NULL, NULL, ?, ?, 'user', 1, 0, 1, 0, 0, 0, 0, 0, 1, 0, 0, 0, 0, ?, ?, ?)");
                    $stmt->execute([$username, $first_name ?: $username, $last_name, $autologin_token, $autologin_expires, $einheit_id]);
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
}

// Benutzer dieser Einheit laden (mit Berechtigungen)
$unit_users = [];
try {
    $stmt = $db->prepare("SELECT u.id, u.username, u.email, u.first_name, u.last_name, u.user_type, u.is_active, u.created_at,
        u.can_reservations, u.can_atemschutz, u.can_members, u.can_ric, u.can_courses, u.can_forms
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
    $stmt = $db->prepare("SELECT id, username, first_name, last_name, is_active, autologin_token, autologin_expires, created_at FROM users WHERE einheit_id = ? AND is_system_user = 1 ORDER BY username");
    $stmt->execute([$einheit_id]);
    $system_users = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
                                        <?php if (!empty($u['can_atemschutz'])): ?><span class="badge bg-success">Atemschutz</span><?php endif; ?>
                                        <?php if (!empty($u['can_members'])): ?><span class="badge bg-info">Mitglieder</span><?php endif; ?>
                                        <?php if (!empty($u['can_ric'])): ?><span class="badge bg-warning text-dark">RIC</span><?php endif; ?>
                                        <?php if (!empty($u['can_courses'])): ?><span class="badge bg-purple">Lehrgänge</span><?php endif; ?>
                                        <?php if (!empty($u['can_forms'])): ?><span class="badge bg-secondary">Formulare</span><?php endif; ?>
                                        <?php if (($u['user_type'] ?? '') === 'superadmin'): ?><span class="badge bg-danger">Superadmin</span><?php endif; ?>
                                        <?php if (($u['user_type'] ?? '') === 'einheitsadmin'): ?><span class="badge bg-warning text-dark">Einheitsadmin</span><?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($u['is_active']): ?>
                                        <span class="badge bg-success">Aktiv</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Inaktiv</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (($u['user_type'] ?? '') !== 'superadmin'): ?>
                                    <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#editUserModal"
                                        data-user-id="<?php echo (int)$u['id']; ?>"
                                        data-username="<?php echo htmlspecialchars($u['username'], ENT_QUOTES); ?>"
                                        data-first-name="<?php echo htmlspecialchars($u['first_name'], ENT_QUOTES); ?>"
                                        data-last-name="<?php echo htmlspecialchars($u['last_name'], ENT_QUOTES); ?>"
                                        data-can-reservations="<?php echo (int)($u['can_reservations'] ?? 0); ?>"
                                        data-can-atemschutz="<?php echo (int)($u['can_atemschutz'] ?? 0); ?>"
                                        data-can-members="<?php echo (int)($u['can_members'] ?? 0); ?>"
                                        data-can-ric="<?php echo (int)($u['can_ric'] ?? 0); ?>"
                                        data-can-courses="<?php echo (int)($u['can_courses'] ?? 0); ?>"
                                        data-can-forms="<?php echo (int)($u['can_forms'] ?? 0); ?>"
                                        data-is-active="<?php echo (int)$u['is_active']; ?>">
                                        <i class="fas fa-edit"></i> Bearbeiten
                                    </button>
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
                <span class="badge bg-secondary">Nur Formulare ausfüllen</span>
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">Systembenutzer haben keinen Login. Sie erhalten einen Link, mit dem sie direkt Formulare ausfüllen können – z.B. für Tablets am Gerätehaus.</p>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Benutzername</th>
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
                                    <button type="button" class="btn btn-outline-primary btn-sm btn-show-autologin" data-user-id="<?php echo (int)$su['id']; ?>" title="Link anzeigen">
                                        <i class="fas fa-link"></i> Link anzeigen
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#regenerateLinkModal" data-user-id="<?php echo (int)$su['id']; ?>" title="Neuen Link generieren">
                                        <i class="fas fa-sync-alt"></i> Neuer Link
                                    </button>
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
                        <p class="text-muted small mb-3">Systembenutzer erhalten einen Autologin-Link und können nur Formulare ausfüllen – ideal für Tablets am Gerätehaus.</p>
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
                        <h5 class="modal-title"><i class="fas fa-user-edit"></i> Berechtigungen bearbeiten</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-muted small mb-3" id="edit_user_info"></p>
                        <div class="mb-3">
                            <label class="form-label">Berechtigungen</label>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="can_reservations" id="edit_can_reservations">
                                <label class="form-check-label" for="edit_can_reservations">Fahrzeugreservierungen</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="can_atemschutz" id="edit_can_atemschutz">
                                <label class="form-check-label" for="edit_can_atemschutz">Atemschutz</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="can_members" id="edit_can_members">
                                <label class="form-check-label" for="edit_can_members">Mitgliederverwaltung</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="can_ric" id="edit_can_ric">
                                <label class="form-check-label" for="edit_can_ric">RIC Verwaltung</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="can_courses" id="edit_can_courses">
                                <label class="form-check-label" for="edit_can_courses">Lehrgangsverwaltung</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="can_forms" id="edit_can_forms">
                                <label class="form-check-label" for="edit_can_forms">Formularcenter</label>
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
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="can_reservations" id="add_can_reservations">
                                <label class="form-check-label" for="add_can_reservations">Fahrzeugreservierungen</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="can_atemschutz" id="add_can_atemschutz">
                                <label class="form-check-label" for="add_can_atemschutz">Atemschutz</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="can_members" id="add_can_members">
                                <label class="form-check-label" for="add_can_members">Mitgliederverwaltung</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="can_ric" id="add_can_ric">
                                <label class="form-check-label" for="add_can_ric">RIC Verwaltung</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="can_courses" id="add_can_courses">
                                <label class="form-check-label" for="add_can_courses">Lehrgangsverwaltung</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="can_forms" id="add_can_forms" checked>
                                <label class="form-check-label" for="add_can_forms">Formularcenter</label>
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
            document.getElementById('edit_user_info').textContent = btn.dataset.username + ' (' + (btn.dataset.firstName || '') + ' ' + (btn.dataset.lastName || '') + ')';
            document.getElementById('edit_can_reservations').checked = btn.dataset.canReservations == '1';
            document.getElementById('edit_can_atemschutz').checked = btn.dataset.canAtemschutz == '1';
            document.getElementById('edit_can_members').checked = btn.dataset.canMembers == '1';
            document.getElementById('edit_can_ric').checked = btn.dataset.canRic == '1';
            document.getElementById('edit_can_courses').checked = btn.dataset.canCourses == '1';
            document.getElementById('edit_can_forms').checked = btn.dataset.canForms == '1';
            document.getElementById('edit_is_active').checked = btn.dataset.isActive == '1';
            document.getElementById('edit_password').value = '';
        });
        document.getElementById('regenerateLinkModal').addEventListener('show.bs.modal', function(e) {
            var btn = e.relatedTarget;
            if (btn && btn.dataset.userId) document.getElementById('regenerate_user_id').value = btn.dataset.userId;
        });
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
            if (urlEl && urlEl.value) {
                navigator.clipboard.writeText(urlEl.value).then(function() {
                    var orig = document.getElementById('autologin-copy-btn').innerHTML;
                    document.getElementById('autologin-copy-btn').innerHTML = '<i class="fas fa-check"></i> Kopiert!';
                    setTimeout(function() { document.getElementById('autologin-copy-btn').innerHTML = orig; }, 1500);
                });
            }
        });
    </script>
</body>
</html>
