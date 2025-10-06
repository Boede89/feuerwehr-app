<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Prüfe ob Benutzer eingeloggt ist
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header("Location: ../login.php");
    exit;
}

// Prüfe ob Benutzer Benutzerverwaltung-Rechte hat
// Fallback auf alte Admin-Prüfung falls neue Permissions nicht verfügbar
if (!has_permission('users') && !has_admin_access()) {
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
        $user_role = sanitize_input($_POST['user_role'] ?? 'user');
        $email_notifications = isset($_POST['email_notifications']) ? 1 : 0;
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $password = $_POST['password'] ?? '';
        
        // Granular permissions
        $is_admin = isset($_POST['is_admin']) ? 1 : 0;
        $can_reservations = isset($_POST['can_reservations']) ? 1 : 0;
        $can_atemschutz = isset($_POST['can_atemschutz']) ? 1 : 0;
        $can_users = isset($_POST['can_users']) ? 1 : 0;
        $can_settings = isset($_POST['can_settings']) ? 1 : 0;
        $can_vehicles = isset($_POST['can_vehicles']) ? 1 : 0;
        
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
                        $stmt = $db->prepare("INSERT INTO users (username, email, password_hash, first_name, last_name, user_role, email_notifications, is_active, is_admin, can_reservations, can_atemschutz, can_users, can_settings, can_vehicles) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$username, $email, $password_hash, $first_name, $last_name, $user_role, $email_notifications, $is_active, $is_admin, $can_reservations, $can_atemschutz, $can_users, $can_settings, $can_vehicles]);
                        $message = "Benutzer wurde erfolgreich hinzugefügt.";
                        log_activity($_SESSION['user_id'], 'user_added', "Benutzer '$username' hinzugefügt");
                        
                        // Weiterleitung um POST-Problem zu vermeiden
                        header("Location: users.php?success=added");
                        exit();
                    }
                } elseif ($action == 'edit') {
                    if (!empty($password)) {
                        $password_hash = hash_password($password);
                        $stmt = $db->prepare("UPDATE users SET username = ?, email = ?, password_hash = ?, first_name = ?, last_name = ?, user_role = ?, email_notifications = ?, is_active = ?, is_admin = ?, can_reservations = ?, can_atemschutz = ?, can_users = ?, can_settings = ?, can_vehicles = ? WHERE id = ?");
                        $stmt->execute([$username, $email, $password_hash, $first_name, $last_name, $user_role, $email_notifications, $is_active, $is_admin, $can_reservations, $can_atemschutz, $can_users, $can_settings, $can_vehicles, $user_id]);
                    } else {
                        $stmt = $db->prepare("UPDATE users SET username = ?, email = ?, first_name = ?, last_name = ?, user_role = ?, email_notifications = ?, is_active = ?, is_admin = ?, can_reservations = ?, can_atemschutz = ?, can_users = ?, can_settings = ?, can_vehicles = ? WHERE id = ?");
                        $stmt->execute([$username, $email, $first_name, $last_name, $user_role, $email_notifications, $is_active, $is_admin, $can_reservations, $can_atemschutz, $can_users, $can_settings, $can_vehicles, $user_id]);
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
    $stmt = $db->prepare("SELECT id, username, email, first_name, last_name, user_role, email_notifications, is_active, created_at, is_admin, can_reservations, can_atemschutz, can_users, can_settings, can_vehicles FROM users ORDER BY created_at DESC");
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
                    <li class="nav-item">
                        <a class="nav-link" href="dashboard.php">
                            <i class="fas fa-tachometer-alt"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="reservations.php">
                            <i class="fas fa-calendar-check"></i> Reservierungen
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="vehicles.php">
                            <i class="fas fa-truck"></i> Fahrzeuge
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="users.php">
                            <i class="fas fa-users"></i> Benutzer
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="settings.php">
                            <i class="fas fa-cog"></i> Einstellungen
                        </a>
                    </li>
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
                                        <th>Rolle</th>
                                        <th>Berechtigungen</th>
                                        <th>E-Mail-Benachrichtigungen</th>
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
                                            <td>
                                                <?php
                                                $role_labels = [
                                                    'admin' => ['bg-danger', 'Administrator'],
                                                    'approver' => ['bg-warning', 'Genehmiger'],
                                                    'user' => ['bg-secondary', 'Benutzer']
                                                ];
                                                $role_info = $role_labels[$user['user_role']] ?? ['bg-secondary', 'Unbekannt'];
                                                ?>
                                                <span class="badge <?php echo $role_info[0]; ?>"><?php echo $role_info[1]; ?></span>
                                            </td>
                                            <td>
                                                <div class="d-flex flex-wrap gap-1">
                                                    <?php if ($user['is_admin']): ?>
                                                        <span class="badge bg-danger">Admin</span>
                                                    <?php endif; ?>
                                                    <?php if ($user['can_reservations']): ?>
                                                        <span class="badge bg-primary">Reservierungen</span>
                                                    <?php endif; ?>
                                                    <?php if ($user['can_atemschutz']): ?>
                                                        <span class="badge bg-info">Atemschutz</span>
                                                    <?php endif; ?>
                                                    <?php if ($user['can_users']): ?>
                                                        <span class="badge bg-warning">Benutzer</span>
                                                    <?php endif; ?>
                                                    <?php if ($user['can_settings']): ?>
                                                        <span class="badge bg-info">Einstellungen</span>
                                                    <?php endif; ?>
                                                    <?php if ($user['can_vehicles']): ?>
                                                        <span class="badge bg-success">Fahrzeuge</span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <?php if ($user['email_notifications']): ?>
                                                    <span class="badge bg-success">Aktiviert</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">Deaktiviert</span>
                                                <?php endif; ?>
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
                                                <button type="button" class="btn btn-outline-primary btn-sm" 
                                                        onclick="editUser(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['username']); ?>', '<?php echo htmlspecialchars($user['email']); ?>', '<?php echo htmlspecialchars($user['first_name']); ?>', '<?php echo htmlspecialchars($user['last_name']); ?>', '<?php echo $user['user_role']; ?>', <?php echo $user['email_notifications']; ?>, <?php echo $user['is_active']; ?>, <?php echo $user['is_admin']; ?>, <?php echo $user['can_reservations']; ?>, <?php echo $user['can_atemschutz']; ?>, <?php echo $user['can_users']; ?>, <?php echo $user['can_settings']; ?>, <?php echo $user['can_vehicles']; ?>)">
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
                        
                        <div class="mb-3">
                            <label for="user_role" class="form-label">Rolle *</label>
                            <select class="form-select" id="user_role" name="user_role" required>
                                <option value="user">Benutzer</option>
                                <option value="approver">Genehmiger</option>
                                <option value="admin">Administrator</option>
                            </select>
                            <div class="form-text">
                                <strong>Benutzer:</strong> Kann nur Reservierungen einreichen<br>
                                <strong>Genehmiger:</strong> Kann Reservierungen genehmigen/ablehnen<br>
                                <strong>Administrator:</strong> Vollzugriff auf alle Funktionen
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Berechtigungen</label>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="is_admin" name="is_admin">
                                        <label class="form-check-label" for="is_admin">
                                            <strong>Administrator</strong> - Vollzugriff auf alles
                                        </label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="can_reservations" name="can_reservations">
                                        <label class="form-check-label" for="can_reservations">
                                            Fahrzeugreservierungen - Dashboard & Reservierungen
                                        </label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="can_atemschutz" name="can_atemschutz">
                                        <label class="form-check-label" for="can_atemschutz">
                                            <strong>Atemschutztauglichkeits-Überwachung</strong>
                                        </label>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="can_users" name="can_users">
                                        <label class="form-check-label" for="can_users">
                                            Benutzerverwaltung
                                        </label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="can_settings" name="can_settings">
                                        <label class="form-check-label" for="can_settings">
                                            Einstellungen
                                        </label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="can_vehicles" name="can_vehicles">
                                        <label class="form-check-label" for="can_vehicles">
                                            Fahrzeugverwaltung
                                        </label>
                                    </div>
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
                                    <input class="form-check-input" type="checkbox" id="email_notifications" name="email_notifications" checked>
                                    <label class="form-check-label" for="email_notifications">
                                        E-Mail-Benachrichtigungen
                                    </label>
                                </div>
                            </div>
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
                document.getElementById('user_role').value = 'user';
                document.getElementById('email_notifications').checked = true;
                document.getElementById('is_active').checked = true;
                document.getElementById('action').value = 'add';
                document.getElementById('submitButton').textContent = 'Hinzufügen';
                document.getElementById('password-required').textContent = '*';
                document.getElementById('password-help').style.display = 'none';
                
                // Modal anzeigen
                modal.style.display = 'block';
                modal.classList.add('show');
                document.body.classList.add('modal-open');
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
                document.getElementById('user_role').value = userRole;
                document.getElementById('email_notifications').checked = emailNotifications == 1;
                document.getElementById('is_active').checked = isActive == 1;
                
                // Berechtigungen setzen
                document.getElementById('is_admin').checked = isAdmin == 1;
                document.getElementById('can_reservations').checked = canReservations == 1;
                if (document.getElementById('can_atemschutz')) {
                    document.getElementById('can_atemschutz').checked = canAtemschutz == 1;
                }
                document.getElementById('can_users').checked = canUsers == 1;
                document.getElementById('can_settings').checked = canSettings == 1;
                document.getElementById('can_vehicles').checked = canVehicles == 1;
                
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
        
        // Event Listener für Modal-Schließung hinzufügen
        document.addEventListener('DOMContentLoaded', function() {
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
</body>
</html>
