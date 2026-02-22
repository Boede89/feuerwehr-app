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

if (isset($_GET['success']) && $_GET['success'] === 'updated') {
    $message = "Benutzer wurde erfolgreich aktualisiert.";
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

        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h3 mb-0">
                <i class="fas fa-users-cog text-success me-2"></i>Benutzerverwaltung – <?php echo htmlspecialchars($einheit['name']); ?>
            </h1>
            <a href="settings-einheit.php?id=<?php echo $einheit_id; ?>" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left"></i> Zurück zur Einheit
            </a>
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
    </script>
</body>
</html>
