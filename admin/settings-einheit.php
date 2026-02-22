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

// Zugriff prüfen: Superadmin oder Einheitsadmin dieser Einheit
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

// Einheit bearbeiten
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_einheit') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Ungültiger Sicherheitstoken.";
    } else {
        $name = trim(sanitize_input($_POST['einheit_name'] ?? ''));
        if (empty($name)) {
            $error = "Name der Einheit ist erforderlich.";
        } else {
            try {
                $stmt = $db->prepare("UPDATE einheiten SET name = ?, kurzbeschreibung = ? WHERE id = ?");
                $stmt->execute([$name, trim(sanitize_input($_POST['einheit_kurzbeschreibung'] ?? '')), $einheit_id]);
                $einheit['name'] = $name;
                $message = "Einheit wurde aktualisiert.";
            } catch (Exception $e) {
                $error = "Fehler: " . $e->getMessage();
            }
        }
    }
}

// Benutzer zur Einheit hinzufügen
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
                    header("Location: settings-einheit.php?id=" . $einheit_id . "&success=user_added");
                    exit;
                }
            } catch (Exception $e) {
                $error = "Fehler: " . $e->getMessage();
            }
        }
    }
}

// Benutzer zur Einheit hinzufügen (bestehender User)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'link_user') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Ungültiger Sicherheitstoken.";
    } else {
        $user_id = (int)($_POST['user_id'] ?? 0);
        if ($user_id && is_superadmin()) {
            try {
                $stmt = $db->prepare("INSERT IGNORE INTO user_einheiten (user_id, einheit_id) VALUES (?, ?)");
                $stmt->execute([$user_id, $einheit_id]);
                header("Location: settings-einheit.php?id=" . $einheit_id . "&success=user_linked");
                exit;
            } catch (Exception $e) {
                $error = "Fehler: " . $e->getMessage();
            }
        }
    }
}

if (isset($_GET['success'])) {
    if ($_GET['success'] === 'user_added') $message = "Benutzer wurde erfolgreich angelegt.";
    if ($_GET['success'] === 'user_linked') $message = "Benutzer wurde der Einheit zugewiesen.";
}

// Benutzer dieser Einheit laden
$unit_users = [];
try {
    $stmt = $db->prepare("SELECT u.id, u.username, u.email, u.first_name, u.last_name, u.user_type, u.is_active, u.created_at 
        FROM users u 
        WHERE (u.einheit_id = ? OR u.id IN (SELECT user_id FROM user_einheiten WHERE einheit_id = ?))
        AND u.is_system_user = 0
        ORDER BY u.last_name, u.first_name");
    $stmt->execute([$einheit_id, $einheit_id]);
    $unit_users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Alle anderen Benutzer (für Verknüpfung durch Superadmin)
$other_users = [];
if (is_superadmin()) {
    try {
        $stmt = $db->prepare("SELECT u.id, u.username, u.first_name, u.last_name FROM users u 
            WHERE u.is_system_user = 0 
            AND u.einheit_id != ? 
            AND u.id NOT IN (SELECT user_id FROM user_einheiten WHERE einheit_id = ?)
            ORDER BY u.last_name, u.first_name");
        $stmt->execute([$einheit_id, $einheit_id]);
        $other_users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($einheit['name']); ?> - Einstellungen</title>
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
                <li class="breadcrumb-item active"><?php echo htmlspecialchars($einheit['name']); ?></li>
            </ol>
        </nav>

        <?php if ($message): ?>
            <?php echo show_success($message); ?>
        <?php endif; ?>
        <?php if ($error): ?>
            <?php echo show_error($error); ?>
        <?php endif; ?>

        <!-- Einheit bearbeiten -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-building text-primary me-2"></i>
                    <?php echo htmlspecialchars($einheit['name']); ?> – Einstellungen
                </h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="edit_einheit">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="einheit_name" class="form-label">Name</label>
                            <input type="text" class="form-control" id="einheit_name" name="einheit_name" value="<?php echo htmlspecialchars($einheit['name']); ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="einheit_kurzbeschreibung" class="form-label">Kurzbeschreibung (optional)</label>
                            <input type="text" class="form-control" id="einheit_kurzbeschreibung" name="einheit_kurzbeschreibung" value="<?php echo htmlspecialchars($einheit['kurzbeschreibung'] ?? ''); ?>">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Speichern</button>
                </form>
            </div>
        </div>

        <!-- Benutzer dieser Einheit -->
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-users text-success me-2"></i>Benutzer dieser Einheit</h5>
                <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addUserModal">
                    <i class="fas fa-plus"></i> Neuer Benutzer
                </button>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Benutzername</th>
                                <th>E-Mail</th>
                                <th>Rolle</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($unit_users as $u): ?>
                            <tr>
                                <td><?php echo htmlspecialchars(trim($u['first_name'] . ' ' . $u['last_name'])); ?></td>
                                <td><?php echo htmlspecialchars($u['username']); ?></td>
                                <td><?php echo htmlspecialchars($u['email'] ?? ''); ?></td>
                                <td>
                                    <?php if (($u['user_type'] ?? '') === 'superadmin'): ?>
                                        <span class="badge bg-danger">Superadmin</span>
                                    <?php elseif (($u['user_type'] ?? '') === 'einheitsadmin'): ?>
                                        <span class="badge bg-warning text-dark">Einheitsadmin</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Benutzer</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($u['is_active']): ?>
                                        <span class="badge bg-success">Aktiv</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Inaktiv</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (empty($unit_users)): ?>
                <p class="text-muted mb-0">Noch keine Benutzer in dieser Einheit. Legen Sie einen neuen Benutzer an.</p>
                <?php endif; ?>
            </div>
        </div>

        <?php if (is_superadmin() && !empty($other_users)): ?>
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-link me-2"></i>Bestehenden Benutzer zur Einheit hinzufügen</h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="link_user">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <div class="row align-items-end">
                        <div class="col-md-8">
                            <label for="user_id" class="form-label">Benutzer</label>
                            <select class="form-select" id="user_id" name="user_id" required>
                                <option value="">— Bitte wählen —</option>
                                <?php foreach ($other_users as $ou): ?>
                                    <option value="<?php echo (int)$ou['id']; ?>"><?php echo htmlspecialchars($ou['last_name'] . ', ' . $ou['first_name'] . ' (' . $ou['username'] . ')'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <button type="submit" class="btn btn-outline-primary"><i class="fas fa-plus"></i> Hinzufügen</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>
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
                                <label for="username" class="form-label">Benutzername *</label>
                                <input type="text" class="form-control" name="username" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="email" class="form-label">E-Mail *</label>
                                <input type="email" class="form-control" name="email" required>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="first_name" class="form-label">Vorname *</label>
                                <input type="text" class="form-control" name="first_name" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="last_name" class="form-label">Nachname *</label>
                                <input type="text" class="form-control" name="last_name" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label">Passwort *</label>
                            <input type="password" class="form-control" name="password" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Berechtigungen</label>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="can_reservations" id="cu_reservations">
                                <label class="form-check-label" for="cu_reservations">Fahrzeugreservierungen</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="can_atemschutz" id="cu_atemschutz">
                                <label class="form-check-label" for="cu_atemschutz">Atemschutz</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="can_members" id="cu_members">
                                <label class="form-check-label" for="cu_members">Mitgliederverwaltung</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="can_ric" id="cu_ric">
                                <label class="form-check-label" for="cu_ric">RIC Verwaltung</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="can_courses" id="cu_courses">
                                <label class="form-check-label" for="cu_courses">Lehrgangsverwaltung</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="can_forms" id="cu_forms" checked>
                                <label class="form-check-label" for="cu_forms">Formularcenter</label>
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
</body>
</html>
