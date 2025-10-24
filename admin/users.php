<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Prüfe ob Benutzer eingeloggt ist
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header("Location: ../login.php");
    exit;
}

// Prüfe ob Benutzer Admin-Berechtigung hat
if (!hasAdminPermission()) {
    header("Location: ../login.php?error=access_denied");
    exit;
}

$message = '';
$error = '';

// Erfolgsmeldungen von GET-Parameter
if (isset($_GET['success'])) {
    if ($_GET['success'] == 'added') {
        $message = "Benutzer wurde erfolgreich hinzugefügt.";
    } elseif ($_GET['success'] == 'updated') {
        $message = "Benutzer wurde erfolgreich aktualisiert.";
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
        // Benutzerverwaltung/Einstellungen werden durch Administrator gesetzt
        $can_users = $is_admin ? 1 : 0;
        $can_settings = $is_admin ? 1 : 0;
        // Fahrzeugverwaltung: nur Administratoren erhalten Zugriff (kein eigener Schalter)
        $can_vehicles = $is_admin ? 1 : 0;
        
        if (empty($username) || empty($email) || empty($first_name) || empty($last_name)) {
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
                        $stmt = $db->prepare("INSERT INTO users (username, email, password_hash, first_name, last_name, user_role, is_active, is_admin, can_reservations, can_atemschutz, can_users, can_settings, can_vehicles) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$username, $email, $password_hash, $first_name, $last_name, 'user', $is_active, $is_admin, $can_reservations, $can_atemschutz, $can_users, $can_settings, $can_vehicles]);
                        $message = "Benutzer wurde erfolgreich hinzugefügt.";
                        log_activity($_SESSION['user_id'], 'user_added', "Benutzer '$username' hinzugefügt");
                        
                        // Weiterleitung um POST-Problem zu vermeiden
                        header("Location: users.php?success=added");
                        exit();
                    }
                } elseif ($action == 'edit') {
                    if (!empty($password)) {
                        $password_hash = hash_password($password);
                        $stmt = $db->prepare("UPDATE users SET username = ?, email = ?, password_hash = ?, first_name = ?, last_name = ?, user_role = ?, is_active = ?, is_admin = ?, can_reservations = ?, can_atemschutz = ?, can_users = ?, can_settings = ?, can_vehicles = ? WHERE id = ?");
                        $stmt->execute([$username, $email, $password_hash, $first_name, $last_name, 'user', $is_active, $is_admin, $can_reservations, $can_atemschutz, $can_users, $can_settings, $can_vehicles, $user_id]);
                    } else {
                        $stmt = $db->prepare("UPDATE users SET username = ?, email = ?, first_name = ?, last_name = ?, user_role = ?, is_active = ?, is_admin = ?, can_reservations = ?, can_atemschutz = ?, can_users = ?, can_settings = ?, can_vehicles = ? WHERE id = ?");
                        $stmt->execute([$username, $email, $first_name, $last_name, 'user', $is_active, $is_admin, $can_reservations, $can_atemschutz, $can_users, $can_settings, $can_vehicles, $user_id]);
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
    $stmt = $db->prepare("SELECT id, username, email, first_name, last_name, user_role, is_active, created_at, is_admin, can_reservations, can_atemschutz, can_users, can_settings, can_vehicles FROM users ORDER BY created_at DESC");
    $stmt->execute();
    $users = $stmt->fetchAll();
} catch(PDOException $e) {
    $error = "Fehler beim Laden der Benutzer: " . $e->getMessage();
    $users = [];
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
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container-fluid">
            <a class="navbar-brand" href="../index.php">
                <i class="fas fa-fire"></i> Feuerwehr App
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <?php echo get_admin_navigation(); ?>
                </ul>
                <ul class="navbar-nav">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="navbarDropdown" role="button" data-bs-toggle="dropdown">
                            <i class="fas fa-user"></i> <?php echo htmlspecialchars($_SESSION['first_name'] . ' ' . $_SESSION['last_name']); ?>
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user-edit"></i> Profil</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="../logout.php"><i class="fas fa-sign-out-alt"></i> Abmelden</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container-fluid mt-4">
        <div class="row">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h1 class="h3 mb-0">
                        <i class="fas fa-users"></i> Benutzerverwaltung
                    </h1>
                    <button type="button" class="btn btn-primary" onclick="openUserModal()">
                        <i class="fas fa-plus"></i> Neuer Benutzer
                    </button>
                </div>
                
                <?php if ($message): ?>
                    <?php echo show_success($message); ?>
                <?php endif; ?>
                
                <?php if ($error): ?>
                    <?php echo show_error($error); ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Benutzer Tabelle -->
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Benutzername</th>
                                        <th>E-Mail</th>
                                        <th>Name</th>
                                        <!-- Rolle entfernt -->
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
                                            <td><?php echo htmlspecialchars($user['email']); ?></td>
                                            <td><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></td>
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
                                                    data-email="<?php echo htmlspecialchars($user['email'], ENT_QUOTES); ?>"
                                                    data-first-name="<?php echo htmlspecialchars($user['first_name'], ENT_QUOTES); ?>"
                                                    data-last-name="<?php echo htmlspecialchars($user['last_name'], ENT_QUOTES); ?>"
                                                    data-is-active="<?php echo (int)$user['is_active']; ?>"
                                                    data-is-admin="<?php echo (int)$user['is_admin']; ?>"
                                                    data-can-reservations="<?php echo (int)$user['can_reservations']; ?>"
                                                    data-can-atemschutz="<?php echo (int)$user['can_atemschutz']; ?>">
                                                    <i class="fas fa-edit"></i>
                                                </button>
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Einfache Funktionen ohne Bootstrap-Event-Listener
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
        
        function editUser(userId, username, email, firstName, lastName, userRole, emailNotifications, isActive, isAdmin, canReservations, canAtemschutz, canUsers, canSettings, canVehicles) {
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
                    document.getElementById('can_reservations').checked = canRes;
                    const atmEl = document.getElementById('can_atemschutz');
                    if (atmEl) atmEl.checked = canAtm;
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
